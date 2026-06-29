# Authentication & CSRF

> [!IMPORTANT]
> Routes are **public by default**. Add the optional `#[Authenticate]` attribute to require authentication, and `#[RequireRequestToken]` to add CSRF protection to state-changing endpoints.

## `#[Authenticate]`

`#[Authenticate]` names a [`RouteAuthenticatorInterface`](../Classes/Authentication/RouteAuthenticatorInterface.php) implementation that decides whether a request may reach the controller. Authentication is *code behind an interface* — the attribute only selects which authenticator applies and passes options through.

```php
use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Route};
use KonradMichalik\Typo3Routing\Authentication\BearerTokenAuthenticator;

#[Route(path: '/api/partner/report', name: 'partner_report')]
#[Authenticate(BearerTokenAuthenticator::class, options: ['envName' => 'PARTNER_A_TOKEN'])]
public function report(): ResponseInterface
{
    // Reached only with a valid "Authorization: Bearer <token>".
}
```

| Parameter       | Type                  | Default | Description                                                          |
|-----------------|-----------------------|---------|----------------------------------------------------------------------|
| `authenticator` | `class-string`        | –       | FQCN of a registered `RouteAuthenticatorInterface` service.          |
| `options`       | `array<string,mixed>` | `[]`    | Passed verbatim to `authenticate()`.                                 |

- The attribute is **repeatable, and multiple attributes are combined with OR** — the route is reachable as soon as *one* authenticator accepts. The real case: "a logged-in FE user **or** a valid token".
- When every authenticator rejects, the dispatcher returns `401 Unauthorized` (`{error, status}` JSON).
- A referenced class that is not a registered service, or does not implement the interface, is a **build-time error**.

> [!IMPORTANT]
> A route without `#[Authenticate]` is public. Audit your open endpoints at any time with `vendor/bin/typo3 routing:debug --unprotected`.

## Built-in authenticators

### `BearerTokenAuthenticator`

Compares `Authorization: Bearer <token>` against a secret read from a **process environment variable**. Only the variable *name* is ever stored in config — never the value.

- `options['envName']` overrides the variable name; otherwise the `bearerTokenEnvName` extension setting is used, defaulting to `ROUTING_BEARER_TOKEN`.
- **Fails closed**: an unset/empty expected token rejects every request. The comparison is constant-time (`hash_equals`). The token is never logged or echoed.
- Bearer tokens are **CSRF-immune** (a foreign origin cannot set the `Authorization` header), so they need no request token.

### `FrontendUserAuthenticator`

Passes when a frontend user is logged in (`Context` `frontend.user` aspect). Optionally constrain to groups: `options['groups']` (`list<int>`) passes only when the user is a member of at least one.

### `BackendUserAuthenticator`

Passes when a backend user is logged in (`Context` `backend.user` aspect) — useful for admin/diagnostic endpoints on the frontend.

> [!NOTE]
> `FrontendUserAuthenticator` / `BackendUserAuthenticator` only work when the dispatcher runs **after** the corresponding TYPO3 auth middleware. The default middleware placement already guarantees this (see [Configuration](CONFIGURATION.md)). A Bearer-only setup needs neither.

### Custom authenticators

Implement the interface and register the class as a service (autoconfiguration is enough), then reference it in `#[Authenticate]`:

```php
final readonly class ApiKeyAuthenticator implements RouteAuthenticatorInterface
{
    public function authenticate(ServerRequestInterface $request, array $options = []): bool
    {
        return hash_equals($this->expectedKey(), $request->getHeaderLine('X-Api-Key'));
    }
}
```

## Caching authenticated routes

Authenticated routes are **never served from the shared response cache** (the cache key does not vary by identity, so a cached entry would leak one client's response to another). The dispatcher forces `no-store` for any route with an `#[Authenticate]` attribute — combining it with `#[Cache]` raises a build-time **warning** and the cache is ignored.

## `#[RequireRequestToken]` (CSRF)

For **session-based, state-changing** endpoints (FE/BE user, `POST`/`PUT`/`PATCH`), add `#[RequireRequestToken]`. It verifies TYPO3's CSRF-like [request token](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Authentication/AuthenticationService/CSRFlikeRequestTokenHandling.html); a missing, unverifiable, or wrong-scope token yields `403 Forbidden`.

```php
#[Route(path: '/api/account/update', methods: ['POST'], name: 'account_update')]
#[Authenticate(FrontendUserAuthenticator::class)]
#[RequireRequestToken(scope: 'routing/account-update')]
public function update(ServerRequestInterface $request): ResponseInterface { /* … */ }
```

| Parameter | Type          | Default                | Description                                          |
|-----------|---------------|------------------------|------------------------------------------------------|
| `scope`   | `string\|null`| `routing/<routeName>`  | Token scope; derived from the route name when omitted. |

- Only enforced for `POST`/`PUT`/`PATCH`; safe methods are not CSRF-relevant. Putting it on a **GET-only** route is a build-time error.
- It is **redundant for Bearer-protected** routes (harmless, but unnecessary).

### Issuing the token

Render a token for the scope into the page that hosts the calling JavaScript with the `routing:requestToken` ViewHelper, then send it back in the `X-TYPO3-RequestToken` header (or the `__RequestToken` body parameter):

```html
<script>
  const token = '{routing:requestToken(scope: "routing/account-update")}';
  const url = '{routing:uri(route: \'account_update\')}';
  fetch(url, {
    method: 'POST',
    headers: { 'X-TYPO3-RequestToken': token, 'Content-Type': 'application/json' },
    body: JSON.stringify({ /* … */ }),
  });
</script>
```

Issuing the token also emits the signing nonce cookie that the verification needs — without rendering the token on the page, the client cannot produce a valid one.

## Deployment notes (Bearer)

A static Bearer token is a shared secret read from the process environment. These three things cost hours in the DDEV → production transition:

1. **TYPO3 does not load `.env` itself.** The token must be a real process environment variable.
   - DDEV: `web_environment` in `.ddev/config.yaml` (keep the secret in a gitignored `config.local.yaml`).
   - Production: a hosting/container environment variable.
2. **php-fpm `clear_env`.** fpm clears the pool environment by default (`clear_env = yes`), so `getenv()` returns `false` even though the variable is set in the container. Set `clear_env = no` or add `env[ROUTING_BEARER_TOKEN] = …` to the pool. This is the classic "works locally, breaks in production".
3. **The `Authorization` header.** Apache does not always pass it through CGI/FPM — enable `CGIPassAuth On` or add a rewrite that sets `HTTP_AUTHORIZATION`.

> [!CAUTION]
> Serve Bearer-protected endpoints over **HTTPS only** — a static token is a shared secret sent in clear text on the wire.
