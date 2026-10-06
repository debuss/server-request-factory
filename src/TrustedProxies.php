<?php declare(strict_types=1);

namespace Debuss\ServerRequestFactory;

use InvalidArgumentException;

final readonly class TrustedProxies
{

    /**
     * Trusts the direct peer whatever its address, for hosts whose proxy addresses are not known (PaaS, ...).
     * Only that hop is trusted: the addresses it gives are not, unless they match one of the other proxies.
     */
    public const REMOTE_ADDR = 'REMOTE_ADDR';

    /** @var list<array{string, int}> Binary address and prefix length of each trusted network */
    private array $networks;

    private bool $trustsRemoteAddr;

    /** @var list<TrustedHeader> */
    private array $headers;

    /**
     * @param list<string> $proxies IP addresses, CIDR ranges (10.0.0.0/8, 2001:db8::/32, ...) or self::REMOTE_ADDR
     */
    public function __construct(array $proxies = [], TrustedHeader ...$headers)
    {
        if ($proxies !== [] && $headers === []) {
            throw new InvalidArgumentException('At least one trusted header is required.');
        }

        // A proxy that sets one family usually lets the other through, so the client could forge it
        $xForwarded = array_filter($headers, static fn (TrustedHeader $header) => $header !== TrustedHeader::Forwarded);
        if (in_array(TrustedHeader::Forwarded, $headers, true) && $xForwarded !== []) {
            throw new InvalidArgumentException('Forwarded cannot be trusted along with the X-Forwarded-* headers.');
        }

        $this->trustsRemoteAddr = in_array(self::REMOTE_ADDR, $proxies, true);
        $this->networks = array_values(array_map(
            self::parseNetwork(...),
            array_filter($proxies, static fn (string $proxy) => $proxy !== self::REMOTE_ADDR)
        ));
        $this->headers = array_values($headers);
    }

    /**
     * Whether the address belongs to one of the trusted proxies, self::REMOTE_ADDR is not taken into account.
     */
    public function isTrusted(string $ip): bool
    {
        $binary = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($binary === false) {
            return false;
        }

        foreach ($this->networks as [$network, $prefix]) {
            // IPv4 and IPv6 never match each other
            if (strlen($network) !== strlen($binary)) {
                continue;
            }

            $bytes = intdiv($prefix, 8);
            if (substr($binary, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }

            $bits = $prefix % 8;
            if ($bits === 0) {
                return true;
            }

            $mask = (0xff << (8 - $bits)) & 0xff;
            if ((ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads what the trusted proxies say about the original request, a value is null when it is not known.
     *
     * @internal
     * @param array<string, mixed> $server
     * @return array{client-ip: ?string, proto: ?string, host: ?string, port: ?string}
     */
    public function resolve(array $server): array
    {
        $remote = self::normalizeIp($server['REMOTE_ADDR'] ?? null);
        $resolved = ['client-ip' => $remote, 'proto' => null, 'host' => null, 'port' => null];

        if ($remote === null || (!$this->trustsRemoteAddr && !$this->isTrusted($remote))) {
            return $resolved;
        }

        if (in_array(TrustedHeader::Forwarded, $this->headers, true)) {
            $elements = self::parseForwarded(self::header($server, 'HTTP_FORWARDED'));
            [$index, $resolved['client-ip']] = $this->walk(array_map(static fn (array $element) => $element['for'] ?? '', $elements), $remote);

            // Elements from the client one onwards were added by trusted proxies, the closest to the client wins
            foreach (array_slice($elements, $index) as $element) {
                $resolved['proto'] ??= $element['proto'] ?? null;
                $resolved['host'] ??= $element['host'] ?? null;
            }

            return $resolved;
        }

        $hops = in_array(TrustedHeader::XForwardedFor, $this->headers, true)
            ? self::split(self::header($server, 'HTTP_X_FORWARDED_FOR'))
            : [];
        [$index, $resolved['client-ip']] = $this->walk($hops, $remote);

        // Proxies append their value, so the one given by the outermost trusted proxy is the n-th from the end.
        // A shorter list means a proxy has overridden the header, its values all come from trusted proxies.
        $trustedHops = max(1, count($hops) - $index);
        $headers = [
            'proto' => [TrustedHeader::XForwardedProto, 'HTTP_X_FORWARDED_PROTO'],
            'host' => [TrustedHeader::XForwardedHost, 'HTTP_X_FORWARDED_HOST'],
            'port' => [TrustedHeader::XForwardedPort, 'HTTP_X_FORWARDED_PORT']
        ];
        foreach ($headers as $key => [$header, $name]) {
            $values = in_array($header, $this->headers, true) ? self::split(self::header($server, $name)) : [];
            if ($values !== []) {
                $resolved[$key] = $values[max(0, count($values) - $trustedHops)];
            }
        }

        return $resolved;
    }

    /**
     * Goes from the application to the client: as long as a hop is a trusted proxy, the address it gives is read.
     * The direct peer is already known to be trusted, so the walk starts with the last address of $hops.
     * Returns the index of the client in $hops and its IP (null when a proxy gave something else, like "unknown").
     *
     * @param list<string> $hops
     * @return array{int, ?string}
     */
    private function walk(array $hops, string $remote): array
    {
        if ($hops === []) {
            return [0, $remote];
        }

        $index = count($hops) - 1;
        while ($index > 0 && ($ip = self::normalizeIp($hops[$index])) !== null && $this->isTrusted($ip)) {
            $index--;
        }

        return [$index, self::normalizeIp($hops[$index])];
    }

    /**
     * @return array{string, int}
     */
    private static function parseNetwork(string $proxy): array
    {
        $parts = explode('/', $proxy, 2);
        $ip = $parts[0];
        $prefix = $parts[1] ?? null;

        $binary = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($binary === false) {
            throw new InvalidArgumentException(sprintf('Invalid trusted proxy "%s".', $proxy));
        }

        $max = strlen($binary) * 8;
        if ($prefix !== null && (!ctype_digit($prefix) || (int)$prefix > $max)) {
            throw new InvalidArgumentException(sprintf('Invalid trusted proxy "%s".', $proxy));
        }

        return [$binary, $prefix === null ? $max : (int)$prefix];
    }

    /**
     * Some proxies (Azure, ...) and RFC 7239 add the port: 192.0.2.60:4711, "[2001:db8::17]:4711".
     */
    private static function normalizeIp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value, " \t\"");
        if (preg_match('/^\[([^]]+)](?::\d+)?$/', $value, $matches)) {
            $value = $matches[1];
        } elseif (substr_count($value, ':') === 1) {
            $value = explode(':', $value)[0];
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    /**
     * Forwarded: for=192.0.2.60;proto=https;host=example.com, for="[2001:db8::17]:4711"
     *
     * @return list<array<string, string>>
     */
    private static function parseForwarded(string $header): array
    {
        $elements = [];
        foreach (self::split($header) as $element) {
            $pairs = [];
            foreach (explode(';', $element) as $pair) {
                [$name, $value] = array_map(trim(...), explode('=', $pair, 2)) + [1 => ''];
                $pairs[strtolower($name)] = trim($value, '"');
            }

            $elements[] = $pairs;
        }

        return $elements;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function header(array $server, string $name): string
    {
        return is_string($server[$name] ?? null) ? $server[$name] : '';
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $part) => $part !== ''));
    }
}
