# Route features

Everything on this page is **opt-in**. A route that declares none of these attributes is public, uncached, unthrottled and same-origin by default — which is the right default for most endpoints and costs nothing at runtime, because each feature is gated behind a registry lookup that returns `null` when the route did not declare it. The [global CORS configuration](cors.md#global-configuration) can still allow cross-origin access for every route, declared or not; a route's own [`#[Cors]`](cors.md#per-route-overrides-with-cors) replaces that global policy entirely rather than adding to it.

Each one is a separate attribute stacked next to the `#[Route]`:

```php
#[Route(path: '/api/account/export', methods: ['POST'], name: 'account_export')]
#[Authenticate(FrontendUserAuthenticator::class)]
#[RequireRequestToken]
#[RateLimit(limit: 5, interval: '1 hour', keyBy: 'user')]
public function export(ServerRequestInterface $request): ResponseInterface { /* … */ }
```

| Page | Attribute | What it does |
|------|-----------|--------------|
| [Authentication & CSRF](authentication.md) | `#[Authenticate]`, `#[RequireRequestToken]` | Require a bearer token, an FE user or a BE user; verify TYPO3's request token on state-changing calls |
| [Caching](caching.md) | `#[Cache]` | Cache successful `GET` responses, invalidated by cache tag or lifetime |
| [Rate limiting](rate-limiting.md) | `#[RateLimit]` | Throttle per client IP or per logged-in user |
| [CORS](cors.md) | `#[Cors]` | Allow cross-origin browser access, globally or per route |

## Where they take effect

They are not independent knobs applied in arbitrary order. The dispatcher runs them at fixed points, and two of those positions carry a security decision:

| Order | Feature | Rejects with |
|-------|---------|--------------|
| 1 | CORS preflight | answers `204` outright, before any credential is required |
| 2 | Rate limit | `429` + `Retry-After` |
| 3 | Authentication | `401` |
| 4 | Request token (CSRF) | `403` |
| 5 | Response cache, then the controller | — |

- **Rate limiting before authentication**, so a coarse per-IP limit absorbs token brute-force attempts before any authentication logic runs.
- **The response cache last**, so a cacheable response cannot be used to bypass the rate limit.

The full pipeline, including matching and the environment filter, is in [How it works](../background/how-it-works.md#runtime).

## How they interact

- **`#[Authenticate]` disables `#[Cache]`.** The cache key does not vary by identity, so a shared entry could leak one client's response to another. Combining them raises a build-time warning and the cache is ignored. The [caching page](caching.md) also covers the case this safeguard cannot catch: a *public* cached route whose controller inspects the frontend-user context itself.
- **A Bearer token needs no `#[RequireRequestToken]`.** Browsers do not attach bearer tokens automatically the way they do cookies, so a cross-site request cannot forge one — bearer-protected routes are CSRF-immune. Adding it is harmless but pointless.
- **`keyBy: 'user'` wants an `#[Authenticate]` next to it.** Rate limiting runs before authentication, so an anonymous request has no user identity and falls back to being throttled by IP.
- **A per-route `#[Cors]` replaces the global CORS configuration entirely** for that route, rather than merging with it field by field.

## `#[Cors]`, `#[RateLimit]` and `#[Authenticate]` are inheritable

`#[Cors]`, `#[RateLimit]` and `#[Authenticate]` may sit on the **controller class**, where each supplies the default for every method route that does not declare its own — the same inheritance model as a [class-level `#[Route]` prefix](../routes/route-groups.md). A method's own attribute always replaces the class-level one entirely rather than merging with it field by field, so narrowing or replacing protection on a single method is always explicit. [`routing:debug --unprotected`](../operating/commands.md#filtering-and-inspecting) audits the *resolved* authenticator per route, so it reflects the class-level fallback automatically.

`#[Cache]` and `#[RequireRequestToken]` are method-only by design: a cache policy that silently applies to every method added to a class later is the wrong default, so each route restates it. PHP rejects a class-level use of them outright.
