# Usage

Implement the marker interface [`RouteControllerInterface`](../Classes/Routing/RouteControllerInterface.php) and annotate public methods with [`#[Route]`](../Classes/Attribute/Route.php). No further configuration is needed beyond registering the controller as a service (autoconfiguration in your `Configuration/Services.yaml` is sufficient).

```php
use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class CourseSearchController implements RouteControllerInterface
{
    public function __construct(/* … injected services … */) {}

    #[Route(path: '/api/course-search/count', name: 'course_search_count')]
    public function count(): ResponseInterface
    {
        return new JsonResponse(['count' => 42]);
    }
}
```

A controller method declares **only the parameters it needs** — there is no fixed signature. Type-hint `ServerRequestInterface` to receive the request; everything else is resolved by name from the route (see [Typed arguments](ARGUMENTS.md)).

## Contents

- [The `#[Route]` attribute](#the-route-attribute)
- [Priority](#priority)
- [Defaults (optional placeholders)](#defaults-optional-placeholders)
- [Schemes](#schemes)
- [Host](#host)
  - [Wildcards and multiple hosts](#wildcards-and-multiple-hosts)
- [Description](#description)
- [Case-insensitive paths](#case-insensitive-paths)
- [Class-level prefix (route groups)](#class-level-prefix-route-groups)
- [Requirements](#requirements)
  - [Named requirement patterns](#named-requirement-patterns)
- [Error responses from controllers](#error-responses-from-controllers)

How a controller method's signature is fed from the request — type coercion, enums, entity binding, variadics and `#[Param]` — is covered in [Typed controller arguments](ARGUMENTS.md).

## The `#[Route]` attribute

The attribute is repeatable. Its parameters:

| Parameter      | Type                    | Default   | Description                                                              |
|----------------|-------------------------|-----------|--------------------------------------------------------------------------|
| `path`         | `string`                | –         | Full request path, relative to the site base (e.g. `/api/...`). A trailing slash is tolerated in either direction unless that is switched off — see [Trailing slashes](CONFIGURATION.md#trailing-slashes). |
| `methods`      | `list<string>`          | `['GET']` | Allowed HTTP methods.                                                    |
| `name`         | `?string`               | `null`    | Route name; auto-derived from service id + method when omitted.          |
| `env`          | `?string`               | `null`    | Bind the route to a top-level application context (e.g. `Development`).  |
| `requirements` | `array<string, string>` | `[]`      | Constraints by parameter name → regex (`''` = presence only). See below. |
| `priority`     | `int`                   | `0`       | Match priority; higher is matched first when paths overlap. See below.   |
| `defaults`     | `array<string, mixed>`  | `[]`      | Default values for path placeholders; a trailing placeholder with a default becomes optional. See below. |
| `schemes`      | `list<string>`          | `[]`      | Allowed URI schemes (e.g. `['https']`); empty = any scheme. See below.  |
| `host`         | `?string`               | `null`    | Restrict the route to a specific hostname (e.g. `'api.example.com'`); null = any host. See below. |
| `description`  | `?string`               | `null`    | Human-readable summary of what the endpoint does; surfaced in `routing:debug` and the OpenAPI export. See below. |
| `caseInsensitive` | `?bool`              | `null`    | Match the path's and host's literal segments regardless of case. See below. |
| `exclusive`    | `?bool`                 | `null`    | Claim the class's own route prefix exclusively: an unmatched path under it becomes a 404 instead of falling through to a page. Class-level only — a method-level value is a build-time error. See [Exclusive path prefixes](CONFIGURATION.md#exclusive-path-prefixes). |

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

Instead of repeating the placeholder name in `defaults`, a [`#[Param]`](ARGUMENTS.md#declaring-the-constraint-at-the-parameter) on the parameter contributes its PHP default — `public function blog(#[Param] int $page = 1)` makes the trailing `{page}` optional without a `defaults` entry.

## Schemes

Restrict a route to one or more URI schemes — most commonly to force HTTPS-only access:

```php
#[Route(path: '/api/payment/charge', methods: ['POST'], name: 'payment_charge', schemes: ['https'])]
public function charge(): ResponseInterface { /* … */ }
```

A request over a scheme not in the list gets the same `404 Not Found` as an unmatched path — the constraint is invisible rather than producing a scheme-specific error. `{routing:uri(route: 'payment_charge')}` generates a full absolute URL (`https://…`) instead of a site-relative path when the current request's scheme differs, since a relative path cannot target a different scheme.

Not inherited from a class-level `#[Route]` — same rule as `methods`.

## Host

Restrict a route to a specific hostname — useful for a dedicated API subdomain that coexists with the main site:

```php
#[Route(path: '/v1/status', name: 'api_status', host: 'api.example.com')]
public function status(): ResponseInterface { /* … */ }
```

A request from a different host gets the same `404 Not Found` as an unmatched path. `{routing:uri(route: 'api_status')}` generates a full absolute URL when the current request's host differs, for the same reason as `schemes` above.

`host` is a **matching filter, not an authorization boundary** — use [`#[Authenticate]`](AUTHENTICATION.md) for access control. It is matched against the request's URI host, the same source TYPO3's own `trustedHostsPattern` validates upstream of this middleware.

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

It shows up in `routing:debug` (truncated in the table, full in `--json` and the detail view) and in the OpenAPI export (see [OpenAPI](HOW-IT-WORKS.md#openapi-export)): a description with more than one sentence has its first sentence split off as the operation `summary`, the full text stays the `description`.

## Case-insensitive paths

URL paths are case-sensitive by [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986#section-6.2.2.1), and that is the default here. A single route can opt out, for instance when a legacy client or a hand-typed URL varies the casing:

```php
#[Route(path: '/api/courses/{slug}', name: 'course_show', caseInsensitive: true)]
public function show(string $slug): ResponseInterface { /* … */ }
```

```text
/api/courses/Intro-To-Php  →  match, $slug = 'Intro-To-Php'
/API/Courses/Intro-To-Php  →  match, $slug = 'Intro-To-Php'
/ApI/CoUrSeS/Intro-To-Php  →  match, $slug = 'Intro-To-Php'
```

Three things this deliberately does **not** do:

- **Placeholder values are never folded.** The tolerance covers the path's literal segments only, so `{slug}` reaches the controller exactly as it was sent. Lower-casing the whole path would silently corrupt identifiers.
- **`requirements` stay case-sensitive.** `['slug' => '[a-z-]+']` still rejects `Intro-To-Php`, opted in or not. The tolerance is about finding the route, never about relaxing its constraints. Since the client just sees a `404`, [`routing:match`](HOW-IT-WORKS.md#match-simulation-command) reports this case separately instead of claiming no route matched.
- **No redirect is issued.** Both forms answer directly, exactly as with [trailing slashes](CONFIGURATION.md#trailing-slashes). If you want one canonical URL for SEO reasons, do not use this and let the other casing 404.

Generated URLs (`{routing:uri(...)}`, `RouteUrlGenerator`) always use the declared casing.

Nothing is opted in by default, and the extra matching pass runs only after the exact path has already failed, so routes that do not use it are unaffected.

The same opt-in applies to [`host`](#host)'s literal labels: a route combining `caseInsensitive: true` with a `host` constraint also accepts mixed-case host input. Host placeholders and their `requirements` are unaffected, same as for path placeholders above.

## Class-level prefix (route groups)

Placing `#[Route]` on the **controller class** turns it into a prefix shared by every method route — handy for grouping related endpoints or versioning an API (`/api/v1`, `/api/v2`). At most one class-level `#[Route]` is allowed.

```php
use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;

#[Route(path: '/api/v1/courses', name: 'v1_courses_', requirements: ['id' => '\d+'])]
final class CourseController implements RouteControllerInterface
{
    // → GET /api/v1/courses/{id}, route name "v1_courses_course_show"
    #[Route(path: '/{id}', name: 'course_show')]
    public function show(int $id): ResponseInterface { /* … */ }

    // → GET /api/v1/courses, route name "v1_courses_course_list"
    #[Route(path: '', name: 'course_list')]
    public function list(): ResponseInterface { /* … */ }
}
```

How the class-level values combine with each method:

| Parameter      | Combination                                                                                   |
|----------------|-----------------------------------------------------------------------------------------------|
| `path`         | Class path is **prepended** to each method path.                                              |
| `name`         | Class name is **prepended** to each resolved method name (auto-derived name still applies).   |
| `env`          | Used as the **default** for methods that do not set their own `env`; a method `env` wins.     |
| `requirements` | **Merged** with method requirements; the method wins per key.                                 |
| `defaults`     | **Merged** with method defaults; the method wins per key.                                     |
| `methods`      | **Ignored** at class level — the method default (`['GET']`) is indistinguishable from "unset". |
| `description`  | Used as the **default** for methods that do not set their own `description`; a method `description` wins. |
| `caseInsensitive` | Used as the **default** for methods that do not set their own value; a method can opt back out with `false`. |
| `exclusive`    | **Class-level only** — a value on a method-level `#[Route]` is a build-time error.             |

### Sharing routes through an abstract base controller

Route discovery reflects the concrete controller's public methods, including ones inherited from a parent class, so an abstract base controller can declare the route methods once while each concrete subclass supplies only its own class-level prefix.

A method path of `''` (e.g. `#[Route(path: '', name: 'course_list')]` above) needs the class-level prefix to resolve to something non-empty. Without a class prefix, `path` would resolve to the empty string, which Symfony silently normalizes to `/` — claiming the site's root ahead of TYPO3's own page rendering. The compiler pass rejects this at build time.

PHP does not carry method attributes onto an override, so overriding an inherited route method without repeating its `#[Route]` silently removes that route, and repeating `#[Route]` while dropping a modifier such as `#[Authenticate]` silently removes only that modifier. Both are caught at build time with a warning naming the overriding method and the parent method it overrides; a controller that ends up with no route at all (for example because every inherited route method was overridden this way) is warned about separately.

## Requirements

`requirements` constrains parameters by name, with two enforcement layers depending on where the parameter lives:

- **Path placeholders** (a name that appears as `{name}` in the path) are enforced by the **matcher**: a violating path is treated as no match → **404**.
- **Any other name** is a required **query or POST-body** parameter, validated at **dispatch**: missing or format-violating → **400**, before your controller runs. (`''` means presence only.)

A constraint can equivalently be written on the parameter itself with [`#[Param(requirement:)]`](ARGUMENTS.md#declaring-the-constraint-at-the-parameter) — which additionally allows an *optional but constrained* parameter.

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

Every **other** exception stays untouched and reaches TYPO3's regular error handling (and its logging) as before — `HttpProblemException` is for *expected* error outcomes, not a replacement for exception handling.
