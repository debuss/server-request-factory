<?php declare(strict_types=1);

namespace Debuss\ServerRequestFactory;

/**
 * Headers that trusted proxies are allowed to set.
 *
 * Only trust the headers your proxy actually sets (or overrides): a header it lets through untouched can be forged by
 * the client. For the same reason, Forwarded cannot be combined with the X-Forwarded-* headers.
 */
enum TrustedHeader
{

    /** Forwarded (RFC 7239), gives the client IP, scheme and host */
    case Forwarded;

    /** X-Forwarded-For, gives the client IP */
    case XForwardedFor;

    /** X-Forwarded-Proto, gives the scheme */
    case XForwardedProto;

    /** X-Forwarded-Host, gives the host, and the port if any */
    case XForwardedHost;

    /** X-Forwarded-Port, gives the port */
    case XForwardedPort;
}
