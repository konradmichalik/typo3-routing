# Caching

Add the optional `#[Cache]` attribute next to a `#[Route]` to cache the response — the controller stays unaware of caching:

```php
use KonradMichalik\Typo3Routing\Attribute\Cache;
use KonradMichalik\Typo3Routing\Attribute\Route;

// e.g. GET /api/news?page=2&search=foo
//   → cached per "page", but the volatile "search" query parameter is excluded from the cache key
#[Route(path: '/api/news', name: 'news_list')]
#[Cache(lifetime: 3600, tags: ['tx_news_domain_model_news'], ignoreParams: ['search'])]
public function list(int $page = 1): ResponseInterface
{
    // $page ← ?page=… query param, defaults to 1 (see Typed controller arguments in Usage)
    // …
}
```

| Parameter      | Type           | Default | Description                                                                       |
|----------------|----------------|---------|-----------------------------------------------------------------------------------|
| `lifetime`     | `int`          | `86400` | Time to live in seconds (fallback when no tag is invalidated).                    |
| `tags`         | `list<string>` | `[]`    | Cache tags. A tag matching a table name is flushed automatically when a record of that table changes (via DataHandler). |
| `ignoreParams` | `list<string>` | `[]`    | Query parameters excluded from the cache key (e.g. an individual `search` term).  |

- Only **successful (`200`) GET responses** are cached. The cache key is built from route name, host, path, query string (minus `ignoreParams`) and language, so host/query/language variants are cached separately — in a multi-site install two sites never share a cache entry.
- A parameter with a [`#[Param]` value constraint](../routes/arguments.md) (`min`, `max`, `minLength`, `maxLength`) cannot be listed in `ignoreParams`, the build fails. The cache answers before arguments are checked, so a request with an invalid value would get the cached `200` instead of `400`.
- Invalidation rides on the TYPO3 caching framework: changing a record of a tagged table flushes the matching entries immediately; `lifetime` is the fallback. The response is stored via the TYPO3 cache backend (no extra cache layer of its own).

- Routes carrying an [`#[Authenticate]`](authentication.md) attribute are **never cached** (forced `no-store`): the cache key does not vary by identity, so a shared entry could leak one client's response to another. Combining `#[Cache]` with `#[Authenticate]` raises a build-time warning and the cache is ignored.

> [!WARNING]
> This safeguard only triggers on `#[Authenticate]`. A **public** `#[Cache]` route whose controller inspects the frontend-user context itself (e.g. via the `frontend.user` aspect) shares one cache entry across all visitors — the first user's personalized response would be served to everyone. Don't combine `#[Cache]` with identity-dependent output; protect such routes with `#[Authenticate]` (which disables the cache) instead.
- The cache is bypassed entirely — neither read nor written — for a **logged-in backend user** (so editors always see live content while previewing) and for a request sending `Cache-Control: no-store`. A plain `Cache-Control: no-cache` only skips **reading** the stored entry; the fresh response still refreshes the cache for later requests.

## Invalidating from a write route

The DataHandler hook only sees writes that go through the DataHandler. A route persisting through an Extbase repository or the QueryBuilder never reaches it, so the cached `GET` response stays stale until `lifetime` expires. Declare the tags the write route invalidates with `#[InvalidatesCache]`:

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
| `tags`    | `list<string>` | `#[Cache]` tags to flush. An empty list fails the build. |

- The tags are flushed **after** the controller returned a `2xx` response. An error response or an exception flushes nothing, since a rejected write changed nothing.
- It is a separate attribute rather than an option of `#[Cache]`, so it combines freely with `#[Authenticate]` (which disables `#[Cache]` on the same route).
- It applies to every method the route answers. A route that also answers `GET` or `HEAD` (or any method) raises a build-time warning: crawlers and browsers send those freely, so every visitor could empty the cache. Restrict the route's `methods` to the writing ones.
- [`RouteInvoker`](../background/extending.md#invoking-a-route-without-an-http-request) flushes the tags as well, because the write happened regardless of the transport.

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

Every response from a `#[Cache]`-enabled `GET` route carries an `X-TYPO3-API-Cache` header — `HIT` when served from the stored entry, `MISS` when computed fresh (including whenever the cache was bypassed, see above). Routes without `#[Cache]`, or non-`GET` requests, never receive the header — its mere presence tells a client whether caching applies at all.

> [!NOTE]
> The header is not echoed on a `304 Not Modified` response — only `ETag` is (RFC 9110 §15.4.5).
