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

A controller method declares **only the parameters it needs** — there is no fixed signature. Type-hint `ServerRequestInterface` to receive the request; everything else is resolved by name from the route (see [Typed arguments](#typed-controller-arguments)).

## The `#[Route]` attribute

The attribute is repeatable. Its parameters:

| Parameter      | Type                    | Default   | Description                                                              |
|----------------|-------------------------|-----------|--------------------------------------------------------------------------|
| `path`         | `string`                | –         | Full request path, written including the prefix (e.g. `/api/...`).       |
| `methods`      | `list<string>`          | `['GET']` | Allowed HTTP methods.                                                    |
| `name`         | `?string`               | `null`    | Route name; auto-derived from service id + method when omitted.          |
| `env`          | `?string`               | `null`    | Bind the route to a top-level application context (e.g. `Development`).  |
| `requirements` | `array<string, string>` | `[]`      | Constraints by parameter name → regex (`''` = presence only). See below. |
| `priority`     | `int`                   | `0`       | Match priority; higher is matched first when paths overlap. See below.   |

## Priority

When a static path and a placeholder path can both match the same URL, the one with the higher `priority` wins. Give the more specific route the higher value:

```php
#[Route(path: '/api/item/new', name: 'item_new', priority: 10)]
public function new(): ResponseInterface { /* … */ }

#[Route(path: '/api/item/{id}', name: 'item_show', requirements: ['id' => '\d+'])]
public function show(int $id): ResponseInterface { /* … */ }
```

`priority` affects match order only; `routing:debug` and URL generation are unaffected. (Often unnecessary — a `requirements` constraint like `['id' => '\d+']` already keeps `/api/item/new` from matching the `{id}` route.)

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
| `methods`      | **Ignored** at class level — the method default (`['GET']`) is indistinguishable from "unset". |

## Requirements

`requirements` constrains parameters by name, with two enforcement layers depending on where the parameter lives:

- **Path placeholders** (a name that appears as `{name}` in the path) are enforced by the **matcher**: a violating path is treated as no match → **404**.
- **Any other name** is a required **query or POST-body** parameter, validated at **dispatch**: missing or format-violating → **400**, before your controller runs. (`''` means presence only.)

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

## Typed controller arguments

Instead of reading values off the request by hand, declare them as **typed method parameters**. The extension reflects each routed method's signature at container-compile time and resolves the arguments at dispatch:

| Parameter shape                   | Resolved from                          |
|-----------------------------------|----------------------------------------|
| `ServerRequestInterface $request` | The PSR-7 request itself.              |
| A name matching a `{placeholder}` | The matched path segment.              |
| Any other scalar name             | Query string, then request body.       |

Values are coerced to the declared type (`int`, `float`, `bool`, `string`, `array`, `mixed`; untyped = raw string) — including **backed enums**. A value that cannot be coerced, or a missing parameter without a default, yields a **400** before the controller runs. Optional parameters use their PHP default; nullable parameters become `null` when absent.

> [!NOTE]
> The request **body** is read as form fields for `application/x-www-form-urlencoded`/`multipart` POSTs, and decoded from the raw stream for `application/json` requests — so JSON payloads (and any `PUT`/`PATCH` body) bind to parameters the same way. The body stream stays rewound, so a controller that injects `ServerRequestInterface` can still read it.

```php
#[Route(path: '/api/courses/{id}', name: 'course_show', requirements: ['id' => '\d+'])]
public function show(int $id, int $page = 1, ?string $sort = null, ServerRequestInterface $request): ResponseInterface
{
    // $id   ← path placeholder, cast to int
    // $page ← ?page=… query param, defaults to 1
    // $sort ← ?sort=… query param, null when omitted
    // $request ← the full request, still available when you need headers/body
    // …
}
```

> [!NOTE]
> `requirements` validates the *format* (regex) of inputs and runs first; typed parameters handle the *type* mapping. Use them together: a placeholder constrained by `requirements: ['id' => '\d+']` plus an `int $id` parameter gives you a guaranteed, type-safe value.
>
> Unsupported parameter shapes (union/intersection types, non-request objects, pure non-backed enums) are rejected at compile time with a clear `LogicException`, so misuse surfaces during container build, not at runtime.

### Backed enums

A **backed enum** parameter is resolved from its backing value (string-compared, so `?priority=5` resolves an `int`-backed case). An unknown value yields a **400**.

```php
enum Status: string { case Active = 'active'; case Inactive = 'inactive'; }

#[Route(path: '/api/users/{status}', name: 'users_by_status')]
public function byStatus(Status $status): ResponseInterface
{
    // /api/users/active → Status::Active
}
```

### Variadics

A **variadic** parameter collects zero or more values from a single input array (`?ids[]=1&ids[]=2`), each coerced to the element type. An absent input yields no arguments.

```php
#[Route(path: '/api/courses', name: 'courses_filter')]
public function filter(int ...$ids): ResponseInterface
{
    // /api/courses?ids[]=3&ids[]=7 → filter(3, 7)
}
```

### Overriding the source with `#[Param]`

By default the lookup key is the parameter name and the source is auto-derived. The [`#[Param]`](../Classes/Attribute/Param.php) attribute overrides either:

| Argument | Description                                                            |
|----------|------------------------------------------------------------------------|
| `name`   | Read a different input/path key than the parameter name.               |
| `source` | Pin the source: `path`, `query`, `body` (form or JSON), or `input` (query + body). |

```php
use KonradMichalik\Typo3Routing\Attribute\Param;

#[Route(path: '/api/search', name: 'search')]
public function search(
    #[Param(name: 'q')] string $term,        // reads ?q=… into $term
    #[Param(source: 'body')] int $page = 1,  // only from the request body (form or JSON)
): ResponseInterface {
    // …
}
```
