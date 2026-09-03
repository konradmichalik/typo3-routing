# The `#[Route]` attribute

```php
#[Route(path: '/api/courses/{id}', name: 'course_show', requirements: ['id' => '\d+'])]
public function show(int $id): ResponseInterface { /* … */ }
```

[`#[Route]`](../../Classes/Attribute/Route.php) on a public method of a [route controller](README.md) is what makes an endpoint exist — see [Defining routes](README.md) for the three things a controller needs. The attribute is **repeatable**, so one method can answer several paths, and it may additionally sit on the class as a [shared prefix](#class-level-prefix-route-groups).

| Parameter      | Type                    | Default   | Description                                                              |
|----------------|-------------------------|-----------|--------------------------------------------------------------------------|
| `path`         | `string`                | –         | Full request path, relative to the site base (e.g. `/api/...`). A trailing slash is tolerated in either direction unless that is switched off — see [Trailing slashes](../operating/configuration.md#trailing-slashes). |
| `methods`      | `list<string>`          | `['GET']` | Allowed HTTP methods.                                                    |
| `name`         | `?string`               | `null`    | Route name; auto-derived from service id + method when omitted.          |
| `env`          | `?string`               | `null`    | Bind the route to a top-level application context (e.g. `Development`).  |
| `requirements` | `array<string, string>` | `[]`      | Constraints by parameter name → regex (`''` = presence only). See below. |
| `priority`     | `int`                   | `0`       | Match priority; higher is matched first when paths overlap. See below.   |
| `defaults`     | `array<string, mixed>`  | `[]`      | Default values for path placeholders; a trailing placeholder with a default becomes optional. See below. |
| `schemes`      | `list<string>`          | `[]`      | Allowed URI schemes (e.g. `['https']`); empty = any scheme. A request on another scheme is redirected to the first one declared. See below. |
| `host`         | `?string`               | `null`    | Restrict the route to a specific hostname (e.g. `'api.example.com'`); null = any host. See below. |
| `description`  | `?string`               | `null`    | Human-readable summary of what the endpoint does; surfaced in `routing:debug` and the OpenAPI export. See below. |
| `caseInsensitive` | `?bool`              | `null`    | Match the path's and host's literal segments regardless of case. See below. |
| `exclusive`    | `?bool`                 | `null`    | Claim the class's own route prefix exclusively: an unmatched path under it becomes a 404 instead of falling through to a page. Class-level only — a method-level value is a build-time error. See [Exclusive class-level claim](route-groups.md#exclusive-class-level-claim). |
| `canonical`    | `?bool`                 | `null`    | Redirect a request that only matched a tolerated variant of this path (trailing slash, or case) to the declared path with a `308`. See [Redirecting instead of tolerating](#redirecting-instead-of-tolerating). |
| `sites`        | `?list<string>`         | `null`    | Site identifiers this route is reachable from; out of scope answers a 404. See [Site- and language-bound routes](#site--and-language-bound-routes). |
| `languages`    | `?list<int>`            | `null`    | Language ids this route is reachable in; out of scope answers a 404. See [Site- and language-bound routes](#site--and-language-bound-routes). |
| `legacyPaths`  | `list<string>`          | `[]`      | Old paths (e.g. from before a rename) that still reach this same route. See [Legacy paths for renamed routes](#legacy-paths-for-renamed-routes). |
| `legacyAlias`  | `bool`                  | `false`   | When true, every path in `legacyPaths` answers directly instead of redirecting. See [Legacy paths for renamed routes](#legacy-paths-for-renamed-routes). |

Each parameter that needs more than a table row has its own section below. What is *not* on this page: how the controller method's signature is fed from the request — type coercion, enums, entity binding, variadics and `#[Param]` — which is [Typed controller arguments](arguments.md).

## Priority

When a static path and a placeholder path can both match the same URL, the one with the higher `priority` wins. Give the more specific route the higher value:

```php
#[Route(path: '/api/item/new', name: 'item_new', priority: 10)]
public function new(): ResponseInterface { /* … */ }

#[Route(path: '/api/item/{id}', name: 'item_show', requirements: ['id' => '\d+'])]
public function show(int $id): ResponseInterface { /* … */ }
```

`priority` affects match order only; `routing:debug` and URL generation are unaffected. (Often unnecessary — a `requirements` constraint like `['id' => '\d+']` already keeps `/api/item/new` from matching the `{id}` route.)

## Defaults (optional placeholders)

A `defaults` entry supplies a value for a path placeholder. When the placeholder is **trailing**, this makes the segment optional: the shorter path matches too and the default is passed to your controller.

```php
// Both /api/blog and /api/blog/{page} match. Without a page, $page arrives as 1.
#[Route(path: '/api/blog/{page}', name: 'blog', requirements: ['page' => '\d+'], defaults: ['page' => 1])]
public function blog(int $page): ResponseInterface { /* … */ }
```

The default flows through everywhere the placeholder does: it is available as a request attribute, resolved into the matching controller argument, and used by URL generation — `{routing:uri(route: 'blog')}` produces `/api/blog`, while `{routing:uri(route: 'blog', parameters: {page: 5})}` produces `/api/blog/5`.

Keys starting with `_` are reserved for internal metadata and are rejected at build time.

Instead of repeating the placeholder name in `defaults`, a [`#[Param]`](arguments.md#declaring-the-constraint-at-the-parameter) on the parameter contributes its PHP default — `public function blog(#[Param] int $page = 1)` makes the trailing `{page}` optional without a `defaults` entry.

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

This is not opt-in and not tied to `canonical`: declaring `schemes` states which scheme the route answers on, so the correct target is unambiguous. Two caveats:

- A route that is invisible in the current context — wrong `env`, out of [site or language scope](#site--and-language-bound-routes) — still answers `404`, since a redirect must never reveal it. So does a **host** mismatch: unlike a scheme, there is no single right host to send the client to.
- The port is carried over unchanged, since nothing here knows which port the other scheme listens on.
- The scheme comes from the request URI, which TYPO3 itself derives proxy-aware (`reverseProxySSL`, or `reverseProxyIP` plus the `X-Forwarded-Proto` header). A TLS-terminating proxy that is *not* declared in that configuration makes TYPO3 believe every request is plain `http`, and this redirect then loops. That configuration was always required for `schemes` to match correctly — the symptom just changes from a permanent `404` to a redirect loop.

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
public function status(): ResponseInterface { /* … */ }
```

There is no `hosts` (plural) parameter — a route has exactly one `host` pattern, matching Symfony's own `Route` API. To accept a fixed set of exact hostnames, match the whole host with a placeholder and constrain it with an alternation:

```php
#[Route(path: '/api/status', name: 'multi_host_status', host: '{host}', requirements: ['host' => 'api\.example\.com|admin\.example\.com'])]
public function status(): ResponseInterface { /* … */ }
```

Not inherited from a class-level `#[Route]` — same rule as `methods`.

## Description

A human-readable summary of what the endpoint does — not derived from PHPDoc, deliberately: an explicit attribute parameter is the honest contract, whereas parsing docblocks at compile time would tie route metadata to comment formatting.

```php
#[Route(path: '/api/courses/{id}', name: 'course_show', description: 'Fetch a single course by its numeric ID, including schedule and availability.')]
public function show(int $id): ResponseInterface { /* … */ }
```

It shows up in `routing:debug` (truncated in the table, full in `--json` and the detail view) and in the OpenAPI export (see [OpenAPI](../operating/openapi.md)): a description with more than one sentence has its first sentence split off as the operation `summary`, the full text stays the `description`.

## Deprecating a route

`#[DeprecatedRoute]` is a separate, non-repeatable attribute — not a `#[Route]` parameter — so it stays optional and out of the way for routes that never need it:

```php
#[Route(path: '/api/v1/courses', name: 'courses_v1')]
#[DeprecatedRoute(since: '2026-03-01', sunset: '2026-12-31', successor: 'courses_v2', documentation: 'https://example.com/migrate-to-v2')]
public function indexV1(): ResponseInterface { /* … */ }
```

| Parameter       | Type          | Default | Description |
|-----------------|---------------|---------|--------------|
| `since`         | `string`      | –       | When the route became deprecated. Any format `DateTimeImmutable` accepts (e.g. `'2026-03-01'`); rejected at build time if unparseable. |
| `sunset`        | `?string`     | `null`  | When the route stops being supported, same format as `since`. Must not precede `since` — rejected at build time, naming the route. |
| `successor`     | `?string`     | `null`  | Route name replacing this one, resolved through `RouteUrlGenerator`. An unknown route name fails the build. |
| `documentation` | `?string`     | `null`  | URL with migration guidance. |

Every response the route produces — success, a cached hit, a conditional `304`, or any `4xx` further down the gauntlet — carries:

| Header | Format | Note |
|--------|--------|------|
| `Deprecation` | `@1772323200` | [RFC 9745](https://www.rfc-editor.org/rfc/rfc9745) section 2 — an Item Structured Field Date, `@` plus a Unix timestamp. **Never** an HTTP-date; early drafts of the RFC used one, and that is the standard implementation mistake. |
| `Sunset` | `Thu, 31 Dec 2026 23:59:59 GMT` | [RFC 8594](https://www.rfc-editor.org/rfc/rfc8594) section 3 — an HTTP-date, a different format for historical reasons. Omitted when `sunset` is not given. |
| `Link: …; rel="successor-version"` | — | Present when `successor` is given. |
| `Link: …; rel="deprecation"` | — | Present when `documentation` is given. Both `Link` values ride in the same header when both are given. |

The same declaration also sets `deprecated: true` on the OpenAPI operation, and shows in `routing:debug` (detail view and `--json`; `--deprecated` filters the table to only these routes).

At class level, `#[DeprecatedRoute]` applies to every method route without its own — same rule as [`#[Cors]`](../features/cors.md#per-route-overrides-with-cors) and `description` above; a method's own attribute wins entirely rather than merging field by field. There is no `410 Gone` after `sunset` passes: [RFC 9745](https://www.rfc-editor.org/rfc/rfc9745) is explicit that deprecation is a hint, and turning an endpoint off has to stay a deliberate, separate act.

## Case-insensitive paths

URL paths are case-sensitive by [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986#section-6.2.2.1), and that is the default here. A single route can opt into case-insensitive matching, for instance when a legacy client or a hand-typed URL varies the casing:

```php
#[Route(path: '/api/courses/{slug}', name: 'course_show_slug', caseInsensitive: true)]
public function show(string $slug): ResponseInterface { /* … */ }
```

```text
/api/courses/Intro-To-Php  →  match, $slug = 'Intro-To-Php'
/API/Courses/Intro-To-Php  →  match, $slug = 'Intro-To-Php'
/ApI/CoUrSeS/Intro-To-Php  →  match, $slug = 'Intro-To-Php'
```

Three things this deliberately does **not** do:

- **Placeholder values are never folded.** The tolerance covers the path's literal segments only, so `{slug}` reaches the controller exactly as it was sent. Lower-casing the whole path would silently corrupt identifiers.
- **`requirements` stay case-sensitive.** `['slug' => '[a-z-]+']` still rejects `Intro-To-Php`, opted in or not. The tolerance is about finding the route, never about relaxing its constraints. Since the client just sees a `404`, [`routing:match`](../operating/commands.md#routingmatch) reports this case separately instead of claiming no route matched.
- **No redirect is issued by default.** Both forms answer directly, exactly as with [trailing slashes](../operating/configuration.md#trailing-slashes). Add `#[Route(canonical: true)]` to redirect a differently-cased request to the declared path instead, see [Redirecting instead of tolerating](#redirecting-instead-of-tolerating).

Generated URLs (`{routing:uri(...)}`, `RouteUrlGenerator`) always use the declared casing.

Nothing is opted in by default, and the extra matching pass runs only after the exact path has already failed, so routes that do not use it are unaffected.

The same opt-in applies to [`host`](#host)'s literal labels: a route combining `caseInsensitive: true` with a `host` constraint also accepts mixed-case host input. Host placeholders and their `requirements` are unaffected, same as for path placeholders above.

## Redirecting instead of tolerating

Answering both forms directly is right for API clients, who should not pay a second round trip. For a route serving HTML or a download, the same tolerance produces two URLs for one resource, which fragments caches and splits search ranking. `#[Route(canonical: true)]` opts a route into the other behaviour: a request that only matched a tolerated variant (trailing slash, or case via `caseInsensitive`) gets a `308 Permanent Redirect` to the declared path instead of the response.

```php
#[Route(path: '/downloads/report', name: 'report', canonical: true)]
```

```text
/downloads/report      →  served directly
/downloads/report/     →  308 → /downloads/report
```

`308` rather than `301` or `302`, because it preserves the request method and body — a tolerated `POST` is never silently downgraded to `GET`. A route with placeholders redirects to the concrete resolved path, never to the `{id}` template, and the query string carries over unchanged. `405` still wins over the redirect: a path that matches with the wrong method answers `405` regardless of which variant it was. Nullable and class-level inheritance work exactly like `caseInsensitive`. Not opting in (the default) keeps today's behaviour: both forms answered directly, no redirect.

## Legacy paths for renamed routes

Renaming a route's path breaks any client that hardcoded the old one. `#[Route(legacyPaths:)]` keeps the old path reachable through the same route:

```php
#[Route(path: '/api/courses', name: 'courses_index', legacyPaths: ['/api/course-search/count'])]
```

By default a legacy path `308`s to the declared path — the same mechanism and the same reasoning as `canonical` above, but the decision is governed by its own `legacyAlias` flag, independent of whether the route separately opted into `canonical`:

```text
/api/courses                     →  served directly
/api/course-search/count         →  308 → /api/courses
```

For a client that cannot follow redirects, `legacyAlias: true` answers every legacy path directly instead:

```php
#[Route(path: '/api/courses', name: 'courses_index', legacyPaths: ['/api/course-search/count'], legacyAlias: true)]
```

A legacy path is never canonical: it is invisible to `routing:debug`'s route list and the OpenAPI export (both list only the declared path; `routing:debug <name>` shows the legacy paths in the detail view of the route that owns them), and URL generation (`{routing:uri()}`, `RouteUrlGenerator`) never produces one. It costs nothing for a route that declares none — matching only tries the legacy paths once every other tier has already failed, mirroring the case-insensitive fallback. `405` beats the redirect exactly as it does for a tolerated variant, and a legacy path may not collide with any declared route path or with another route's legacy path — either fails the container build. A site/language-scoped route stays out of scope through its legacy path too. Combined with [`#[DeprecatedRoute]`](#deprecating-a-route), a request that reaches the route through its old path carries the same `Deprecation`/`Sunset` headers as any other request to that route, since the dispatcher resolves both to the same route identity.

Legacy paths do not inherit the owning route's [`caseInsensitive`](#case-insensitive-paths) tolerance — they match exactly as declared, and are not inherited from a class-level `#[Route]` (same rule as `methods`).

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

## Class-level prefix (route groups)

The attribute may also sit on the **controller class**, where its `path` and `name` prefix every method route and most other parameters supply a default. That has its own page: [Route groups](route-groups.md).

## Requirements

`requirements` constrains parameters by name, with two enforcement layers depending on where the parameter lives:

- **Path placeholders** (a name that appears as `{name}` in the path) are enforced by the **matcher**: a violating path is treated as no match → **404**.
- **Any other name** is a required **query or POST-body** parameter, validated at **dispatch**: missing or format-violating → **400**, before your controller runs. (`''` means presence only.)

A constraint can equivalently be written on the parameter itself with [`#[Param(requirement:)]`](arguments.md#declaring-the-constraint-at-the-parameter) — which additionally allows an *optional but constrained* parameter.

```php
#[Route(
    path: '/api/item/{id}',
    name: 'item_show',
    // {id} → matcher (404 if not digits); q → required query/body param (400 if missing or not digits)
    requirements: ['id' => '\d+', 'q' => '\d+'],
)]
public function show(int $id, int $q): ResponseInterface
{
    // $id and $q arrive type-cast and validated — no manual reading from the request.
    // …
}
```

### Named requirement patterns

Common patterns are available as named constants on Symfony's `Requirement` enum — already bundled with this extension via `symfony/routing`, so there is nothing extra to install:

```php
use KonradMichalik\Typo3Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(
    path: '/api/item/{id}',
    name: 'item_show',
    requirements: ['id' => Requirement::DIGITS],
)]
```

| Constant                 | Matches                                            |
|--------------------------|----------------------------------------------------|
| `Requirement::DIGITS`    | One or more digits (`0`, `42`, `007`).             |
| `Requirement::POSITIVE_INT` | A positive integer without leading zeros (`1`, `42`). |
| `Requirement::ASCII_SLUG`   | A hyphenated ASCII slug (`my-article-title`).   |
| `Requirement::UUID`      | Any RFC 4122 UUID.                                 |
| `Requirement::UID_BASE58`   | A base58-encoded UID (e.g. a Symfony `Ulid`/`Uuid` in short form). |
| `Requirement::DATE_YMD`  | A `YYYY-MM-DD` date.                               |
| `Requirement::CATCH_ALL` | Everything, including slashes (`.+`).              |

Any plain regex string still works, so the enum is opt-in and freely mixable: `['id' => Requirement::DIGITS, 'q' => '']`.

## Error responses from controllers

Throw `HttpProblemException` to answer with the same [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem-details format the dispatcher uses for its own errors (`404`, `405`, `400`, …):

```php
use KonradMichalik\Typo3Routing\Http\HttpProblemException;

#[Route(path: '/api/orders/{id}/cancel', methods: ['POST'], name: 'order_cancel')]
public function cancel(Order $order): ResponseInterface
{
    if ($order->isShipped()) {
        throw new HttpProblemException(409, 'Order has already been shipped');
    }
    // …
}
```

The dispatcher maps it to `application/problem+json` — status `409`, title `Conflict`, and the message as `detail` (omitted when it only repeats the title). Only 4xx/5xx status codes are accepted; anything else raises a runtime `LogicException` when the exception is constructed.

Every **other** exception stays untouched and reaches TYPO3's regular error handling (and its logging) as before — `HttpProblemException` is for *expected* error outcomes, not a replacement for exception handling. The one exception to that: a controller method declaring a bare `JsonResponse` return type opts in to automatic JSON errors, described next.

### Automatic JSON errors for `JsonResponse` routes

A controller method whose declared return type is a bare, non-nullable `\TYPO3\CMS\Core\Http\JsonResponse` — no explicit opt-in needed — gets every otherwise-uncaught exception converted to a generic `500` `application/problem+json` response instead of reaching TYPO3's regular (HTML) error handling:

```php
use TYPO3\CMS\Core\Http\JsonResponse;

#[Route(path: '/api/orders/{id}', name: 'order_show')]
public function show(Order $order): JsonResponse
{
    // Any exception thrown here — one nobody anticipated — still answers as JSON.
    return new JsonResponse($order->toArray());
}
```

The response body never includes the exception's own message or a stack trace, regardless of application context — only the fixed `title: 'Internal Server Error'` — since an unanticipated exception's detail is unvetted and possibly sensitive. `HttpProblemException`, `ArgumentResolutionException` (unresolvable argument) and a missing entity are still mapped to their own specific status exactly as before; this only adds a catch-all for everything else.

A nullable (`?JsonResponse`) or union return type does **not** opt in: the method can legitimately answer with something other than JSON, so guessing "JSON errors" for it would be wrong as often as it is right. A method with no declared return type is likewise unaffected.
