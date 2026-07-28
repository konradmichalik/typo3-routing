# Configuration

## Path gate

The dispatcher only reaches the matcher for paths that could plausibly belong to a route. That gate is **derived from your `#[Route]` paths at container compile time** — the static leading segment of every path, baked into the compiled container next to the compiled matcher. It needs no configuration and cannot drift out of sync with your routes: put an endpoint at `/webhook/stripe` and the gate covers it automatically.

A path outside the gate falls through to normal page rendering at zero cost. With no routes registered at all the gate is empty and rejects everything, so the dispatcher costs a single string comparison per page request.

> [!NOTE]
> A route whose path starts with a placeholder (e.g. `/{slug}`) has no static prefix, so it opens the gate for every path — the matcher then decides. That is unavoidable and correct: such a route can match anywhere.

Route paths in the `#[Route]` attribute are always written in full.

## Exclusive path prefixes

Separate from the gate, you can reserve path spaces **exclusively** for attribute routes. Inside them a path matching no route returns a JSON `404` instead of falling through to page rendering. Configure it via **Settings → Extension Configuration → typo3_routing**:

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

## CORS

Browser clients on a different origin need CORS headers. CORS is **off by default** and applies globally to every matched attribute route once at least one origin is configured (via **Settings → Extension Configuration → typo3_routing**). Preflight `OPTIONS` requests are answered automatically with a `204`.

| Setting                | Description                                                                                       | Default                        |
|------------------------|---------------------------------------------------------------------------------------------------|--------------------------------|
| `cors.allowedOrigins`  | Comma-separated allowed origins, or `*` for any. **Empty disables CORS.**                         | *(empty)*                      |
| `cors.allowedHeaders`  | Comma-separated request headers a client may send (`Access-Control-Allow-Headers`).               | `Content-Type, Authorization`  |
| `cors.allowCredentials`| Allow credentialed requests. Requires an explicit origin list (ignored with `*`, see below).      | `0`                            |
| `cors.exposeHeaders`   | Comma-separated response headers exposed to the browser (`Access-Control-Expose-Headers`).        | *(empty)*                      |
| `cors.maxAge`          | Seconds the browser may cache the preflight result (`Access-Control-Max-Age`).                    | `3600`                         |

The allowed **methods** for a preflight are derived automatically from the route(s) matching the path (plus `OPTIONS`). An origin that is not on the allow-list simply receives no CORS headers.

> [!WARNING]
> `cors.allowCredentials` only takes effect with an **explicit** origin list. Combined with the `*` wildcard it is ignored (a PHP warning is logged): reflecting arbitrary origins with `Access-Control-Allow-Credentials: true` would let **any** website read authenticated API responses — exactly what the CORS spec's wildcard/credentials prohibition exists to prevent.

### Per-route overrides with `#[Cors]`

Cross-origin access is often only needed for a handful of routes — e.g. a public widget endpoint consumed by a partner site — while the rest of the API should stay same-origin. `#[Cors]` overrides the global configuration entirely for that route (or, at class level, for every method route without its own):

```php
#[Route(path: '/api/widget/data', name: 'widget_data')]
#[Cors(allowedOrigins: ['https://partner.example.org'], allowCredentials: true, maxAge: 3600)]
public function data(): ResponseInterface { /* … */ }
```

- **Precedence**: a method's own `#[Cors]` wins entirely over a class-level one, which wins entirely over the global configuration — it is not merged field by field.
- `#[Cors]` **enables CORS for that route even when the global configuration is entirely off** (`cors.allowedOrigins` empty).
- `allowedOrigins: ['*']` combined with `allowCredentials: true` is rejected at **build time** (container compile fails) — unlike the global configuration's runtime downgrade-with-warning, a per-route attribute is an explicit, deliberate choice, so a misconfiguration fails loudly instead of silently falling back.
- Preflight (`OPTIONS` + `Access-Control-Request-Method`) is answered using the resolved per-route policy: the dispatcher matches on the *intended* method (from `Access-Control-Request-Method`) to resolve the specific route — and with it, its own `#[Cors]` — before authentication ever runs.

## Environment-bound routes

A route with `env: 'Development'` only exists while the top-level application context matches (case-insensitive). Outside that context the route behaves as if it does not exist (`404`) — no ExpressionLanguage, just a match-time check against `Environment::getContext()`.

```php
#[Route(path: '/api/debug/dump', name: 'debug_dump', env: 'Development')]
public function dump(ServerRequestInterface $request): ResponseInterface { /* … */ }
```

## Swagger UI (development only)

The extension can serve a Swagger UI page over its own OpenAPI export — no extra setup beyond enabling a flag. Both routes are double-gated: they only exist in the `Development` [application context](#environment-bound-routes) **and** only when explicitly enabled (via **Settings → Extension Configuration → typo3_routing**); outside either condition they behave as if they don't exist (`404`), same as any other environment-bound route.

| Setting     | Description                                                                                   | Default |
|-------------|-------------------------------------------------------------------------------------------------|---------|
| `swaggerUi` | Serve the Swagger UI (`/api/_routing/docs`) and its OpenAPI JSON document (`/api/_routing/openapi.json`). | `0` (off) |

Both are ordinary attribute routes, so the [path gate](#path-gate) covers them automatically — no configuration can accidentally make them unreachable.

> [!WARNING]
> Never enable `swaggerUi` in a production context — the `Development` gate alone already prevents this in a correctly configured instance, but the flag exists as a second, independent safeguard.

## Middleware placement

The dispatcher middleware runs in the **frontend** stack, **after** `typo3/cms-frontend/site` (it needs the resolved site/language context) and after both auth middlewares — `typo3/cms-frontend/backend-user-authentication` and `typo3/cms-frontend/authentication` — and **before** `typo3/cms-frontend/page-resolver`.

This default covers every built-in [authenticator](AUTHENTICATION.md): the `frontend.user` / `backend.user` context aspects and the request token in the `SecurityAspect` (provided by the core request-token middleware, which runs even earlier) are all populated before the dispatcher's access checks.

> [!NOTE]
> A purely public or Bearer-only setup needs neither auth middleware. You may pull the dispatcher in front of them — by overriding the ordering in your own `Configuration/RequestMiddlewares.php` — for a marginally earlier short-circuit, as long as no route uses the FE/BE-user authenticators.
