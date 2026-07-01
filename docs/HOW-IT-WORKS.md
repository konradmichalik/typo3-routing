# How It Works

1. **Compile time** — [`RouteCompilerPass`](../Classes/DependencyInjection/RouteCompilerPass.php) scans every service definition, picks those implementing `RouteControllerInterface`, reflects their `#[Route]` attributes **and method parameter signatures** into plain arrays, and injects those plus a `ServiceLocator` of the controllers into [`RouteRegistry`](../Classes/Routing/RouteRegistry.php). Duplicate route names, unsupported parameter shapes, and modifier attributes (`#[Cache]`, `#[RateLimit]`, `#[Authenticate]`, `#[RequireRequestToken]`) sitting on a method without a `#[Route]` all raise a build-time exception. There is no extra cache: invalidation rides on the DI container cache, which TYPO3 already clears correctly.

2. **Runtime** — [`RouteDispatcher`](../Classes/Middleware/RouteDispatcher.php) applies the prefix gate, matches via `symfony/routing`, filters by environment, then resolves the controller method's typed arguments via [`ControllerArgumentResolver`](../Classes/Routing/ControllerArgumentResolver.php) and invokes it. `404`, `405` (with an `Allow` header), and `400` (unresolvable/invalid argument) responses are emitted as `{"error": "…", "status": …}`; the success response format is entirely the controller's choice.

## Debug command

> [!TIP]
> `routing:debug` reads the same compiled registry as the dispatcher, so it can never drift from actual runtime behaviour.

``` bash
vendor/bin/typo3 routing:debug          # human-readable table
vendor/bin/typo3 routing:debug --json   # machine-readable (tooling / LLM)
```

### Filtering and inspecting

Pass a route name as an argument: an **exact** name prints a full detail view (including cache, rate limit, and the resolved controller arguments — which the overview table omits); any other value is treated as a **name substring** filter.

``` bash
vendor/bin/typo3 routing:debug course_show     # detail view for one route
vendor/bin/typo3 routing:debug course          # substring search over names
```

Filters narrow the table (and `--json`) and combine with AND. The active filters are echoed above the table.

| Option           | Keeps routes that…                                  |
| ---------------- | --------------------------------------------------- |
| `--method=POST`  | accept the HTTP method (case-insensitive; routes with no method restriction always match) |
| `--path=/api`    | contain the substring in their path                 |
| `--env=Development` | are bound to that application context             |
| `--unprotected`  | have no authenticator (audit open endpoints)        |
| `--protected`    | are guarded by an authenticator                     |
| `--cached`       | have response caching                               |
| `--rate-limited` | have rate limiting                                  |
| `--csrf`         | require a CSRF request token                        |

``` bash
vendor/bin/typo3 routing:debug --method=POST --protected   # protected write endpoints
vendor/bin/typo3 routing:debug --cached --json             # cached routes, machine-readable
```

The table lists every route with its path, methods, controller, environment binding, and requirements:

```
 Attribute Routes
 ================

 ---------------------- -------------------- --------- ------------------------------------ ------------- --------------
  Name                   Path                 Methods   Controller                           Env           Requirements
 ---------------------- -------------------- --------- ------------------------------------ ------------- --------------
  course_search_count    /api/course-search   GET       CourseSearchController::count        -             -
  course_show            /api/courses/{id}    GET       CourseController::show               -             id: \d+
  debug_dump             /api/debug/dump      GET       DebugController::dump                 Development   -
 ---------------------- -------------------- --------- ------------------------------------ ------------- --------------
```

`--json` emits the same data as an array, ready for tooling or an LLM:

```json
[
    {
        "name": "course_show",
        "path": "/api/courses/{id}",
        "methods": ["GET"],
        "controller": "CourseController::show",
        "env": null,
        "requirements": {"id": "\\d+"},
        "auth": [],
        "csrf": null,
        "cache": {"lifetime": 3600, "tags": ["pages"], "ignoreParams": []},
        "rateLimit": null,
        "arguments": [
            {"name": "id", "type": "int", "source": "path", "nullable": false, "hasDefault": false, "default": null}
        ]
    }
]
```

## OpenAPI export

`routing:openapi` turns the same compiled registry into an [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.0) document — so the routes stay the single source of truth for your API contract, Swagger UI, and client generators.

``` bash
vendor/bin/typo3 routing:openapi                 # compact JSON to stdout
vendor/bin/typo3 routing:openapi --pretty        # pretty-printed
vendor/bin/typo3 routing:openapi --pretty > openapi.json
```

| Option              | Effect                                                            |
| ------------------- | ----------------------------------------------------------------- |
| `--title=…`         | API title (default `TYPO3 Routing API`)                           |
| `--api-version=…`   | API version (default `1.0.0`)                                     |
| `--server=…`        | Server base URL (defaults to the configured path prefix)          |
| `--pretty`          | Pretty-print the JSON                                             |

What is derived automatically from the attributes:

- **Paths & operations** — one operation per HTTP method; `{placeholder}` path segments become path parameters.
- **Parameters & request bodies** — from the typed method signature: path/query parameters for `GET`-style routes, a JSON request body for `POST`/`PUT`/`PATCH`. Scalar types map to JSON Schema, backed enums become `enum` schemas, and a `requirements` regex becomes a `pattern`.
- **Security** — `#[Authenticate]` routes reference a matching security scheme (`BearerTokenAuthenticator` → HTTP bearer; FE/BE user → cookie API key). OR-combined authenticators emit multiple requirements.
- **Responses** — a generic `200` plus the error responses each route can actually produce (`400`/`401`/`403`/`404`/`405`/`429`), all sharing the `{error, status}` `Error` schema.
