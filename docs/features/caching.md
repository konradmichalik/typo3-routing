# Caching

Add the optional `#[Cache]` attribute next to a `#[Route]` to cache the response — the controller stays unaware of caching:

```php
use KonradMichalik\Typo3Routing\Attribute\Cache;
use KonradMichalik\Typo3Routing\Attribute\Route;

// e.g. GET /api/news?page=2&utm_source=mail
//   → cached per "page"; "utm_source" is no declared argument and does not vary the cache key
#[Route(path: '/api/news', name: 'news_list')]
#[Cache(lifetime: 3600, tags: ['tx_news_domain_model_news'])]
public function list(int $page = 1): ResponseInterface
{
    // $page ← ?page=… query param, defaults to 1 (see Typed controller arguments in Usage)
    // …
}
```

| Parameter      | Type           | Default | Description                                                                       |
|----------------|----------------|---------|-----------------------------------------------------------------------------------|
| `lifetime`     | `int`          | `86400` | Time to live in seconds (fallback when no tag is invalidated).                    |
| `tags`         | `list<string>` | `[]`    | Static cache tags. A tag `{table}` is flushed automatically when a record of that table changes through the DataHandler, a fixed tag `{table}_{uid}` (e.g. `tx_news_domain_model_news_5`) when that one record does. A tag cannot take a value from a path parameter, so a detail route has no per-record tag. A tag the TYPO3 cache rejects (anything but letters, digits, `_`, `%`, `-` and `&`, or longer than 250 characters) fails the build. |
| `ignoreParams` | `list<string>` | `[]`    | Query parameters excluded from the cache key. Mainly for routes whose controller takes the request, which otherwise vary on every query parameter (e.g. `utm_source`). |

- Only **successful (`200`) GET responses** are cached. The cache key is built from route name, host, site, the matched path placeholder values, the consumed query parameters (minus `ignoreParams`) and language, so host, site, query and language variants are cached separately and two sites never share a cache entry. Path variants the matcher accepts for one route (trailing slash, case, repeated leading slashes) share one entry.
- Consumed query parameters are the controller's declared query and input arguments, so an undeclared parameter (`?cb=123`) never creates a second entry. A controller that takes the request (`ServerRequestInterface $request`) may read anything, so its route varies on every query parameter except `ignoreParams`.
- A parameter with a [`#[Param]` value constraint](../routes/arguments.md) (`min`, `max`, `minLength`, `maxLength`) cannot be listed in `ignoreParams`, the build fails. The cache answers before arguments are checked, so a request with an invalid value would get the cached `200` instead of `400`.
- List only parameters the controller does not read. An ignored parameter that binds a controller argument gets the response cached for another value, and an invalid value (`?page=abc` on `int $page`) gets that cached `200` instead of `400`. The build does not catch this for unconstrained parameters.
- Invalidation rides on the TYPO3 caching framework: the response cache `typo3_routing` is in cache group `pages`, so the core DataHandler flushes it exactly like the page cache. Once its run finishes, it flushes the tags `{table}` and `{table}_{uid}` of every changed record; `lifetime` is the fallback. The response is stored via the TYPO3 cache backend (no extra cache layer of its own).
- Core rules apply to that flush: page TSconfig `TCEMAIN.clearCache_disable` on the record's page suppresses it, and a change to a workspace version flushes nothing.
- If you configure the `typo3_routing` cache yourself (e.g. a Redis backend), leave `groups` unset or include `pages` in it; without `pages` the DataHandler no longer flushes it.
- Clearing the page cache ("Flush frontend caches" in the backend, `typo3 cache:flush --group pages`) also empties the response cache, as does "Flush all caches".

- Routes carrying an [`#[Authenticate]`](authentication.md) attribute are **never cached** (forced `no-store`): the cache key does not vary by identity, so a shared entry could leak one client's response to another. Combining `#[Cache]` with `#[Authenticate]` raises a build-time warning and the cache is ignored.

