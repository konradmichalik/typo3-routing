# CORS

Browser clients on a different origin need CORS headers. CORS is **off by default**. It can be switched on globally for every matched attribute route, or per route with `#[Cors]` — and a per-route attribute works even when the global configuration is entirely off. Preflight `OPTIONS` requests are answered automatically with a `204`, before authentication ever runs.

## Global configuration

Configured under **Settings → Extension Configuration → typo3_routing**; a non-empty `cors.allowedOrigins` enables the global CORS policy. A per-route `#[Cors]` attribute can enable CORS when this setting is empty.

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

## Per-route overrides with `#[Cors]`

Cross-origin access is often only needed for a handful of routes — e.g. a public widget endpoint consumed by a partner site — while the rest of the API should stay same-origin.

```php
#[Route(path: '/api/widget/data', name: 'widget_data')]
#[Cors(allowedOrigins: ['https://partner.example.org'], allowCredentials: true, maxAge: 3600)]
public function data(): ResponseInterface { /* … */ }
```

- **Precedence**: a method's own `#[Cors]` wins entirely over a class-level one, which wins entirely over the global configuration — it is not merged field by field.
- `#[Cors]` **enables CORS for that route even when the global configuration is entirely off** (`cors.allowedOrigins` empty).
- `allowedOrigins: ['*']` combined with `allowCredentials: true` is rejected at **build time** (container compile fails) — unlike the global configuration's runtime downgrade-with-warning, a per-route attribute is an explicit, deliberate choice, so a misconfiguration fails loudly instead of silently falling back.
- Preflight (`OPTIONS` + `Access-Control-Request-Method`) is answered using the resolved per-route policy: the dispatcher matches on the *intended* method (from `Access-Control-Request-Method`) to resolve the specific route — and with it, its own `#[Cors]` — before authentication ever runs.
