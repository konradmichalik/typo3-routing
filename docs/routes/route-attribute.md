# The `#[Route]` attribute

```php
#[Route(path: '/api/courses/{id}', name: 'course_show', requirements: ['id' => '\d+'])]
public function show(int $id): ResponseInterface { /* … */ }
```

[`#[Route]`](../../Classes/Attribute/Route.php) on a public method of a [route controller](README.md) is what makes an endpoint exist — see [Defining routes](README.md) for the three things a controller needs. The attribute is **repeatable**, so one method can answer several paths, and it may additionally sit on the class as a [shared prefix](#class-level-prefix-route-groups).

| Parameter      | Type                    | Default   | Description                                                              |
|----------------|-------------------------|-----------|--------------------------------------------------------------------------|
| `path`         | `string`                | –         | Full request path, relative to the site base (e.g. `/api/...`). Placeholders use the plain `{name}` form — see [Inline placeholder syntax](#inline-placeholder-syntax). A trailing slash is tolerated in either direction unless that is switched off — see [Trailing slashes](../operating/configuration.md#trailing-slashes). |
| `methods`      | `list<string>`          | `['GET']` | Allowed HTTP methods.                                                    |
| `name`         | `?string`               | `null`    | Route name; auto-derived from service id + method when omitted.          |
| `env`          | `?string`               | `null`    | Bind the route to a top-level application context (e.g. `Development`).  |
| `requirements` | `array<string, string>` | `[]`      | Constraints by parameter name → regex (`''` = presence only). See [Requirements](#requirements). |
| `priority`     | `int`                   | `0`       | Match priority; higher is matched first when paths overlap. See [Priority](#priority).   |
| `defaults`     | `array<string, mixed>`  | `[]`      | Default values for path placeholders; a trailing placeholder with a default becomes optional. See [Defaults](#defaults-optional-placeholders). |
| `schemes`      | `list<string>`          | `[]`      | Allowed URI schemes (e.g. `['https']`); empty = any scheme. A request on another scheme is redirected to the first one declared. See [Schemes](host-and-scope.md#schemes). |
| `host`         | `?string`               | `null`    | Restrict the route to a specific hostname (e.g. `'api.example.com'`); null = any host. See [Host](host-and-scope.md#host). |
| `description`  | `?string`               | `null`    | Human-readable summary of what the endpoint does; surfaced in `routing:debug` and the OpenAPI export. See [Description](#description). |
| `caseInsensitive` | `?bool`              | `null`    | Match the path's and host's literal segments regardless of case. See [Case-insensitive paths](path-tolerance.md#case-insensitive-paths). |
| `exclusive`    | `?bool`                 | `null`    | Claim the class's own route prefix exclusively: an unmatched path under it becomes a 404 instead of falling through to a page. Class-level only — a method-level value is a build-time error. See [Exclusive class-level claim](route-groups.md#exclusive-class-level-claim). |
| `canonical`    | `?bool`                 | `null`    | Redirect a request that only matched a tolerated variant of this path (trailing slash, or case) to the declared path with a `308`. See [Redirecting instead of tolerating](path-tolerance.md#redirecting-instead-of-tolerating). |
| `sites`        | `?list<string>`         | `null`    | Site identifiers this route is reachable from; out of scope answers a 404. See [Site- and language-bound routes](host-and-scope.md#site--and-language-bound-routes). |
| `languages`    | `?list<int>`            | `null`    | Language ids this route is reachable in; out of scope answers a 404. See [Site- and language-bound routes](host-and-scope.md#site--and-language-bound-routes). |
| `legacyPaths`  | `list<string>`          | `[]`      | Old paths (e.g. from before a rename) that still reach this same route. See [Legacy paths for renamed routes](route-lifecycle.md#legacy-paths-for-renamed-routes). |
| `legacyAlias`  | `bool`                  | `false`   | When true, every path in `legacyPaths` answers directly instead of redirecting. See [Legacy paths for renamed routes](route-lifecycle.md#legacy-paths-for-renamed-routes). |

Parameters that need more than a table row are explained below or on their own page: [Hosts, schemes, sites and languages](host-and-scope.md), [Case-insensitive paths and canonical redirects](path-tolerance.md), [Renaming and deprecating a route](route-lifecycle.md). Returning an error from a controller is [Error responses](error-responses.md). Also not on this page: how the controller method's signature is fed from the request — type coercion, enums, entity binding, variadics and `#[Param]` — which is [Typed controller arguments](arguments.md).

Table of contents:

- [Requirements](#requirements)
  - [Named requirement patterns](#named-requirement-patterns)
  - [Inline placeholder syntax](#inline-placeholder-syntax)
- [Priority](#priority)
- [Defaults (optional placeholders)](#defaults-optional-placeholders)
- [Description](#description)
- [Matched route name](#matched-route-name)
- [Class-level prefix (route groups)](#class-level-prefix-route-groups)

## Requirements

`requirements` constrains parameters by name, with two enforcement layers depending on where the parameter lives:

- **Path placeholders** (a name that appears as `{name}` in the path) are enforced by the **matcher**: a violating path is treated as no match → **404**.
- **Any other name** is a required **query or POST-body** parameter, validated at **dispatch**: missing or format-violating → **400**, before your controller runs. (`''` means presence only.) When both the query and the body carry the name, each value must match.

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

### Inline placeholder syntax

Symfony's inline placeholder forms are deliberately **not** supported and are rejected at container build time:

| Inline form   | What it means in Symfony                                              | Write instead                                                                             |
| ------------- | --------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `{id<\d+>}`   | Inline requirement                                                    | `requirements: ['id' => '\d+']`                                                            |
| `{page?1}`    | Inline default                                                        | `defaults: ['page' => 1]`                                                                 |
| `{!page}`     | "Important" parameter, always kept in generated URLs                  | No equivalent — drop the `!`                                                                |
| `{user:id}`   | Inline entity mapping                                                 | Type-hint the entity on the parameter, see [Typed controller arguments](arguments.md)     |

They compile and match, but argument binding only recognises the plain `{name}` form, so the controller argument would silently be read from the query or body instead of the path. Rather than parse the same information a second time, a path containing one raises a `LogicException` naming the route and the offending fragment. Every entry in [`legacyPaths`](route-lifecycle.md#legacy-paths-for-renamed-routes) is checked the same way, since a legacy path becomes a route of its own and binds its arguments through the same resolver. For a container compiled before this guard existed, [`routing:lint`](../operating/commands.md#routinglint) reports the same thing as `unsupported-placeholder-syntax` without a rebuild.

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

A default for a name that is no path or host placeholder only becomes a request attribute. It does not exempt a query or body input of the same name from its requirement: a present value is still checked, and only an absent one is accepted.

Instead of repeating the placeholder name in `defaults`, a [`#[Param]`](arguments.md#declaring-the-constraint-at-the-parameter) on the parameter contributes its PHP default — `public function blog(#[Param] int $page = 1)` makes the trailing `{page}` optional without a `defaults` entry.

## Description

A human-readable summary of what the endpoint does — not derived from PHPDoc, deliberately: an explicit attribute parameter is the honest contract, whereas parsing docblocks at compile time would tie route metadata to comment formatting.

```php
#[Route(path: '/api/courses/{id}', name: 'course_show', description: 'Fetch a single course by its numeric ID, including schedule and availability.')]
public function show(int $id): ResponseInterface { /* … */ }
```

It shows up in `routing:debug` (truncated in the table, full in `--json` and the detail view) and in the OpenAPI export (see [OpenAPI](../operating/openapi.md)): a description with more than one sentence has its first sentence split off as the operation `summary`, the full text stays the `description`.

## Matched route name

A controller that takes the request can read the name of the route that matched, for logging, metrics or a self-referencing link:

```php
$request->getAttribute('routing.route'); // 'blog'
```

The value is always a string and is the route's real name, also when the request came in through one of its `legacyPaths`. It is set for HTTP requests and for [programmatic invocation](../background/extending.md) alike. The key `routing.route` is part of the public API and falls under the [BC promise](../background/extending.md#bc-promise).

## Class-level prefix (route groups)

The attribute may also sit on the **controller class**, where its `path` and `name` prefix every method route and most other parameters supply a default. That has its own page: [Route groups](route-groups.md).
