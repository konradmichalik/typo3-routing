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
| `cors.allowCredentials`| Allow credentialed requests. With credentials the concrete origin is echoed instead of `*`.       | `0`                            |
| `cors.exposeHeaders`   | Comma-separated response headers exposed to the browser (`Access-Control-Expose-Headers`).        | *(empty)*                      |
| `cors.maxAge`          | Seconds the browser may cache the preflight result (`Access-Control-Max-Age`).                    | `3600`                         |

The allowed **methods** for a preflight are derived automatically from the route(s) matching the path (plus `OPTIONS`). An origin that is not on the allow-list simply receives no CORS headers.

> [!NOTE]
> The CORS spec forbids the `*` wildcard together with credentials. When `cors.allowCredentials` is enabled and `cors.allowedOrigins` is `*`, the extension echoes the concrete request origin instead.

## Environment-bound routes

A route with `env: 'Development'` only exists while the top-level application context matches (case-insensitive). Outside that context the route behaves as if it does not exist (`404`) — no ExpressionLanguage, just a match-time check against `Environment::getContext()`.

```php
#[Route(path: '/api/debug/dump', name: 'debug_dump', env: 'Development')]
public function dump(ServerRequestInterface $request): ResponseInterface { /* … */ }
```

## Middleware placement

The dispatcher middleware runs in the **frontend** stack, **after** `typo3/cms-frontend/site` (it needs the resolved site/language context) and after both auth middlewares — `typo3/cms-frontend/backend-user-authentication` and `typo3/cms-frontend/authentication` — and **before** `typo3/cms-frontend/page-resolver`.

This default covers every built-in [authenticator](AUTHENTICATION.md): the `frontend.user` / `backend.user` context aspects and the request token in the `SecurityAspect` (provided by the core request-token middleware, which runs even earlier) are all populated before the dispatcher's access checks.

> [!NOTE]
> A purely public or Bearer-only setup needs neither auth middleware. You may pull the dispatcher in front of them — by overriding the ordering in your own `Configuration/RequestMiddlewares.php` — for a marginally earlier short-circuit, as long as no route uses the FE/BE-user authenticators.
