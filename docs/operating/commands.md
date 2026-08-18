# Console commands

```bash
vendor/bin/typo3 routing:debug [<name>] [--json] [--method=…] [--path=…] [--env=…] [--unprotected|--protected] [--cached] [--rate-limited] [--csrf] [--deprecated]
vendor/bin/typo3 routing:match <path> [--method=GET] [--scheme=https] [--host=localhost] [--site=…] [--language=…]
```

> [!TIP]
> Both commands read the same compiled registry as the dispatcher, so they can never drift from actual runtime behaviour.

A third command, `routing:openapi`, exports the same registry as an API document and has its own page: [OpenAPI export](openapi.md).

## `routing:debug`

Lists every registered route.

```bash
vendor/bin/typo3 routing:debug          # human-readable table
vendor/bin/typo3 routing:debug --json   # machine-readable (tooling / LLM)
```

The table shows path, methods, controller, environment binding and requirements:

```text
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

<details>
<summary><code>--json</code> emits the same data as an array, including the modifiers the table omits</summary>

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

</details>

### Filtering and inspecting

Pass a route name as an argument: an **exact** name prints a full detail view (including cache, rate limit, and the resolved controller arguments, which the overview table omits); any other value is treated as a **name substring** filter. A route's [`description`](../routes/route-attribute.md#description) is truncated in the table but shown in full in the detail view and `--json`.

```bash
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
| `--deprecated`   | are marked deprecated (see [Deprecating a route](../routes/route-attribute.md#deprecating-a-route)) |

```bash
vendor/bin/typo3 routing:debug --method=POST --protected   # protected write endpoints
vendor/bin/typo3 routing:debug --cached --json             # cached routes, machine-readable
```

## `routing:match`

Runs the same matcher the dispatcher uses — [trailing-slash tolerance](configuration.md#trailing-slashes) included — and reports which route wins for a given path, or why none does.

```bash
vendor/bin/typo3 routing:match /api/item/new                        # which route claims this path?
vendor/bin/typo3 routing:match /api/item/42                         # placeholder route, with resolved parameters
vendor/bin/typo3 routing:match /api/orders --method=POST --host=api.example.com
```

Give the path **without the site base** (exactly as written in `#[Route]`); the leading slash is optional. `--method` (default `GET`), `--scheme` (default `https`) and `--host` (default `localhost`) simulate the request, so [`schemes`](../routes/route-attribute.md#schemes) / [`host`](../routes/route-attribute.md#host) constraints and [priority](../routes/route-attribute.md#priority) overlaps can be debugged. `--site` and `--language` simulate the request's site identifier and language id, so a [`sites`/`languages`](../routes/route-attribute.md#site--and-language-bound-routes) constraint that would reject the request is reported the same way a `requirements` mismatch is, instead of silently matching.

A match prints the route name, controller, resolved path parameters and — for an [environment-bound route](configuration.md#environment-bound-routes) — a note that it is only reachable in that context (the matcher itself ignores `env`; the dispatcher enforces it at request time). A path that matches nothing exits non-zero with `No route matches`; a path that matches but rejects the method reports the allowed methods.

### The requirement-mismatch report

One miss gets its own report, because `No route matches` would be misleading: a [case-insensitive route](../routes/route-attribute.md#case-insensitive-paths) whose path was found but whose `requirements` then rejected a placeholder value.

```text
 [WARNING] Path "/API/Courses/Intro-To-Php" matches route "course_show", but the
           value "Intro-To-Php" for parameter "slug" does not satisfy its
           requirement "[a-z-]+".

 ! [NOTE] The route opted into caseInsensitive, which covers the path's literal
 !        segments only. Placeholder values and their requirements stay
 !        case-sensitive.
```

At request time this stays an ordinary `404`: the distinction is a development aid, not something the client is told.
