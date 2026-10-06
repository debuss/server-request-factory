<?php declare(strict_types=1);

namespace Tests;

use Debuss\ServerRequestFactory\{TrustedHeader, TrustedProxies};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrustedProxiesTest extends TestCase
{

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function isTrustedProvider(): iterable
    {
        yield 'exact ipv4' => [['10.0.0.1'], '10.0.0.1', true];
        yield 'other ipv4' => [['10.0.0.1'], '10.0.0.2', false];
        yield 'ipv4 range' => [['10.0.0.0/8'], '10.255.1.2', true];
        yield 'outside ipv4 range' => [['10.0.0.0/8'], '11.0.0.1', false];
        yield 'ipv4 range not on a byte' => [['172.16.0.0/12'], '172.31.255.255', true];
        yield 'outside ipv4 range not on a byte' => [['172.16.0.0/12'], '172.32.0.0', false];
        yield 'every ipv4' => [['0.0.0.0/0'], '203.0.113.1', true];
        yield 'exact ipv6' => [['::1'], '::1', true];
        yield 'ipv6 range' => [['2001:db8::/32'], '2001:db8:cafe::17', true];
        yield 'outside ipv6 range' => [['2001:db8::/32'], '2001:db9::1', false];
        yield 'ipv4 against ipv6' => [['::/0'], '10.0.0.1', false];
        yield 'one of several' => [['192.168.0.0/16', '10.0.0.0/8'], '10.0.0.1', true];
        yield 'not an ip' => [['10.0.0.0/8'], 'unknown', false];
        yield 'nothing trusted' => [[], '10.0.0.1', false];
    }

    /**
     * @param list<string> $proxies
     */
    #[DataProvider('isTrustedProvider')]
    public function testIsTrusted(array $proxies, string $ip, bool $expected): void
    {
        $this->assertSame($expected, (new TrustedProxies($proxies, TrustedHeader::XForwardedFor))->isTrusted($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProxyProvider(): iterable
    {
        yield 'not an ip' => ['localhost'];
        yield 'ipv4 prefix too long' => ['10.0.0.0/33'];
        yield 'ipv6 prefix too long' => ['::1/129'];
        yield 'negative prefix' => ['10.0.0.0/-1'];
        yield 'empty prefix' => ['10.0.0.0/'];
    }

    #[DataProvider('invalidProxyProvider')]
    public function testInvalidProxy(string $proxy): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrustedProxies([$proxy], TrustedHeader::XForwardedFor);
    }

    public function testProxiesWithoutHeaders(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrustedProxies(['10.0.0.1']);
    }

    public function testRemoteAddrIsNotAnAddress(): void
    {
        $proxies = new TrustedProxies([TrustedProxies::REMOTE_ADDR, '10.0.0.1'], TrustedHeader::XForwardedFor);

        $this->assertFalse($proxies->isTrusted('203.0.113.1'));
        $this->assertTrue($proxies->isTrusted('10.0.0.1'));
    }

    public function testRemoteAddrWithoutHeaders(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrustedProxies([TrustedProxies::REMOTE_ADDR]);
    }

    public function testForwardedCannotBeMixedWithXForwarded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrustedProxies(['10.0.0.1'], TrustedHeader::Forwarded, TrustedHeader::XForwardedFor);
    }
}
