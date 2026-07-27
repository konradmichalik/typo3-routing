# Configuration

## Path prefix gate

The dispatcher first checks whether the request path (after stripping the site/language base) starts with one or more configurable prefixes. Paths outside every prefix fall through to normal page rendering at zero cost — this is a pure performance gate. Configure it via **Settings → Extension Configuration → typo3_routing**:

| Setting  | Description                                                                                                                  | Default |
|----------|-------------------------------------------------------------------------------------------------------------------------------|---------|
| `prefix` | Comma-separated list; only paths starting with one of these are matched against attribute routes. Leave **empty** to disable the gate. | `/api/` |

Use a comma-separated list to serve multiple namespaces, e.g. `/api/, /va/`.

Leaving `prefix` **empty** disables the gate: every request path is checked against your routes, at a performance cost for every page request. A path that still matches nothing falls through to normal page rendering, same as a path outside a configured prefix — so routes can declare their full path individually per controller and coexist with ordinary pages anywhere on the site. A path that matches a route's shape but the wrong HTTP method still gets a hard `405`, since that path was deliberately claimed by that route.

Route paths in the `#[Route]` attribute are always written in full, including the prefix.

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

The routes sit under the configured [path prefix](#path-prefix-gate) (`/api/` by default) so they are reachable without further changes; if you've customized `prefix` away from `/api/`, make sure it still covers `/api/_routing/` (or add it explicitly to the comma-separated list), or the request never reaches the dispatcher at all.

> [!WARNING]
> Never enable `swaggerUi` in a production context — the `Development` gate alone already prevents this in a correctly configured instance, but the flag exists as a second, independent safeguard.

## Middleware placement

The dispatcher middleware runs in the **frontend** stack, **after** `typo3/cms-frontend/site` (it needs the resolved site/language context) and after both auth middlewares — `typo3/cms-frontend/backend-user-authentication` and `typo3/cms-frontend/authentication` — and **before** `typo3/cms-frontend/page-resolver`.

This default covers every built-in [authenticator](AUTHENTICATION.md): the `frontend.user` / `backend.user` context aspects and the request token in the `SecurityAspect` (provided by the core request-token middleware, which runs even earlier) are all populated before the dispatcher's access checks.

> [!NOTE]
> A purely public or Bearer-only setup needs neither auth middleware. You may pull the dispatcher in front of them — by overriding the ordering in your own `Configuration/RequestMiddlewares.php` — for a marginally earlier short-circuit, as long as no route uses the FE/BE-user authenticators.
