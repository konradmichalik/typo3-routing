# Configuration

**Nothing here is required to make routes work.** The performance-critical part, the [path gate](#path-gate), is derived from your `#[Route]` paths at container build time and cannot be configured out of sync with them. Every setting below changes behaviour you may or may not want; the defaults are the behaviour most installations should keep.

All of them live under **Settings → Extension Configuration → typo3_routing**.

| Setting | Default | Documented in |
|---------|---------|---------------|
| `exclusivePrefixes` | *(empty)* | [Exclusive path prefixes](#exclusive-path-prefixes) |
| `trailingSlash` | `1` | [Trailing slashes](#trailing-slashes) |
| `bearerTokenEnvName` | `ROUTING_BEARER_TOKEN` | [Authentication](../features/authentication.md#bearertokenauthenticator) |
| `cors.allowedOrigins` | *(empty)* | [CORS](../features/cors.md#global-configuration) |
| `cors.allowedHeaders` | `Content-Type, Authorization` | [CORS](../features/cors.md#global-configuration) |
| `cors.allowCredentials` | `0` | [CORS](../features/cors.md#global-configuration) |
| `cors.exposeHeaders` | *(empty)* | [CORS](../features/cors.md#global-configuration) |
| `cors.maxAge` | `3600` | [CORS](../features/cors.md#global-configuration) |
| `swaggerUi` | `0` | [OpenAPI export](openapi.md#swagger-ui-development-only) |

## Path gate

The dispatcher only reaches the matcher for paths that could plausibly belong to a route. That gate is **derived from your `#[Route]` paths at container compile time** — the static leading segment of every path (plus its slashless form where it ends in a slash, so the [trailing-slash tolerance](#trailing-slashes) below can do its work), baked into the compiled container next to the compiled matcher. It needs no configuration and cannot drift out of sync with your routes: put an endpoint at `/webhook/stripe` and the gate covers it automatically.

A path outside the gate falls through to normal page rendering at zero cost. With no routes registered at all the gate is empty and rejects everything, so the dispatcher costs a single string comparison per page request. [Performance](../background/performance.md#traffic-that-is-not-an-api-request) puts a number on that.

> [!NOTE]
> A route whose path starts with a placeholder (e.g. `/{slug}`) has no static prefix, so it opens the gate for every path — the matcher then decides. That is unavoidable and correct: such a route can match anywhere.

Route paths in the `#[Route]` attribute are always written in full.

## Trailing slashes

A route declared as `/api/example` also answers `/api/example/`, and a path declared *with* a trailing slash equally answers the form without one — the same endpoint never needs a second `#[Route]`. Both forms return the response directly; there is no redirect, so API clients never pay a second round trip.

| Setting         | Description                                                                                  | Default |
|-----------------|----------------------------------------------------------------------------------------------|---------|
| `trailingSlash` | Tolerate a trailing slash the route did not declare (and its absence where it did). `0` matches paths strictly as declared. | `1`     |

The tolerance costs nothing in the normal case: the extra match attempt only runs once the exact path has already failed to match.

Two things stay untouched by it:

- **The declared form stays canonical.** Generated URLs ([`{routing:uri()}`, `RouteUrlGenerator`](../routes/url-generation.md)) keep exactly the form you wrote, and so do the route exports that report it — [`routing:debug`](commands.md#routingdebug) and the [OpenAPI document](openapi.md).
- **`405` beats the retry.** A path that matches a route under the active `trailingSlash` policy but uses the wrong HTTP method answers `405` with its `Allow` header. With `trailingSlash: 0`, the opposite-slash form is not a path match at all and stays a plain `404`, not a `405`.

Case is a separate matter and has no global switch: a route opts into it individually with `#[Route(caseInsensitive: true)]`, see [Case-insensitive paths](../routes/route-attribute.md#case-insensitive-paths).

## Exclusive path prefixes

Separate from the gate, you can reserve path spaces **exclusively** for attribute routes. Inside them a path matching no route returns a JSON `404` instead of falling through to page rendering.

| Setting             | Description                                                                                       | Default   |
|---------------------|---------------------------------------------------------------------------------------------------|-----------|
| `exclusivePrefixes` | Comma-separated list of path spaces reserved for the API, e.g. `/api/, /va/`. **Empty disables it.** | *(empty)* |

This is **not** needed to make routes work — only to control what happens to unmatched paths:

| Request                                    | `exclusivePrefixes` empty (default) | `exclusivePrefixes = /api/` |
|--------------------------------------------|-------------------------------------|-----------------------------|
| `/api/example` (matches a route)           | dispatched                          | dispatched                  |
| `/api/typo` (matches nothing)              | page rendering → TYPO3 404 page     | JSON `404`                  |
| `/api/item/abc` (violates a requirement)   | page rendering → TYPO3 404 page     | JSON `404`                  |
| `/some/page`                               | page rendering                      | page rendering              |

A path that matches a route's shape but the wrong HTTP method always gets a hard `405` regardless, since that path was deliberately claimed by that route.

Reach for `exclusivePrefixes` when API clients should receive machine-readable errors for mistyped endpoints. Leave it empty when your routes coexist with ordinary pages.

## Environment-bound routes

A route with `env: 'Development'` only exists while the top-level application context matches (case-insensitive). Outside that context the route behaves as if it does not exist (`404`) — no ExpressionLanguage, just a match-time check against `Environment::getContext()`.

```php
#[Route(path: '/api/debug/dump', name: 'debug_dump', env: 'Development')]
public function dump(ServerRequestInterface $request): ResponseInterface { /* … */ }
```

A class-level `#[Route(env:)]` supplies the default for every method route that does not set its own, so a whole debug controller can be bound to one context at once.

## Middleware placement

The dispatcher middleware runs in the **frontend** stack, **after** `typo3/cms-frontend/site` (it needs the resolved site/language context) and after both auth middlewares — `typo3/cms-frontend/backend-user-authentication` and `typo3/cms-frontend/authentication` — and **before** `typo3/cms-frontend/page-resolver`.

This default covers every built-in [authenticator](../features/authentication.md): the `frontend.user` / `backend.user` context aspects and the request token in the `SecurityAspect` (provided by the core request-token middleware, which runs even earlier) are all populated before the dispatcher's access checks.

> [!NOTE]
> A purely public or Bearer-only setup needs neither auth middleware. For a marginally earlier short-circuit — as long as no route uses the FE/BE-user authenticators — disable the dispatcher's own middleware (`konradmichalik/typo3-routing/dispatcher`) in your `Configuration/RequestMiddlewares.php` and re-register it under a new identifier with only `'after' => ['typo3/cms-frontend/site']`. Redefining `after` on the existing identifier does not work: TYPO3 merges middleware definitions recursively, so the original auth-middleware constraints would still apply alongside whatever you add.