> [!WARNING]
> This safeguard only triggers on `#[Authenticate]`. A **public** `#[Cache]` route whose controller inspects the frontend-user context itself (e.g. via the `frontend.user` aspect) shares one cache entry across all visitors — the first user's personalized response would be served to everyone. Don't combine `#[Cache]` with identity-dependent output; protect such routes with `#[Authenticate]` (which disables the cache) instead.
- The cache is bypassed entirely, neither read nor written, only for a **logged-in backend user**, so editors always see live content while previewing.
- **Clients cannot bypass the cache.** A request header such as `Cache-Control: no-cache` or `no-store` is ignored: the stored entry is served, and on a miss the fresh response is stored as usual. Otherwise any client could force a controller run on every request with a single header.
- To force a fresh response as an operator, flush the entry instead: through its tags (a DataHandler change of the tagged record, or a write route with [`#[InvalidatesCache]`](#invalidating-from-a-write-route)), or by clearing the page cache as described above.

- [Invalidating from a write route](#invalidating-from-a-write-route)
- [ETag / conditional GET](#etag--conditional-get)
- [Cache status header](#cache-status-header)

## Invalidating from a write route

The core flush only covers writes that go through the DataHandler. A route persisting through an Extbase repository or the QueryBuilder never reaches it, so the cached `GET` response stays stale until `lifetime` expires. Declare the tags the write route invalidates with `#[InvalidatesCache]`:

```php
use KonradMichalik\Typo3Routing\Attribute\InvalidatesCache;

#[Route(path: '/api/news', name: 'news_create', methods: ['POST'])]
#[Authenticate(FrontendUserAuthenticator::class)]
#[InvalidatesCache(tags: ['tx_news_domain_model_news'])]
public function create(string $title): JsonResponse
{
    // $this->newsRepository->add(…) and persistAll()
}
```

| Parameter | Type           | Description |
|-----------|----------------|-------------|
| `tags`    | `list<string>` | `#[Cache]` tags to flush. An empty list, or a tag the TYPO3 cache rejects (anything but letters, digits, `_`, `%`, `-` and `&`, or longer than 250 characters), fails the build. A tag that no `#[Cache]` declares raises a build-time warning, because it can never match a cached response. |

- The tags are flushed **after** the controller returned a `2xx` or `3xx` response, so a write answering with a POST-redirect-GET `303` invalidates too. An error response (`4xx`, `5xx`, including an `HttpProblemException` turned into one) flushes nothing, since a rejected write changed nothing.
- An `ImmediateResponseException` thrown from the controller propagates past the flush, so nothing is flushed in that case either. Return the response instead if the write should invalidate.
- It is a separate attribute rather than an option of `#[Cache]`, so it combines freely with `#[Authenticate]` (which disables `#[Cache]` on the same route).
- Anyone who can call the route can empty the tagged cache, as often as they like. Protect a write route with `#[Authenticate]` or at least a `#[RateLimit]`.
- It applies to every method the route answers. A route that also answers `GET` or `HEAD` (or any method) raises a build-time warning: crawlers and browsers send those freely, so every visitor could empty the cache. Restrict the route's `methods` to the writing ones.
- [`RouteInvoker`](../background/extending.md#invoking-a-route-without-an-http-request) flushes the tags as well, because the write happened regardless of the transport. An instance built by hand without the optional `CacheInvalidator` skips the flush.
- `routing:debug <name>` lists the tags under "Invalidates cache".

## ETag / conditional GET

Cached `200` GET responses automatically carry a strong `ETag` — a SHA-256 hash of the **response body**. Because it hashes the payload (not the request), the validator changes whenever the content does, e.g. after a tag flush regenerates a different response.

A client that sends the value back in an `If-None-Match` header gets a body-less `304 Not Modified` when the body is unchanged, saving bandwidth:

```text
GET /api/news                          →  200 OK        ETag: "9f2b…"
GET /api/news   If-None-Match: "9f2b…" →  304 Not Modified
```

Matching follows RFC 9110 (weak comparison, understands `*` and comma-separated lists). ETag/304 handling is scoped to cacheable routes only; uncached responses are unaffected.

> [!CAUTION]
> Only cache responses that are the **same for everyone**. `#[Cache]` is intended for public routes; `Set-Cookie` headers are never cached.

## Cache status header

Every response from a `#[Cache]`-enabled `GET` route carries an `X-TYPO3-API-Cache` header — `HIT` when served from the stored entry, `MISS` when computed fresh (including every request of a logged-in backend user, see above). Routes without `#[Cache]`, or non-`GET` requests, never receive the header — its mere presence tells a client whether caching applies at all.

> [!NOTE]
> The header is not echoed on a `304 Not Modified` response — only `ETag` is (RFC 9110 §15.4.5).
