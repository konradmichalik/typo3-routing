# Rate Limiting

Add the optional `#[RateLimit]` attribute next to a `#[Route]` to throttle requests per client IP:

```php
use KonradMichalik\Typo3Routing\Attribute\RateLimit;
use KonradMichalik\Typo3Routing\Attribute\Route;

#[Route(path: '/api/contact', methods: ['POST'], name: 'contact_submit')]
#[RateLimit(limit: 5, interval: '1 minute', policy: 'sliding_window')]
public function submit(ServerRequestInterface $request): ResponseInterface
{
    // …
}
```

| Parameter  | Type     | Default            | Description                                                                 |
|------------|----------|--------------------|-----------------------------------------------------------------------------|
| `limit`    | `int`    | `60`               | Maximum number of requests allowed within the interval.                     |
| `interval` | `string` | `'1 minute'`       | Time window as a relative date string (e.g. `'1 minute'`, `'10 seconds'`).  |
| `policy`   | `string` | `'sliding_window'` | Limiter policy: `'sliding_window'` or `'fixed_window'`.                      |
| `keyBy`    | `string` | `'ip'`             | What to throttle per: `'ip'` or `'user'` (the logged-in frontend user).     |

- Built on [`symfony/rate-limiter`](https://symfony.com/doc/current/rate_limiter.html); buckets are stored in the dedicated `typo3_routing_ratelimit` cache and **survive a "Flush all caches"** (they expire on their own TTL).
- Applies to **all HTTP methods** of the route. When the limit is exceeded the dispatcher returns `429 Too Many Requests` (RFC 9457 `application/problem+json`) with a `Retry-After` header — and the same value as a `retryAfter` member in the problem body, so clients that only inspect the JSON don't need to parse headers — **before** the response cache is consulted — a cacheable response cannot bypass the limit.
- Every response from a rate-limited route — accepted or blocked — carries `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` (a Unix timestamp), so a client can see its quota without waiting for a `429`.
- The client is keyed by the resolved remote address (`normalizedParams`, so reverse-proxy headers are honoured when configured). An unsupported `policy` or `keyBy` raises a build-time exception.

### Throttling per user

`keyBy: 'user'` throttles each **logged-in frontend user** independently instead of by IP — the right choice for authenticated endpoints, where an office behind one NAT IP would otherwise share a single quota, and a single user across several IPs could otherwise slip the limit.

```php
#[Route(path: '/api/account/export', name: 'account_export')]
#[Authenticate(FrontendUserAuthenticator::class)]
#[RateLimit(limit: 5, interval: '1 hour', keyBy: 'user')]
public function export(ServerRequestInterface $request): ResponseInterface { /* … */ }
```

Because rate limiting runs **before** authentication, an anonymous request to a `keyBy: 'user'` route (one that has no `#[Authenticate]`, or before login) has no user identity and falls back to being throttled by IP. Pair `keyBy: 'user'` with `#[Authenticate]` so only authenticated calls reach the controller.

> [!NOTE]
> Behind a reverse proxy or CDN, configure `$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']` so the real client IP is used as the rate-limit key instead of the proxy address.

> [!NOTE]
> Counters are **best-effort under concurrency**: bucket updates are not cross-process locked, so parallel requests can briefly exceed the configured limit. Treat the limit as a throttle, not as an exact quota or a hard security boundary. The `typo3_routing_ratelimit` cache uses the TYPO3 default backend (database) unless overridden — for busy endpoints, point it at Redis or APCu via `$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['typo3_routing_ratelimit']` to keep the two extra storage round-trips per request off the database.
