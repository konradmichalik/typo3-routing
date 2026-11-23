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
| `tags`         | `list<string>` | `[]`    | Cache tags. A tag `{table}` is flushed automatically when a record of that table changes through the DataHandler, a tag `{table}_{uid}` (e.g. `tx_news_domain_model_news_5`) when that one record does. A tag may take the value of a path parameter through a placeholder (`tx_news_domain_model_news_{id}`), see [Record tags from path parameters](#record-tags-from-path-parameters). A tag the TYPO3 cache rejects (anything but letters, digits, `_`, `%`, `-` and `&`, or longer than 250 characters, a placeholder counting as 64) fails the build. |
| `ignoreParams` | `list<string>` | `[]`    | Query parameters excluded from the cache key. Mainly for routes whose controller takes the request, which otherwise vary on every query parameter (e.g. `utm_source`). A parameter a controller argument reads fails the build. |

- Only **successful (`200`) GET responses** are cached. The cache key is built from route name, host, site, language and the values the controller's arguments consume (path placeholders, query, body, read and coerced the same way the arguments are resolved, minus `ignoreParams`). Host, site and language variants are cached separately and two sites never share a cache entry. Path variants the matcher accepts for one route (trailing slash, case, repeated leading slashes) share one entry.
- Consumed values are keyed in the form the controller receives them, so `?page=1`, `?page=01` and `?page=0001` on `int $page`, `?flag=1` and `?flag=true` on `bool $flag`, or a missing, empty and explicit default value share one entry. An entity argument is keyed on its identifier and never loaded for the key.
- An invalid value (`?page=abc` on `int $page`, a missing required parameter) bypasses the cache: nothing is read or written, so the request answers its `400` as without `#[Cache]`, and the response carries `X-TYPO3-API-Cache: MISS`.
- An undeclared parameter (`?cb=123`) never creates a second entry. A controller that takes the request (`ServerRequestInterface $request`) may read anything, so its route varies on the raw path and on every query and body parameter except `ignoreParams`. A controller or template that reads the query some other way, e.g. through the global request, has to take the request as an argument.
- A parameter a controller argument reads (query, body, variadic or unpinned input, named by its wire name, so `q` for `#[Param(name: 'q')] string $search`) cannot be listed in `ignoreParams`, the build fails. The cache answers before arguments are resolved, so every value would get the first cached response, and an invalid value (`?page=abc` on `int $page`) the cached `200` instead of `400`. This holds with or without a [`#[Param]` value constraint](../routes/arguments.md).
- The check only sees declared arguments. A controller that takes the request can still ignore any parameter it has no argument for, which is what `ignoreParams` is for. If such a controller reads an ignored parameter from the request itself, the build cannot tell, so list only parameters it does not read.
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

- [Record tags from path parameters](#record-tags-from-path-parameters)
- [Invalidating from a write route](#invalidating-from-a-write-route)
- [ETag / conditional GET](#etag--conditional-get)
- [Cache status header](#cache-status-header)

## Record tags from path parameters

A tag may contain `{name}` placeholders bound to the route's path parameters. Each placeholder is replaced with the matched value when the response is stored, so a detail route gets one tag per record:

```php
// GET /api/news/5 → stored under the tag "tx_news_domain_model_news_5"
#[Route(path: '/api/news/{id}', name: 'news_detail', methods: ['GET'])]
#[Cache(lifetime: 3600, tags: ['tx_news_domain_model_news_{id}'])]
public function detail(int $id): ResponseInterface
{
    // …
}
```

A DataHandler change of news record 5 makes the core flush `tx_news_domain_model_news_5`, so only the entry for `/api/news/5` is dropped and every other detail entry stays cached. The core also flushes `{table}` on a change of any record of that table, so a table tag on the detail route would empty all detail entries again. Leave it off only when records are not translated: a change to a translation (uid 12, `l10n_parent` 5) makes the core flush `tx_news_domain_model_news_12`, not `tx_news_domain_model_news_5`, so the entry for `/api/news/5` would stay stale. Keep the table tag when records are translated or otherwise flushed under a different uid than the one in the path.

- Placeholders resolve from path parameters only, including a class-level `#[Route]` prefix. A placeholder that is not a path parameter of the route fails the build, also when the controller reads it from the query or body: a client could otherwise create tags at will. Host placeholders do not count either.
- A method with several `#[Route]` attributes needs the placeholder in the path of every one of them, otherwise the build fails for the route that lacks it.
- An integer-like value loses its leading zeros, the way an `int` argument receives it and the core builds `{table}_{uid}`: `/api/news/05` and `/api/news/5` share the tag `tx_news_domain_model_news_5`. A string slug like `007` therefore shares its tag with `7`, which can only cause an extra flush, never a missed one.
- A value with a character outside `A-Z`, `a-z`, `0-9`, `_` and `-`, an empty value, or one longer than 64 characters becomes `h` followed by the first 16 hex characters of its SHA-256 hash, instead of a silently altered value. Storing and flushing apply the same rule, so the same value always yields the same tag. A literal value that already has that shape (`h` and 16 lowercase hex characters) is hashed as well, so it never collides with another value's hash form.
- The build checks the static parts of the tag: `tx_news_{id}` passes, `tx.news_{id}` fails. Each placeholder counts as 64 characters toward the 250-character limit.
- `routing:debug <name>` lists the tags as declared, with their placeholders.

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
| `tags`    | `list<string>` | `#[Cache]` tags to flush. Placeholders work as in [Record tags from path parameters](#record-tags-from-path-parameters) and resolve from the write route's own path. An empty list, a placeholder that is not a path parameter of the route, or a tag the TYPO3 cache rejects (anything but letters, digits, `_`, `%`, `-` and `&`, or longer than 250 characters, a placeholder counting as 64), fails the build. A tag that no `#[Cache]` declares raises a build-time warning, because it can never match a cached response; placeholder names do not matter for that check, so `news_{uid}` counts as declared by `news_{id}`. |

- A write route on `/api/news/{id}` with `#[InvalidatesCache(tags: ['tx_news_domain_model_news_{id}'])]` flushes only the entry of the record it wrote, the same one the detail route above stored.
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
