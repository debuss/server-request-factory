<?php declare(strict_types=1);

namespace Debuss\ServerRequestFactory;

use InvalidArgumentException;

/**
 * The request sent by the client is invalid (Host, port, path, method, header, ...) and cannot be built,
 * it should be answered with a 400 Bad Request. The exception of the PSR-7 implementation, if any, is the previous one.
 */
final class BadRequestException extends InvalidArgumentException {}
