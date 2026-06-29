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
        "requirements": {"id": "\\d+"}
    }
]
```
