# Configuration

## Path prefix gate

The dispatcher first checks whether the request path (after stripping the site/language base) starts with a configurable prefix. Paths outside the prefix fall through to normal page rendering at zero cost — this is a pure performance gate. Configure it via **Settings → Extension Configuration → typo3_routing**:

| Setting  | Description                                                                                     | Default  |
|----------|-------------------------------------------------------------------------------------------------|----------|
| `prefix` | Only paths starting with this are matched. Leave **empty** to match every path (see warning).   | `/api/`  |

> [!WARNING]
> Setting an **empty prefix** means every request path is matched against your routes. Any path that does not match a registered route returns `404` instead of falling through to the page router. Only use an empty prefix if attribute routes own your entire URL space.

Route paths in the `#[Route]` attribute are always written in full, including the prefix.

## Environment-bound routes

A route with `env: 'Development'` only exists while the top-level application context matches (case-insensitive). Outside that context the route behaves as if it does not exist (`404`) — no ExpressionLanguage, just a match-time check against `Environment::getContext()`.

```php
#[Route(path: '/api/debug/dump', name: 'debug_dump', env: 'Development')]
public function dump(ServerRequestInterface $request): ResponseInterface { /* … */ }
```

## Middleware placement

The dispatcher middleware runs in the **frontend** stack, **after** `typo3/cms-frontend/site` (it needs the resolved site/language context) and **before** `typo3/cms-frontend/page-resolver`.

> [!IMPORTANT]
> If an endpoint needs an authenticated `fe_user`, move the dispatcher **after** `typo3/cms-frontend/authentication` by overriding the middleware ordering in your own `Configuration/RequestMiddlewares.php`. The default placement responds before authentication runs, which is correct for public APIs but wrong for protected endpoints.
