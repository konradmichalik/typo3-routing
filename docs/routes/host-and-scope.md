# Hosts, schemes, sites and languages

Four [`#[Route]`](route-attribute.md) parameters narrow where a route answers: `schemes` and `host` filter the request URI, `sites` and `languages` the TYPO3 site and language the request resolved to. Out of scope means `404`, except for a scheme mismatch, which redirects.

Table of contents:

- [Schemes](#schemes)
- [Host](#host)
  - [Wildcards and multiple hosts](#wildcards-and-multiple-hosts)
- [Site- and language-bound routes](#site--and-language-bound-routes)

## Schemes

Restrict a route to one or more URI schemes — most commonly to force HTTPS-only access:

```php
#[Route(path: '/api/payment/charge', methods: ['POST'], name: 'payment_charge', schemes: ['https'])]
public function charge(): ResponseInterface { /* … */ }
```

A request over a scheme not in the list gets a `308 Permanent Redirect` to the same URL under the **first** declared scheme, provided everything else about it matched (path, method, host, requirements). Path, query string and site base survive untouched — only the scheme changes — and `308` preserves the request method and body, so a `POST` is never downgraded:

```text
http://example.com/api/payment/charge  →  308 →  https://example.com/api/payment/charge
```

This is not opt-in and not tied to `canonical`: declaring `schemes` states which scheme the route answers on, so the correct target is unambiguous. Several caveats:

- A route that is invisible in the current context — wrong `env`, out of [site or language scope](#site--and-language-bound-routes) — still answers `404`, since a redirect must never reveal it. So does a **host** mismatch: unlike a scheme, there is no single right host to send the client to.
- The port is carried over unchanged, since nothing here knows which port the other scheme listens on.
- The scheme comes from the request URI, which TYPO3 itself derives proxy-aware (`reverseProxySSL`, or `reverseProxyIP` plus the `X-Forwarded-Proto` header). A TLS-terminating proxy that is *not* declared in that configuration makes TYPO3 believe every request is plain `http`, and this redirect then loops. That configuration was always required for `schemes` to match correctly — the symptom just changes from a permanent `404` to a redirect loop.
- Combined with [`caseInsensitive`](path-tolerance.md#case-insensitive-paths): a differently-cased request that also uses the wrong scheme answers `404` instead of redirecting — the tolerance and the redirect are separate matcher tiers that are not combined with each other, the same limitation [legacy paths](route-lifecycle.md#legacy-paths-for-renamed-routes) have with `caseInsensitive`. Matching the declared case redirects normally.

A redirect does not retroactively protect the request that triggered it: it already travelled in the clear, headers and body included, so treat any credentials it carried as compromised. The `404` protected them no better; only [HSTS](https://developer.mozilla.org/docs/Web/HTTP/Headers/Strict-Transport-Security) keeps a client from sending them over `http` in the first place.

`{routing:uri(route: 'payment_charge')}` generates a full absolute URL (`https://…`) instead of a site-relative path when the current request's scheme differs, since a relative path cannot target a different scheme — the same URL the redirect points at.

Not inherited from a class-level `#[Route]` — same rule as `methods`.

## Host

Restrict a route to a specific hostname — useful for a dedicated API subdomain that coexists with the main site:

```php
#[Route(path: '/v1/status', name: 'api_status', host: 'api.example.com')]
public function status(): ResponseInterface { /* … */ }
```

A request from a different host gets the same `404 Not Found` as an unmatched path. `{routing:uri(route: 'api_status')}` generates a full absolute URL when the current request's host differs, for the same reason as `schemes` above.

`host` is a **matching filter, not an authorization boundary** — use [`#[Authenticate]`](../features/authentication.md) for access control. It is matched against the request's URI host, the same source TYPO3's own `trustedHostsPattern` validates upstream of this middleware.

### Wildcards and multiple hosts

`host` supports the same `{placeholder}` syntax as `path`, constrained via `requirements` — useful for subdomain patterns:

```php
#[Route(path: '/api/status', name: 'tenant_status', host: '{subdomain}.example.com', requirements: ['subdomain' => '\w+'])]
public function status(string $subdomain): ResponseInterface { /* … */ }
```

A host placeholder binds to a [typed controller argument](arguments.md) of the same name, exactly like a path placeholder, and is equally available as a request attribute (`$request->getAttribute('subdomain')`). Two differences follow from a host pattern having no optional tail: a PHP default on such a parameter does not make the placeholder optional (it always matches, so it stays required), and the placeholder is not exported as an OpenAPI path parameter.

There is no `hosts` (plural) parameter — a route has exactly one `host` pattern, matching Symfony's own `Route` API. To accept a fixed set of exact hostnames, match the whole host with a placeholder and constrain it with an alternation:

```php
#[Route(path: '/api/status', name: 'multi_host_status', host: '{host}', requirements: ['host' => 'api\.example\.com|admin\.example\.com'])]
public function status(): ResponseInterface { /* … */ }
```

Not inherited from a class-level `#[Route]` — same rule as `methods`.

## Site- and language-bound routes

`sites` and `languages` scope a route to a subset of the installation the same way `env` scopes it to an application context: out of scope means the route behaves as if it does not exist (`404`), checked at match time against the request's `site`/`language` attributes.

```php
#[Route(path: '/api/shop/orders', name: 'shop_orders', sites: ['shop-de', 'shop-at'])]
public function orders(): ResponseInterface { /* … */ }

#[Route(path: '/api/example/localized', name: 'example_localized', languages: [0, 1])]
public function localized(): ResponseInterface { /* … */ }
```

- **`sites`** takes site identifiers exactly as configured under `config/sites/<identifier>/config.yaml`. An identifier that names no configured site is not rejected at build time (site configuration cannot be read reliably while the container builds, and the check would go stale the moment a site is renamed) — it is reported once per distinct list, as a runtime warning, regardless of whether it happens to match the current request's site.
- **`languages`** takes language ids as configured per site.
- Both default to `null`, meaning every site/language; a class-level `#[Route]` sets the default for methods that do not set their own (same inheritance rule as `caseInsensitive`).
