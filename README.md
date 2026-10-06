# Server Request Factory

Creates [PSR-7](https://www.php-fig.org/psr/psr-7/) server requests from the PHP globals or from arrays, with any
[PSR-17](https://www.php-fig.org/psr/psr-17/) implementation: Laminas Diactoros, Nyholm, Guzzle, Slim, ...

- Same result whatever the implementation (headers, `Host`, uploaded files, ...)
- URI built from `Host`, `HTTPS`, `REQUEST_URI`, including absolute-form and `OPTIONS *` requests
- `$_FILES` normalized into a tree of `UploadedFileInterface`, nested fields included (`docs[]`, `user[docs][cv]`)
- `Authorization` restored when Apache strips it
- Trusted proxies support (`Forwarded` or `X-Forwarded-*`) with a `client-ip` attribute
- A `BadRequestException` to answer invalid requests with a 400

## Installation

```bash
composer require debuss-a/server-request-factory
```

PHP 8.2 or later is required, along with a PSR-7 / PSR-17 implementation, for instance:

```bash
composer require nyholm/psr7
```

## Usage

The factory needs the four PSR-17 factories of your implementation:

```php
use Debuss\ServerRequestFactory\ServerRequestFactory;

// Nyholm
$psr17 = new Nyholm\Psr7\Factory\Psr17Factory();
$factory = new ServerRequestFactory($psr17, $psr17, $psr17, $psr17);

// Guzzle
$psr17 = new GuzzleHttp\Psr7\HttpFactory();
$factory = new ServerRequestFactory($psr17, $psr17, $psr17, $psr17);

// Laminas Diactoros
$factory = new ServerRequestFactory(
    new Laminas\Diactoros\ServerRequestFactory(),
    new Laminas\Diactoros\UriFactory(),
    new Laminas\Diactoros\UploadedFileFactory(),
    new Laminas\Diactoros\StreamFactory()
);

// Slim
$factory = new ServerRequestFactory(
    new Slim\Psr7\Factory\ServerRequestFactory(),
    new Slim\Psr7\Factory\UriFactory(),
    new Slim\Psr7\Factory\UploadedFileFactory(),
    new Slim\Psr7\Factory\StreamFactory()
);
```

### From the globals

```php
$request = $factory->fromGlobals();
```

The request is built from `$_SERVER`, `$_GET`, `$_COOKIE`, `$_FILES` and `php://input` for the body. The parsed body is
`$_POST`, only for a `POST` request with a form content type (`application/x-www-form-urlencoded` or
`multipart/form-data`), as PHP does. It is `null` otherwise.

Parsing other bodies (JSON, XML, ...) is out of the scope of this package, it is the job of a middleware.

### From arrays

`fromArrays()` builds the request from the given values only. It is handy for long-running runtimes (Swoole,
RoadRunner, ReactPHP, ...) and for tests:

```php
$request = $factory->fromArrays(
    server: [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/users?page=2',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'HTTP_HOST' => 'example.com',
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '203.0.113.1'
    ],
    query: ['page' => '2'],
    parsedBody: null,
    cookies: ['session' => 'abc'],
    files: [],
    body: $psr17->createStream('{"name":"John"}')
);
```

All the arguments but `$server` are optional. Scalar values of `$server` are accepted as strings (`'SERVER_PORT' => 8080`
works) and the other ones (`argv`, ...) are ignored.

## What is built

| Request part        | Source                                                                                                         |
|---------------------|----------------------------------------------------------------------------------------------------------------|
| Method              | `REQUEST_METHOD`, `GET` by default                                                                             |
| Protocol version    | `SERVER_PROTOCOL`, `1.1` by default                                                                            |
| URI                 | `HTTP_HOST` (or `SERVER_NAME` and `SERVER_PORT`), `HTTPS`, `REQUEST_URI`, `QUERY_STRING`                       |
| Headers             | `HTTP_*` and non-empty `CONTENT_*` entries                                                                     |
| `Authorization`     | `HTTP_AUTHORIZATION`, or `REDIRECT_HTTP_AUTHORIZATION`, `PHP_AUTH_USER` / `PHP_AUTH_PW`, `PHP_AUTH_DIGEST`     |
| Server params       | `$server` as given                                                                                             |
| Uploaded files      | `$files`, normalized                                                                                           |
| `client-ip`         | `REMOTE_ADDR`, or the address given by trusted proxies                                                         |

A few details:

- **Absolute-form** (`GET http://example.com/foo HTTP/1.1`): the URI comes from the request target, which takes
  precedence over the `Host` header ([RFC 9112 §3.2.2](https://www.rfc-editor.org/rfc/rfc9112#section-3.2.2)).
- **Asterisk-form** (`OPTIONS * HTTP/1.1`): the URI has no path and `getRequestTarget()` returns `*`.
- **Header names**: `$_SERVER` loses their original case (`HTTP_CONTENT_TYPE`), so the usual one is rebuilt
  (`Content-Type`). Header names are case-insensitive anyway.
- **Headers only come from `$server`**: some implementations (Slim) read the headers of the current request from the
  globals, they are removed.
- **`Host` header**: kept as received. Without one, it is built from the URI the same way for every implementation.

## Trusted proxies

Behind a load balancer or a reverse proxy, `REMOTE_ADDR` is the address of the proxy and the URI is the one the proxy
requested. The proxy gives the original values in headers, but these headers can be forged by any client, so they must
only be read when the request comes from a proxy you trust.

```php
use Debuss\ServerRequestFactory\{ServerRequestFactory, TrustedHeader, TrustedProxies};

$factory = new ServerRequestFactory(
    $psr17, $psr17, $psr17, $psr17,
    new TrustedProxies(
        ['10.0.0.0/8', '127.0.0.1', '::1'],
        TrustedHeader::XForwardedFor,
        TrustedHeader::XForwardedProto
    )
);
```

Proxies are IP addresses or CIDR ranges, IPv4 or IPv6.

### Choosing the headers

**Only trust the headers your proxy sets or overrides.** A header the proxy lets through untouched is the one the client
sent: if you trust `X-Forwarded-Host` while your proxy does not set it, any client can change the host of your URIs.

| Header                         | Gives                          |
|--------------------------------|--------------------------------|
| `TrustedHeader::Forwarded`     | Client IP, scheme and host ([RFC 7239](https://www.rfc-editor.org/rfc/rfc7239)) |
| `TrustedHeader::XForwardedFor` | Client IP                      |
| `TrustedHeader::XForwardedProto` | Scheme                       |
| `TrustedHeader::XForwardedHost`  | Host, and port if any        |
| `TrustedHeader::XForwardedPort`  | Port                         |

`Forwarded` cannot be combined with the `X-Forwarded-*` headers: a proxy usually sets one family and lets the other one
through, so the client could forge it.

Some usual setups:

```php
// nginx, with:
//   proxy_set_header Host $host;
//   proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
//   proxy_set_header X-Forwarded-Proto $scheme;
new TrustedProxies(['10.0.0.0/8'], TrustedHeader::XForwardedFor, TrustedHeader::XForwardedProto);

// AWS Application Load Balancer, the range being the one of your VPC
new TrustedProxies(['10.0.0.0/16'], TrustedHeader::XForwardedFor, TrustedHeader::XForwardedProto, TrustedHeader::XForwardedPort);

// Traefik, which overrides all the X-Forwarded-* headers by default
new TrustedProxies(
    ['172.16.0.0/12'],
    TrustedHeader::XForwardedFor,
    TrustedHeader::XForwardedProto,
    TrustedHeader::XForwardedHost,
    TrustedHeader::XForwardedPort
);

// A proxy sending the standard Forwarded header
new TrustedProxies(['10.0.0.0/8'], TrustedHeader::Forwarded);
```

### Unknown proxy addresses

Some hosts (PaaS, ...) do not give the addresses of their load balancers. `TrustedProxies::REMOTE_ADDR` trusts the
direct peer, whatever its address:

```php
new TrustedProxies([TrustedProxies::REMOTE_ADDR], TrustedHeader::XForwardedFor, TrustedHeader::XForwardedProto);
```

Only the direct peer is trusted: the addresses it gives are not, unless they match one of the other proxies. This is
very different from trusting `0.0.0.0/0`, which would trust the whole chain, including the addresses forged by the
client. Only use it when your application cannot be reached without going through the proxy.

### How values are read

- **Client IP**: `X-Forwarded-For` (or the `for` parameters of `Forwarded`) is read from right to left, as long as the
  addresses are trusted proxies. The first address that is not one is the client: the addresses a client adds on the
  left are ignored. Ports and brackets are removed (`192.0.2.60:4711`, `[2001:db8::17]:4711`). When a proxy gives
  something else than an address (`unknown`, `_hidden`), the client IP is `null`.
- **Scheme, host and port**: when a header holds several values, the one given by the outermost trusted proxy is used,
  never a value that could come from the client. A forwarded host replaces the whole authority, its port included.
- **`Host` header**: kept as received, only the URI changes.

When `REMOTE_ADDR` is not a trusted proxy, all these headers are ignored.

### Client IP

The client IP is available in the `client-ip` attribute, also without trusted proxies (it is then `REMOTE_ADDR`):

```php
$ip = $request->getAttribute(ServerRequestFactory::CLIENT_IP_ATTRIBUTE); // ?string
```

The server params are not modified: `REMOTE_ADDR` is still the address of the direct peer.

## Errors

When the request sent by the client cannot be built (invalid `Host`, port, header value, ...), a
`BadRequestException` is thrown. Answer it with a 400:

```php
use Debuss\ServerRequestFactory\BadRequestException;

try {
    $request = $factory->fromGlobals();
} catch (BadRequestException) {
    http_response_code(400);
    exit;
}
```

`BadRequestException` extends `InvalidArgumentException`. The exception thrown by the PSR-7 implementation, if any, is
available with `getPrevious()`.

Invalid values are never replaced by default ones. Some of them depend on the implementation, as they do not all
validate the same things:

| Invalid request                         | Diactoros | Nyholm   | Guzzle   | Slim     |
|-----------------------------------------|-----------|----------|----------|----------|
| Port, host, header value with a newline | 400       | 400      | 400      | 400      |
| Method (`GE T`)                         | 400       | accepted | 400      | 400      |
| Fragment in the path (`/foo#bar`)       | 400       | accepted | accepted | accepted |
| `HTTP/3`                                | 400       | accepted | accepted | 400      |

Errors that do not come from the client keep their type: an invalid `$files` structure or `TrustedProxies`
configuration throws an `InvalidArgumentException`, an unreadable uploaded file a `RuntimeException`.

## Known limitations

- **Uploaded files are copied, not moved.** PSR-17 creates uploaded files from a stream, not from a path, so
  `moveTo()` copies the stream instead of calling `move_uploaded_file()`. It is slower for big files, and the
  `is_uploaded_file()` check is lost. The temporary files are still removed by PHP at the end of the request.
- **`Forwarded` values containing a comma** inside quotes are not supported. It does not happen with the `for`,
  `proto` and `host` parameters in practice.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The test suite runs against Laminas Diactoros, Nyholm, Guzzle and Slim, see `tests/Implementation`.

## License

MIT
