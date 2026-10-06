<?php declare(strict_types=1);

namespace Debuss\ServerRequestFactory;

use InvalidArgumentException;
use Psr\Http\Message\{ServerRequestFactoryInterface,
    ServerRequestInterface,
    StreamFactoryInterface,
    StreamInterface,
    UploadedFileFactoryInterface,
    UploadedFileInterface,
    UriFactoryInterface,
    UriInterface};

final readonly class ServerRequestFactory
{

    /** Request attribute holding the client IP, the one given by trusted proxies or REMOTE_ADDR (null when unknown) */
    public const CLIENT_IP_ATTRIBUTE = 'client-ip';

    public function __construct(
        private ServerRequestFactoryInterface $serverRequestFactory,
        private UriFactoryInterface $uriFactory,
        private UploadedFileFactoryInterface $uploadedFileFactory,
        private StreamFactoryInterface $streamFactory,
        private TrustedProxies $trustedProxies = new TrustedProxies()
    ) {}

    public function fromGlobals(): ServerRequestInterface
    {
        $server = self::serverStrings($_SERVER);
        $contentType = strtolower(trim(explode(';', $server['CONTENT_TYPE'] ?? '')[0]));
        $isForm = in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true);

        return $this->fromArrays(
            $_SERVER,
            $_GET,
            ($server['REQUEST_METHOD'] ?? 'GET') === 'POST' && $isForm ? $_POST : null,
            $_COOKIE,
            $_FILES,
            $this->streamFactory->createStreamFromFile('php://input')
        );
    }

    /**
     * Keys are not always strings: PHP gives integer keys for `?0=a`, `files[]`, ...
     *
     * @param array<mixed> $server
     * @param array<mixed> $query
     * @param array<mixed>|null $parsedBody
     * @param array<mixed> $cookies
     * @param array<mixed> $files
     * @throws BadRequestException When the request sent by the client is invalid
     */
    public function fromArrays(
        array $server,
        array $query = [],
        ?array $parsedBody = null,
        array $cookies = [],
        array $files = [],
        ?StreamInterface $body = null
    ): ServerRequestInterface {
        // Outside of the try block: an invalid files structure is a programming error, not a bad request
        $uploadedFiles = $this->normalizeFiles($files);

        try {
            $request = $this->createRequest($server);
        } catch (InvalidArgumentException $exception) {
            // The PSR-7 implementations refuse the values they cannot represent, they all come from the client here
            throw new BadRequestException($exception->getMessage(), 0, $exception);
        }

        return $request
            ->withQueryParams($query)
            ->withParsedBody($parsedBody)
            ->withCookieParams($cookies)
            ->withUploadedFiles($uploadedFiles)
            ->withBody($body ?? $this->streamFactory->createStream());
    }

    /**
     * Creates the request from what the client sent: method, URI, protocol version and headers.
     *
     * @param array<mixed> $serverParams
     */
    private function createRequest(array $serverParams): ServerRequestInterface
    {
        $server = self::serverStrings($serverParams);
        $forwarded = $this->trustedProxies->resolve($server);
        $uri = $this->applyForwarded($this->createUri($server), $forwarded);
        $request = $this->serverRequestFactory
            ->createServerRequest($server['REQUEST_METHOD'] ?? 'GET', $uri, $serverParams)
            ->withProtocolVersion(str_replace('HTTP/', '', $server['SERVER_PROTOCOL'] ?? '1.1'))
            ->withAttribute(self::CLIENT_IP_ATTRIBUTE, $forwarded['client-ip']);

        // Asterisk-form (OPTIONS *) cannot be expressed by the URI path, so the request target is kept as is
        if (($server['REQUEST_URI'] ?? null) === '*') {
            $request = $request->withRequestTarget('*');
        }

        // Some implementations (Slim, ...) add the headers of the current request from the globals,
        // they are removed so that the headers only come from $server, whatever the implementation
        foreach (array_keys($request->getHeaders()) as $name) {
            $request = $request->withoutHeader((string)$name);
        }

        $headers = $this->getHeaders($server);

        // Without Host header, it is built from the URI as PSR-7 requires, but the same way for every implementation
        if (!isset($headers['Host']) && $uri->getHost() !== '') {
            $port = $uri->getPort();
            $headers = ['Host' => $uri->getHost().($port !== null ? ':'.$port : '')] + $headers;
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Keeps the scalar values as strings: some runtimes (Swoole, RoadRunner, ...) give integers (SERVER_PORT, ...),
     * and the other entries (argv, ...) are not request data.
     *
     * @param array<mixed> $server
     * @return array<string, string>
     */
    private static function serverStrings(array $server): array
    {
        $strings = [];
        foreach ($server as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $strings[$key] = (string)$value;
            }
        }

        return $strings;
    }

    /**
     * @param array<string, string> $server
     * @return array<string, string>
     */
    private function getHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[$this->normalizeHeaderName(substr($key, 5))] = $value;
            } elseif (str_starts_with($key, 'CONTENT_') && $value !== '') {
                // Empty values are skipped, some servers (nginx, ...) always send CONTENT_TYPE and CONTENT_LENGTH
                $headers[$this->normalizeHeaderName($key)] = $value;
            }
        }

        // Apache can strip the Authorization header, it is then only available in these entries
        if (!isset($headers['Authorization'])) {
            if (isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($server['PHP_AUTH_USER'])) {
                $headers['Authorization'] = 'Basic '.base64_encode($server['PHP_AUTH_USER'].':'.($server['PHP_AUTH_PW'] ?? ''));
            } elseif (isset($server['PHP_AUTH_DIGEST'])) {
                $headers['Authorization'] = 'Digest '.$server['PHP_AUTH_DIGEST'];
            }
        }

        return $headers;
    }

    /**
     * The original case is lost in $_SERVER (HTTP_CONTENT_TYPE), so the usual one is rebuilt (Content-Type).
     */
    private function normalizeHeaderName(string $name): string
    {
        return ucwords(strtolower(str_replace('_', '-', $name)), '-');
    }

    /**
     * @param array<string, string> $server
     */
    private function createUri(array $server): UriInterface
    {
        $requestUri = $server['REQUEST_URI'] ?? '/';

        // In absolute-form (proxy requests), the target authority takes precedence over the Host header (RFC 9112 §3.2.2)
        if (preg_match('/^[a-z][a-z\d+.-]*:\/\//i', $requestUri)) {
            return $this->uriFactory->createUri($requestUri);
        }

        $uri = $this->uriFactory->createUri();

        // The Host header contains the port when it is not the default one, SERVER_* is only used as a fallback
        if (isset($server['HTTP_HOST']) && $server['HTTP_HOST'] !== '') {
            $authority = $this->parseHost($server['HTTP_HOST']);
            $uri = $uri->withHost($authority[0])->withPort($authority[1]);
        } elseif (isset($server['SERVER_NAME'])) {
            $uri = $uri->withHost($server['SERVER_NAME']);
            if (isset($server['SERVER_PORT'])) {
                $uri = $uri->withPort((int)$server['SERVER_PORT']);
            }
        }

        // Without host (CLI, ...), a scheme would give an invalid URI like "http:/"
        if ($uri->getHost() !== '') {
            $https = strtolower($server['HTTPS'] ?? 'off');
            $uri = $uri->withScheme($https !== '' && $https !== 'off' ? 'https' : 'http');
        }

        if ($requestUri === '*') {
            return $uri;
        }

        $parts = explode('?', $requestUri, 2);

        return $uri
            ->withPath($parts[0])
            ->withQuery($server['QUERY_STRING'] ?? $parts[1] ?? '');
    }

    /**
     * @return array{string, ?int} Host and port
     */
    private function parseHost(string $host): array
    {
        if (!preg_match('/^(\[[^]]+]|[^:]+)(?::(\d+))?$/', $host, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid host "%s".', $host));
        }

        return [$matches[1], isset($matches[2]) ? (int)$matches[2] : null];
    }

    /**
     * @param array{client-ip: ?string, proto: ?string, host: ?string, port: ?string} $forwarded
     */
    private function applyForwarded(UriInterface $uri, array $forwarded): UriInterface
    {
        // The forwarded host replaces the whole authority, the local port has nothing to do with it
        if ($forwarded['host'] !== null) {
            $authority = $this->parseHost($forwarded['host']);
            $uri = $uri->withHost($authority[0])->withPort($authority[1]);
        }

        if ($forwarded['port'] !== null) {
            if (!ctype_digit($forwarded['port'])) {
                throw new InvalidArgumentException(sprintf('Invalid forwarded port "%s".', $forwarded['port']));
            }

            $uri = $uri->withPort((int)$forwarded['port']);
        }

        // Same as in createUri(), a scheme without host would give an invalid URI
        if ($forwarded['proto'] !== null && $uri->getHost() !== '') {
            $uri = $uri->withScheme(strtolower($forwarded['proto']));
        }

        return $uri;
    }

    /**
     * Normalizes the $_FILES structure into a tree of UploadedFileInterface, as required by PSR-7.
     *
     * @param array<mixed> $files
     * @return array<mixed>
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $value) {
            $normalized[$key] = match (true) {
                $value instanceof UploadedFileInterface => $value,
                is_array($value) && isset($value['tmp_name']) => $this->createUploadedFiles($value),
                is_array($value) => $this->normalizeFiles($value),
                default => throw new InvalidArgumentException('Invalid value in uploaded files specification.')
            };
        }

        return $normalized;
    }

    /**
     * Fields like `files[]` or `doc[cv]` are given by PHP as `['name' => ['cv' => ...], 'tmp_name' => ['cv' => ...]]`,
     * they are turned back into one file per key, recursively.
     *
     * @param array<mixed> $file
     * @return UploadedFileInterface|array<mixed>
     */
    private function createUploadedFiles(array $file): UploadedFileInterface|array
    {
        $tmpName = $file['tmp_name'] ?? null;
        if (is_array($tmpName)) {
            $files = [];
            foreach ($tmpName as $key => $value) {
                $files[$key] = $this->createUploadedFiles([
                    'tmp_name' => $value,
                    'size' => self::item($file['size'] ?? null, $key),
                    'error' => self::item($file['error'] ?? null, $key) ?? UPLOAD_ERR_OK,
                    'name' => self::item($file['name'] ?? null, $key),
                    'type' => self::item($file['type'] ?? null, $key)
                ]);
            }

            return $files;
        }

        $error = $file['error'] ?? UPLOAD_ERR_OK;
        $size = $file['size'] ?? null;
        $name = $file['name'] ?? null;
        $type = $file['type'] ?? null;

        // Same types as the ones PHP gives in $_FILES
        if (!is_string($tmpName)
            || !is_int($error)
            || ($size !== null && !is_int($size))
            || ($name !== null && !is_string($name))
            || ($type !== null && !is_string($type))
        ) {
            throw new InvalidArgumentException('Invalid value in uploaded files specification.');
        }

        return $this->uploadedFileFactory->createUploadedFile(
            // A failed upload has no temporary file to read from
            $error === UPLOAD_ERR_OK ? $this->streamFactory->createStreamFromFile($tmpName) : $this->streamFactory->createStream(),
            $size,
            $error,
            $name,
            $type
        );
    }

    /**
     * Value of a nested field (`files[]`, `doc[cv]`, ...) in one of the $_FILES entries.
     */
    private static function item(mixed $values, int|string $key): mixed
    {
        return is_array($values) ? $values[$key] ?? null : null;
    }
}
