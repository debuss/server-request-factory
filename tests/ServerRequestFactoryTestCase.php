<?php declare(strict_types=1);

namespace Tests;

use Debuss\ServerRequestFactory\{BadRequestException, ServerRequestFactory, TrustedHeader, TrustedProxies};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    UploadedFileInterface,
    UriFactoryInterface};

/**
 * Every test runs against each PSR-17 implementation, see the subclasses in tests/Implementation.
 */
abstract class ServerRequestFactoryTestCase extends TestCase
{

    private ServerRequestFactory $factory;

    /** @var list<string> */
    private array $tmpFiles = [];

    /** @var array<string, array<mixed>> */
    private array $globals = [];

    /**
     * @return array{ServerRequestFactoryInterface, UriFactoryInterface, UploadedFileFactoryInterface, StreamFactoryInterface}
     */
    abstract protected function createFactories(): array;

    protected function setUp(): void
    {
        $this->factory = new ServerRequestFactory(...$this->createFactories());

        // #[BackupGlobals] is not inherited by subclasses, and some implementations (Slim) read $_SERVER themselves
        $this->globals = ['_SERVER' => $_SERVER, '_GET' => $_GET, '_POST' => $_POST, '_COOKIE' => $_COOKIE, '_FILES' => $_FILES];
    }

    protected function tearDown(): void
    {
        [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES] = array_values($this->globals);

        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createFactoryTrusting(TrustedHeader ...$headers): ServerRequestFactory
    {
        return $this->createFactoryWithProxies(['10.0.0.0/8'], ...$headers);
    }

    /**
     * @param list<string> $proxies
     */
    private function createFactoryWithProxies(array $proxies, TrustedHeader ...$headers): ServerRequestFactory
    {
        return new ServerRequestFactory(...[...$this->createFactories(), new TrustedProxies($proxies, ...$headers)]);
    }

    /**
     * Goes down the uploaded files tree, checking each level.
     *
     * @param array<mixed> $files
     */
    private function uploadedFile(array $files, int|string ...$path): UploadedFileInterface
    {
        $file = $files;
        foreach ($path as $key) {
            $this->assertIsArray($file);
            $this->assertArrayHasKey($key, $file);
            $file = $file[$key];
        }

        $this->assertInstanceOf(UploadedFileInterface::class, $file);

        return $file;
    }

    private function createTmpFile(string $content): string
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'srf');
        file_put_contents($file, $content);
        $this->tmpFiles[] = $file;

