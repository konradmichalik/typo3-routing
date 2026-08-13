# How It Works

1. **Compile time** — [`RouteCompilerPass`](../Classes/DependencyInjection/RouteCompilerPass.php) scans every service definition, picks those implementing `RouteControllerInterface`, reflects their `#[Route]` attributes **and method parameter signatures** into plain arrays, and injects those plus a `ServiceLocator` of the controllers into [`RouteRegistry`](../Classes/Routing/RouteRegistry.php). The route collection is also dumped into Symfony's `CompiledUrlMatcher` format at build time, so request-time matching runs on pre-compiled tables instead of re-compiling every route's regex per request. Duplicate route names, unsupported parameter shapes, and modifier attributes (`#[Cache]`, `#[RateLimit]`, `#[Authenticate]`, `#[RequireRequestToken]`) sitting on a method without a `#[Route]` all raise a build-time exception. There is no extra cache: invalidation rides on the DI container cache, which TYPO3 already clears correctly.

2. **Runtime** — [`RouteDispatcher`](../Classes/Middleware/RouteDispatcher.php) applies the [path gate](CONFIGURATION.md#path-gate) derived from the compiled routes, matches via `symfony/routing`, filters by environment, then resolves the controller method's typed arguments via [`ControllerArgumentResolver`](../Classes/Routing/ControllerArgumentResolver.php) and invokes it. `404`, `405` (with an `Allow` header), `400` (unresolvable/invalid argument), and controller-thrown `HttpProblemException` responses are emitted as [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details — `application/problem+json` with `{"type": "about:blank", "title": …, "status": …, "detail"?: …}` (`detail` is omitted when it would only repeat the title); the success response format is entirely the controller's choice. Every response — success or error — also carries an `X-Request-ID` header: echoed back when the client sent one, otherwise generated, so a single id correlates a request across logs and proxies.

## Debug command

> [!TIP]
> `routing:debug` reads the same compiled registry as the dispatcher, so it can never drift from actual runtime behaviour.

``` bash
vendor/bin/typo3 routing:debug          # human-readable table
vendor/bin/typo3 routing:debug --json   # machine-readable (tooling / LLM)
```

### Filtering and inspecting

Pass a route name as an argument: an **exact** name prints a full detail view (including cache, rate limit, and the resolved controller arguments — which the overview table omits); any other value is treated as a **name substring** filter. A route's `description` (see [Description](USAGE.md#description)) is truncated in the table but shown in full in the detail view and `--json`.

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

## Match simulation command

`routing:match` runs the same matcher the dispatcher uses — [trailing-slash tolerance](CONFIGURATION.md#trailing-slashes) included — and reports which route wins for a given path, or why none does. Give the path **without the site base** (exactly as written in `#[Route]`); the leading slash is optional. `--method` (default `GET`), `--scheme` (default `https`) and `--host` (default `localhost`) simulate the request so `schemes`/`host` constraints and priority overlaps can be debugged.

``` bash
vendor/bin/typo3 routing:match /api/item/new                        # which route claims this path?
vendor/bin/typo3 routing:match /api/item/42                         # placeholder route, with resolved parameters
vendor/bin/typo3 routing:match /api/orders --method=POST --host=api.example.com
```

A match prints the route name, controller, resolved path parameters and — for an [environment-bound route](CONFIGURATION.md) — a note that it is only reachable in that context (the matcher itself ignores `env`; the dispatcher enforces it at request time). A path that matches nothing exits non-zero with `No route matches`; a path that matches but rejects the method reports the allowed methods.

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
| `--server=…`        | Server base URL; omitted by default, which per the OpenAPI spec means `/` — correct, since route paths are exported in full |
| `--pretty`          | Pretty-print the JSON                                             |

What is derived automatically from the attributes:

- **Paths & operations** — one operation per HTTP method; `{placeholder}` path segments become path parameters.
- **Parameters & request bodies** — from the typed method signature: path/query parameters for `GET`-style routes, a JSON request body for `POST`/`PUT`/`PATCH`. Scalar types map to JSON Schema, backed enums become `enum` schemas, and a `requirements` regex becomes a `pattern`.
- **Security** — `#[Authenticate]` routes reference a matching security scheme (`BearerTokenAuthenticator` → HTTP bearer; FE/BE user → cookie API key). OR-combined authenticators emit multiple requirements.
- **Responses** — a generic `200` plus the error responses each route can actually produce (`400`/`401`/`403`/`404`/`405`/`429`), all served as `application/problem+json` sharing the RFC 9457 `{type, title, status, detail}` `Error` schema.
- **Description & summary** — a route's `#[Route(description: …)]` becomes the operation `description`; if it splits into more than one sentence, the first sentence is also emitted as `summary`. Routes without a `description` fall back to a synthesized one-liner naming the controller, CSRF requirement, and application context.

### Swagger UI (development only)

A live Swagger UI is a thin, opt-in HTTP layer on top of the same export — no separate spec generation logic. Enable it via the [`swaggerUi` configuration flag](CONFIGURATION.md#swagger-ui-development-only); it's inert everywhere else:

- `GET /api/_routing/openapi.json` — the OpenAPI document, generated the same way as `routing:openapi`
- `GET /api/_routing/docs` — a minimal HTML page loading Swagger UI from a CDN, pointed at the JSON route above

Both routes are excluded from the OpenAPI document itself (they describe tooling, not the API surface) and only ever exist in the `Development` application context, on top of the config flag — see [Environment-bound routes](CONFIGURATION.md#environment-bound-routes) for how that gate works.