        return $file;
    }

    // Method and protocol

    public function testDefaultsToGetAndHttp11(): void
    {
        $request = $this->factory->fromArrays([]);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('1.1', $request->getProtocolVersion());
    }

    public function testMethodAndProtocolVersion(): void
    {
        $request = $this->factory->fromArrays(['REQUEST_METHOD' => 'PATCH', 'SERVER_PROTOCOL' => 'HTTP/2.0']);

        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('2.0', $request->getProtocolVersion());
    }

    public function testServerParams(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1'];

        $this->assertSame($server, $this->factory->fromArrays($server)->getServerParams());
    }

    // URI

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function uriProvider(): iterable
    {
        yield 'host header' => [['HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/foo'], 'http://example.com/foo'];
        yield 'host header with port' => [['HTTP_HOST' => 'example.com:8080', 'REQUEST_URI' => '/foo'], 'http://example.com:8080/foo'];
        yield 'host header with ipv6' => [['HTTP_HOST' => '[::1]:8080', 'REQUEST_URI' => '/'], 'http://[::1]:8080/'];
        yield 'host header wins over server name' => [
            ['HTTP_HOST' => 'example.com', 'SERVER_NAME' => 'internal', 'SERVER_PORT' => '8080', 'REQUEST_URI' => '/'],
            'http://example.com/'
        ];
        yield 'server name fallback' => [['SERVER_NAME' => 'example.com', 'SERVER_PORT' => '8080', 'REQUEST_URI' => '/'], 'http://example.com:8080/'];
        yield 'integer port (Swoole, RoadRunner, ...)' => [['SERVER_NAME' => 'example.com', 'SERVER_PORT' => 8080, 'REQUEST_URI' => '/'], 'http://example.com:8080/'];
        yield 'non-scalar values are ignored' => [['HTTP_HOST' => ['example.com'], 'SERVER_NAME' => 'fallback.com', 'REQUEST_URI' => '/'], 'http://fallback.com/'];
        yield 'default port is hidden' => [['SERVER_NAME' => 'example.com', 'SERVER_PORT' => '80', 'REQUEST_URI' => '/'], 'http://example.com/'];
        yield 'https on' => [['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'REQUEST_URI' => '/'], 'https://example.com/'];
        yield 'https 1' => [['HTTP_HOST' => 'example.com', 'HTTPS' => '1', 'REQUEST_URI' => '/'], 'https://example.com/'];
        yield 'https off' => [['HTTP_HOST' => 'example.com', 'HTTPS' => 'off', 'REQUEST_URI' => '/'], 'http://example.com/'];
        yield 'https empty' => [['HTTP_HOST' => 'example.com', 'HTTPS' => '', 'REQUEST_URI' => '/'], 'http://example.com/'];
        yield 'no host has no scheme' => [['REQUEST_URI' => '/foo?a=1'], '/foo?a=1'];
        yield 'no request uri' => [['HTTP_HOST' => 'example.com'], 'http://example.com/'];
        yield 'query from request uri' => [['HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/foo?a=1&b=2'], 'http://example.com/foo?a=1&b=2'];
        yield 'query string wins over request uri' => [
            ['HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/foo?a=1', 'QUERY_STRING' => 'b=2'],
            'http://example.com/foo?b=2'
        ];
        yield 'absolute-form' => [
            ['HTTP_HOST' => 'other.com', 'REQUEST_URI' => 'https://example.com:8443/foo?a=1'],
            'https://example.com:8443/foo?a=1'
        ];
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('uriProvider')]
    public function testUri(array $server, string $expected): void
    {
        $this->assertSame($expected, (string)$this->factory->fromArrays($server)->getUri());
    }

    public function testAsteriskForm(): void
    {
        $request = $this->factory->fromArrays(['REQUEST_METHOD' => 'OPTIONS', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '*']);

        $this->assertSame('*', $request->getRequestTarget());
        $this->assertSame('', $request->getUri()->getPath());
        $this->assertSame('example.com', $request->getUri()->getHost());
    }

    public function testEmptyHostHeaderFallsBackToServerName(): void
    {
        $request = $this->factory->fromArrays(['HTTP_HOST' => '', 'SERVER_NAME' => 'example.com', 'REQUEST_URI' => '/']);

        $this->assertSame('http://example.com/', (string)$request->getUri());
    }

    // Bad requests

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badRequestProvider(): iterable
    {
        yield 'invalid port' => [['HTTP_HOST' => 'example.com:99999']];
        yield 'invalid host' => [['HTTP_HOST' => 'example.com:abc']];
        yield 'invalid header value' => [['HTTP_X_FOO' => "a\nb"]];
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('badRequestProvider')]
    public function testBadRequest(array $server): void
    {
        try {
            $this->factory->fromArrays($server);
            $this->fail('A BadRequestException was expected.');
        } catch (BadRequestException $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedByImplementationProvider(): iterable
    {
        yield 'invalid method' => [['REQUEST_METHOD' => 'GE T']];
        yield 'fragment in path' => [['HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/foo#bar']];
        yield 'unsupported protocol version' => [['SERVER_PROTOCOL' => 'HTTP/3']];
    }

    /**
     * Not every implementation validates these values, but when one refuses them, it is a bad request.
     *
     * @param array<string, mixed> $server
     */
    #[DataProvider('refusedByImplementationProvider')]
    public function testRefusedByImplementationIsBadRequest(array $server): void
    {
        try {
            $this->factory->fromArrays($server);
            $this->addToAssertionCount(1);
        } catch (InvalidArgumentException $exception) {
            $this->assertInstanceOf(BadRequestException::class, $exception);
        }
    }

    // Headers

    public function testHeadersFromServer(): void
    {
        $request = $this->factory->fromArrays([
            'HTTP_HOST' => 'example.com',
            'HTTP_ACCEPT_LANGUAGE' => 'fr',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '12',
            'REQUEST_METHOD' => 'POST',
            'SERVER_NAME' => 'internal'
        ]);

        $this->assertSame([
            'Host' => ['example.com'],
            'Accept-Language' => ['fr'],
            'X-Forwarded-For' => ['1.2.3.4'],
            'Content-Type' => ['application/json'],
            'Content-Length' => ['12']
        ], $request->getHeaders());
    }

    public function testHeaderNameKeepsItsCaseButIsCaseInsensitive(): void
    {
        $request = $this->factory->fromArrays(['HTTP_CONTENT_TYPE' => 'text/plain']);

        $this->assertArrayHasKey('Content-Type', $request->getHeaders());
        $this->assertSame('text/plain', $request->getHeaderLine('content-type'));
    }

    public function testEmptyContentHeadersAreSkipped(): void
    {
        $request = $this->factory->fromArrays(['CONTENT_TYPE' => '', 'CONTENT_LENGTH' => '']);

        $this->assertFalse($request->hasHeader('Content-Type'));
        $this->assertFalse($request->hasHeader('Content-Length'));
    }

    public function testEmptyHttpHeadersAreKept(): void
    {
        $request = $this->factory->fromArrays(['HTTP_X_EMPTY' => '']);

        $this->assertTrue($request->hasHeader('X-Empty'));
        $this->assertSame('', $request->getHeaderLine('X-Empty'));
    }

    public function testHeadersOnlyComeFromServerParams(): void
    {
        $_SERVER['HTTP_HOST'] = 'global.com';
        $_SERVER['HTTP_X_GLOBAL'] = 'leaked';

        $request = $this->factory->fromArrays(['REQUEST_URI' => '/', 'HTTP_ACCEPT' => 'text/html']);

        $this->assertSame(['Accept' => ['text/html']], $request->getHeaders());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function hostFromUriProvider(): iterable
    {
        yield 'server name' => [['SERVER_NAME' => 'example.com', 'SERVER_PORT' => '80'], 'example.com'];
        yield 'server name with port' => [['SERVER_NAME' => 'example.com', 'SERVER_PORT' => '8080'], 'example.com:8080'];
        yield 'absolute-form' => [['REQUEST_URI' => 'https://example.com:8443/foo'], 'example.com:8443'];
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('hostFromUriProvider')]
    public function testHostHeaderFromUri(array $server, string $expected): void
    {
        $this->assertSame([$expected], $this->factory->fromArrays($server)->getHeader('Host'));
    }

    public function testNoHostHeaderWithoutHost(): void
    {
        $this->assertFalse($this->factory->fromArrays(['REQUEST_URI' => '/'])->hasHeader('Host'));
    }

    public function testNonScalarServerValuesAreNotHeaders(): void
    {
        $request = $this->factory->fromArrays(['HTTP_X_ARRAY' => ['foo'], 'HTTP_X_NULL' => null]);

        $this->assertFalse($request->hasHeader('X-Array'));
        $this->assertFalse($request->hasHeader('X-Null'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function authorizationProvider(): iterable
    {
        yield 'header' => [['HTTP_AUTHORIZATION' => 'Bearer header'], 'Bearer header'];
        yield 'redirect' => [['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirect'], 'Bearer redirect'];
        yield 'basic' => [['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'pass'], 'Basic '.base64_encode('user:pass')];
        yield 'basic without password' => [['PHP_AUTH_USER' => 'user'], 'Basic '.base64_encode('user:')];
        yield 'digest' => [['PHP_AUTH_DIGEST' => 'username="user", realm="realm"'], 'Digest username="user", realm="realm"'];
        yield 'header wins' => [
            ['HTTP_AUTHORIZATION' => 'Bearer header', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirect', 'PHP_AUTH_USER' => 'user'],
            'Bearer header'
        ];
        yield 'redirect wins over php auth' => [['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirect', 'PHP_AUTH_USER' => 'user'], 'Bearer redirect'];
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('authorizationProvider')]
    public function testAuthorization(array $server, string $expected): void
    {
        $request = $this->factory->fromArrays($server);

        $this->assertSame([$expected], $request->getHeader('Authorization'));
    }

    // Parameters and body

    public function testParameters(): void
    {
        $request = $this->factory->fromArrays(
            ['REQUEST_METHOD' => 'POST'],
            ['page' => '2'],
            ['name' => 'John'],
            ['session' => 'abc', 'prefs' => ['theme' => 'dark']]
        );

        $this->assertSame(['page' => '2'], $request->getQueryParams());
        $this->assertSame(['name' => 'John'], $request->getParsedBody());
        $this->assertSame(['session' => 'abc', 'prefs' => ['theme' => 'dark']], $request->getCookieParams());
    }

    public function testIntegerKeys(): void
    {
        // ?0=a&1=b, cookie 0=c
        $request = $this->factory->fromArrays([], [0 => 'a', 1 => 'b'], [0 => 'body'], [0 => 'c']);

        $this->assertSame(['a', 'b'], $request->getQueryParams());
        $this->assertSame(['body'], $request->getParsedBody());
        $this->assertSame(['c'], $request->getCookieParams());
    }

    public function testDefaultBodyIsEmpty(): void
    {
        $request = $this->factory->fromArrays([]);

        $this->assertNull($request->getParsedBody());
        $this->assertSame('', (string)$request->getBody());
    }

    public function testBody(): void
    {
        [, , , $streamFactory] = $this->createFactories();

        $request = $this->factory->fromArrays([], body: $streamFactory->createStream('{"a":1}'));

        $this->assertSame('{"a":1}', (string)$request->getBody());
    }

    // Uploaded files

    public function testSingleFile(): void
    {
        $tmp = $this->createTmpFile('content');

        $files = $this->factory->fromArrays([], files: [
            'avatar' => ['tmp_name' => $tmp, 'name' => 'me.png', 'type' => 'image/png', 'size' => 7, 'error' => UPLOAD_ERR_OK]
        ])->getUploadedFiles();

        $avatar = $this->uploadedFile($files, 'avatar');
        $this->assertSame('me.png', $avatar->getClientFilename());
        $this->assertSame('image/png', $avatar->getClientMediaType());
        $this->assertSame(7, $avatar->getSize());
        $this->assertSame(UPLOAD_ERR_OK, $avatar->getError());
        $this->assertSame('content', (string)$avatar->getStream());
    }

    public function testMultipleFiles(): void
    {
        // <input type="file" name="docs[]" multiple>
        $files = $this->factory->fromArrays([], files: [
            'docs' => [
                'tmp_name' => [$this->createTmpFile('a'), $this->createTmpFile('b')],
                'name' => ['a.txt', 'b.txt'],
                'type' => ['text/plain', 'text/plain'],
                'size' => [1, 1],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK]
            ]
        ])->getUploadedFiles();

        $this->assertIsArray($files['docs']);
        $this->assertCount(2, $files['docs']);
        $this->assertSame('a.txt', $this->uploadedFile($files, 'docs', 0)->getClientFilename());
        $this->assertSame('b', (string)$this->uploadedFile($files, 'docs', 1)->getStream());
    }

    public function testDeeplyNestedFiles(): void
    {
        // <input type="file" name="user[docs][cv]"> and <input type="file" name="user[docs][letters][]">
        $files = $this->factory->fromArrays([], files: [
            'user' => [
                'tmp_name' => ['docs' => ['cv' => $this->createTmpFile('cv'), 'letters' => [$this->createTmpFile('letter')]]],
                'name' => ['docs' => ['cv' => 'cv.pdf', 'letters' => ['letter.pdf']]],
                'type' => ['docs' => ['cv' => 'application/pdf', 'letters' => ['application/pdf']]],
                'size' => ['docs' => ['cv' => 2, 'letters' => [6]]],
                'error' => ['docs' => ['cv' => UPLOAD_ERR_OK, 'letters' => [UPLOAD_ERR_OK]]]
            ]
        ])->getUploadedFiles();

        $this->assertSame('cv.pdf', $this->uploadedFile($files, 'user', 'docs', 'cv')->getClientFilename());
        $this->assertSame('letter', (string)$this->uploadedFile($files, 'user', 'docs', 'letters', 0)->getStream());
    }

    public function testAlreadyNestedFiles(): void
    {
        // Structure already in the PSR-7 shape, as some tools (or tests) give it
        $files = $this->factory->fromArrays([], files: [
            'docs' => [
                'cv' => ['tmp_name' => $this->createTmpFile('cv'), 'name' => 'cv.pdf', 'size' => 2, 'error' => UPLOAD_ERR_OK]
            ]
        ])->getUploadedFiles();

        $this->assertSame('cv.pdf', $this->uploadedFile($files, 'docs', 'cv')->getClientFilename());
    }

    public function testFailedUpload(): void
    {
        $files = $this->factory->fromArrays([], files: [
            'avatar' => ['tmp_name' => '', 'name' => '', 'type' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]
        ])->getUploadedFiles();

        $this->assertSame(UPLOAD_ERR_NO_FILE, $this->uploadedFile($files, 'avatar')->getError());
    }

    public function testUploadedFileInstancesAreKept(): void
    {
        [, , $uploadedFileFactory, $streamFactory] = $this->createFactories();
        $file = $uploadedFileFactory->createUploadedFile($streamFactory->createStream('content'));

        $files = $this->factory->fromArrays([], files: ['avatar' => $file, 'docs' => [$file]])->getUploadedFiles();

        $this->assertSame($file, $this->uploadedFile($files, 'avatar'));
        $this->assertSame($file, $this->uploadedFile($files, 'docs', 0));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidFilesProvider(): iterable
    {
        yield 'not a file' => [['avatar' => 'not a file']];
        yield 'tmp_name is not a string' => [['avatar' => ['tmp_name' => 42, 'error' => UPLOAD_ERR_OK]]];
        yield 'error is not an integer' => [['avatar' => ['tmp_name' => '', 'error' => '4']]];
        yield 'size is not an integer' => [['avatar' => ['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => '0']]];
        yield 'name is not a string' => [['avatar' => ['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'name' => ['a']]]];
        yield 'nested error is not an integer' => [['docs' => ['tmp_name' => [''], 'error' => ['4']]]];
    }

    /**
     * @param array<mixed> $files
     */
    #[DataProvider('invalidFilesProvider')]
    public function testInvalidFilesSpecification(array $files): void
    {
        // Given by PHP or the developer, not by the client: it is not a bad request
        try {
            $this->factory->fromArrays([], files: $files);
            $this->fail('An InvalidArgumentException was expected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotInstanceOf(BadRequestException::class, $exception);
        }
    }

    // Trusted proxies

    public function testClientIpWithoutTrustedProxies(): void
    {
        $request = $this->factory->fromArrays(['REMOTE_ADDR' => '203.0.113.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1']);

        $this->assertSame('203.0.113.1', $request->getAttribute(ServerRequestFactory::CLIENT_IP_ATTRIBUTE));
    }

    public function testClientIpUnknown(): void
    {
        $this->assertNull($this->factory->fromArrays([])->getAttribute(ServerRequestFactory::CLIENT_IP_ATTRIBUTE));
    }

    public function testUntrustedRemoteIgnoresProxyHeaders(): void
    {
        $factory = $this->createFactoryTrusting(
            TrustedHeader::XForwardedFor,
            TrustedHeader::XForwardedProto,
            TrustedHeader::XForwardedHost,
            TrustedHeader::XForwardedPort
        );

        $request = $factory->fromArrays([
            'REMOTE_ADDR' => '203.0.113.1',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/foo',
            'HTTP_X_FORWARDED_FOR' => '1.1.1.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil.com',
            'HTTP_X_FORWARDED_PORT' => '8443'
        ]);

        $this->assertSame('http://example.com/foo', (string)$request->getUri());
        $this->assertSame('203.0.113.1', $request->getAttribute(ServerRequestFactory::CLIENT_IP_ATTRIBUTE));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function xForwardedForProvider(): iterable
    {
        yield 'no header' => [[], '10.0.0.1'];
        yield 'single proxy' => [['HTTP_X_FORWARDED_FOR' => '1.1.1.1'], '1.1.1.1'];
        yield 'chain of trusted proxies' => [['HTTP_X_FORWARDED_FOR' => '1.1.1.1, 10.0.0.2'], '1.1.1.1'];
        yield 'forged by the client' => [['HTTP_X_FORWARDED_FOR' => '6.6.6.6, 1.1.1.1'], '1.1.1.1'];
        yield 'only proxies' => [['HTTP_X_FORWARDED_FOR' => '10.0.0.3, 10.0.0.2'], '10.0.0.3'];
        yield 'ipv4 with port' => [['HTTP_X_FORWARDED_FOR' => '1.1.1.1:4711'], '1.1.1.1'];
        yield 'ipv6 with port' => [['HTTP_X_FORWARDED_FOR' => '[2001:db8::1]:4711'], '2001:db8::1'];
        yield 'ipv6' => [['HTTP_X_FORWARDED_FOR' => '2001:db8::1'], '2001:db8::1'];
        yield 'not an ip' => [['HTTP_X_FORWARDED_FOR' => 'unknown'], null];
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('xForwardedForProvider')]
    public function testXForwardedFor(array $server, ?string $expected): void
    {
        $request = $this->createFactoryTrusting(TrustedHeader::XForwardedFor)->fromArrays(['REMOTE_ADDR' => '10.0.0.1'] + $server);

        $this->assertSame($expected, $request->getAttribute(ServerRequestFactory::CLIENT_IP_ATTRIBUTE));
    }

    /**
     * @return iterable<string, array{list<TrustedHeader>, array<string, mixed>, string}>
     */
    public static function xForwardedUriProvider(): iterable
    {
        yield 'proto' => [[TrustedHeader::XForwardedProto], ['HTTP_X_FORWARDED_PROTO' => 'https'], 'https://example.com/foo'];
        yield 'uppercase proto' => [[TrustedHeader::XForwardedProto], ['HTTP_X_FORWARDED_PROTO' => 'HTTPS'], 'https://example.com/foo'];
        yield 'host' => [[TrustedHeader::XForwardedHost], ['HTTP_X_FORWARDED_HOST' => 'public.com'], 'http://public.com/foo'];
        yield 'host with port' => [[TrustedHeader::XForwardedHost], ['HTTP_X_FORWARDED_HOST' => 'public.com:8443'], 'http://public.com:8443/foo'];
        yield 'host drops the local port' => [
            [TrustedHeader::XForwardedHost],
            ['HTTP_HOST' => 'example.com:8080', 'HTTP_X_FORWARDED_HOST' => 'public.com'],
            'http://public.com/foo'
        ];
        yield 'port' => [[TrustedHeader::XForwardedPort], ['HTTP_X_FORWARDED_PORT' => '8443'], 'http://example.com:8443/foo'];
        yield 'everything' => [
            [TrustedHeader::XForwardedFor, TrustedHeader::XForwardedProto, TrustedHeader::XForwardedHost, TrustedHeader::XForwardedPort],
            ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'public.com', 'HTTP_X_FORWARDED_PORT' => '443'],
            'https://public.com/foo'
        ];
        yield 'untrusted host is ignored' => [
            [TrustedHeader::XForwardedFor, TrustedHeader::XForwardedProto],
            ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'evil.com'],
            'https://example.com/foo'
        ];
        yield 'values appended by each proxy' => [
            [TrustedHeader::XForwardedFor, TrustedHeader::XForwardedProto, TrustedHeader::XForwardedHost],
            ['HTTP_X_FORWARDED_FOR' => '1.1.1.1, 10.0.0.2', 'HTTP_X_FORWARDED_PROTO' => 'https, http', 'HTTP_X_FORWARDED_HOST' => 'public.com, internal'],
            'https://public.com/foo'
        ];
        yield 'value forged by the client' => [
            [TrustedHeader::XForwardedFor, TrustedHeader::XForwardedHost],
            ['HTTP_X_FORWARDED_FOR' => '1.1.1.1', 'HTTP_X_FORWARDED_HOST' => 'evil.com, public.com'],
            'http://public.com/foo'
        ];
        yield 'value overridden by the last proxy' => [
            [TrustedHeader::XForwardedFor, TrustedHeader::XForwardedProto],
            ['HTTP_X_FORWARDED_FOR' => '1.1.1.1, 10.0.0.2', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            'https://example.com/foo'
        ];
        yield 'forwarded is ignored' => [
            [TrustedHeader::XForwardedFor, TrustedHeader::XForwardedProto],
            ['HTTP_FORWARDED' => 'for=6.6.6.6;proto=https;host=evil.com'],
            'http://example.com/foo'
        ];
    }

    /**
     * @param list<TrustedHeader> $headers
     * @param array<string, mixed> $server
     */
    #[DataProvider('xForwardedUriProvider')]
    public function testXForwardedUri(array $headers, array $server, string $expected): void
    {
        $request = $this->createFactoryTrusting(...$headers)->fromArrays(
            $server + ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/foo']
        );

        $this->assertSame($expected, (string)$request->getUri());
    }

    public function testHostHeaderIsKept(): void
    {
        $request = $this->createFactoryTrusting(TrustedHeader::XForwardedHost)->fromArrays([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_HOST' => 'example.com',
            'HTTP_X_FORWARDED_HOST' => 'public.com'
        ]);

        $this->assertSame('public.com', $request->getUri()->getHost());
        $this->assertSame('example.com', $request->getHeaderLine('Host'));
    }

    /**
     * @return iterable<string, array{TrustedHeader, array<string, mixed>}>
     */
    public static function invalidForwardedProvider(): iterable
    {
        yield 'x-forwarded-port' => [TrustedHeader::XForwardedPort, ['HTTP_X_FORWARDED_PORT' => 'abc']];
        yield 'x-forwarded-port out of range' => [TrustedHeader::XForwardedPort, ['HTTP_X_FORWARDED_PORT' => '99999']];
        yield 'x-forwarded-host' => [TrustedHeader::XForwardedHost, ['HTTP_X_FORWARDED_HOST' => 'public.com:abc']];
        yield 'forwarded host' => [TrustedHeader::Forwarded, ['HTTP_FORWARDED' => 'for=1.1.1.1;host="public.com:abc"']];
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('invalidForwardedProvider')]
    public function testInvalidForwardedValue(TrustedHeader $header, array $server): void
    {
        $this->expectException(BadRequestException::class);

        $this->createFactoryTrusting($header)->fromArrays($server + ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => 'example.com']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, ?string}>
     */
    public static function forwardedProvider(): iterable
    {
        yield 'no header' => [[], 'http://example.com/foo', '10.0.0.1'];
        yield 'single proxy' => [['HTTP_FORWARDED' => 'for=1.1.1.1;proto=https;host=public.com'], 'https://public.com/foo', '1.1.1.1'];
        yield 'chain of trusted proxies' => [
            ['HTTP_FORWARDED' => 'for=1.1.1.1;proto=https;host=public.com, for=10.0.0.2;proto=http;host=internal'],
            'https://public.com/foo',
            '1.1.1.1'
        ];
        yield 'forged by the client' => [
            ['HTTP_FORWARDED' => 'for=6.6.6.6;host=evil.com, for=1.1.1.1;proto=https;host=public.com'],
            'https://public.com/foo',
            '1.1.1.1'
        ];
        yield 'missing values from the next trusted proxy' => [
            ['HTTP_FORWARDED' => 'for=1.1.1.1, for=10.0.0.2;proto=https'],
            'https://example.com/foo',
            '1.1.1.1'
        ];
        yield 'quoted ipv6 with port' => [['HTTP_FORWARDED' => 'for="[2001:db8::1]:4711";proto=https'], 'https://example.com/foo', '2001:db8::1'];
        yield 'quoted host with port' => [['HTTP_FORWARDED' => 'for=1.1.1.1;host="public.com:8443"'], 'http://public.com:8443/foo', '1.1.1.1'];
        yield 'case insensitive names' => [['HTTP_FORWARDED' => 'For=1.1.1.1;Proto=https'], 'https://example.com/foo', '1.1.1.1'];
        yield 'obfuscated client' => [['HTTP_FORWARDED' => 'for=_hidden;proto=https'], 'https://example.com/foo', null];
        yield 'x-forwarded is ignored' => [
            ['HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'evil.com'],
            'http://example.com/foo',
            '10.0.0.1'
        ];
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('forwardedProvider')]
    public function testForwarded(array $server, string $expectedUri, ?string $expectedIp): void
    {
        $request = $this->createFactoryTrusting(TrustedHeader::Forwarded)->fromArrays(
            $server + ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/foo']
        );

        $this->assertSame($expectedUri, (string)$request->getUri());
        $this->assertSame($expectedIp, $request->getAttribute(ServerRequestFactory::CLIENT_IP_ATTRIBUTE));
    }

    /**
     * @return iterable<string, array{list<string>, list<TrustedHeader>, array<string, mixed>, string, ?string}>
     */
    public static function remoteAddrProvider(): iterable
    {
        $xForwarded = [TrustedHeader::XForwardedFor, TrustedHeader::XForwardedProto, TrustedHeader::XForwardedHost];

        yield 'any direct peer' => [
            [TrustedProxies::REMOTE_ADDR],
            $xForwarded,
            ['HTTP_X_FORWARDED_FOR' => '1.1.1.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'public.com'],
            'https://public.com/foo',
            '1.1.1.1'
        ];
        yield 'only the direct peer' => [
            [TrustedProxies::REMOTE_ADDR],
            $xForwarded,
            ['HTTP_X_FORWARDED_FOR' => '6.6.6.6, 1.1.1.1', 'HTTP_X_FORWARDED_HOST' => 'evil.com, public.com'],
            'http://public.com/foo',
            '1.1.1.1'
        ];
        yield 'the direct peer address is not trusted further' => [
            [TrustedProxies::REMOTE_ADDR],
            $xForwarded,
            ['HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.1'],
            'http://example.com/foo',
            '203.0.113.1'
        ];
        yield 'along with other proxies' => [
            [TrustedProxies::REMOTE_ADDR, '10.0.0.0/8'],
            $xForwarded,
            ['HTTP_X_FORWARDED_FOR' => '1.1.1.1, 10.0.0.2', 'HTTP_X_FORWARDED_PROTO' => 'https, http'],
            'https://example.com/foo',
            '1.1.1.1'
        ];
        yield 'forwarded' => [
            [TrustedProxies::REMOTE_ADDR],
            [TrustedHeader::Forwarded],
            ['HTTP_FORWARDED' => 'for=6.6.6.6;host=evil.com, for=1.1.1.1;proto=https;host=public.com'],
            'https://public.com/foo',
            '1.1.1.1'
        ];
    }

    /**
     * @param list<string> $proxies
     * @param list<TrustedHeader> $headers
     * @param array<string, mixed> $server
     */
    #[DataProvider('remoteAddrProvider')]
    public function testTrustedRemoteAddr(array $proxies, array $headers, array $server, string $expectedUri, ?string $expectedIp): void
    {
        $request = $this->createFactoryWithProxies($proxies, ...$headers)->fromArrays(
            $server + ['REMOTE_ADDR' => '203.0.113.1', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/foo']
        );

        $this->assertSame($expectedUri, (string)$request->getUri());
        $this->assertSame($expectedIp, $request->getAttribute(ServerRequestFactory::CLIENT_IP_ATTRIBUTE));
    }

    // Globals

    public function testFromGlobals(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/form?page=2',
            'QUERY_STRING' => 'page=2',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=UTF-8'
        ];
        $_GET = ['page' => '2'];
        $_POST = ['name' => 'John'];
        $_COOKIE = ['session' => 'abc'];
        $_FILES = [];

        $request = $this->factory->fromGlobals();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://example.com/form?page=2', (string)$request->getUri());
        $this->assertSame(['page' => '2'], $request->getQueryParams());
        $this->assertSame(['name' => 'John'], $request->getParsedBody());
        $this->assertSame(['session' => 'abc'], $request->getCookieParams());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function noParsedBodyProvider(): iterable
    {
        yield 'json body' => ['POST', 'application/json'];
        yield 'not a post' => ['PUT', 'application/x-www-form-urlencoded'];
    }

    #[DataProvider('noParsedBodyProvider')]
    public function testFromGlobalsWithoutParsedBody(string $method, string $contentType): void
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'CONTENT_TYPE' => $contentType];
        $_GET = $_COOKIE = $_FILES = [];
        $_POST = ['ignored' => 'value'];

        $this->assertNull($this->factory->fromGlobals()->getParsedBody());
    }

    public function testFromGlobalsMultipart(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data; boundary=----abc'];
        $_GET = $_COOKIE = [];
        $_POST = ['name' => 'John'];
        $_FILES = [
            'avatar' => ['tmp_name' => $this->createTmpFile('content'), 'name' => 'me.png', 'type' => 'image/png', 'size' => 7, 'error' => UPLOAD_ERR_OK]
        ];

        $request = $this->factory->fromGlobals();

        $this->assertSame(['name' => 'John'], $request->getParsedBody());
        $this->assertSame('me.png', $this->uploadedFile($request->getUploadedFiles(), 'avatar')->getClientFilename());
    }
}
