# How It Works

1. **Compile time** — [`RouteCompilerPass`](../Classes/DependencyInjection/RouteCompilerPass.php) scans every service definition, picks those implementing `RouteControllerInterface`, reflects their `#[Route]` attributes **and method parameter signatures** into plain arrays, and injects those plus a `ServiceLocator` of the controllers into [`RouteRegistry`](../Classes/Routing/RouteRegistry.php). Duplicate route names and unsupported parameter shapes raise a build-time exception. There is no extra cache: invalidation rides on the DI container cache, which TYPO3 already clears correctly.

2. **Runtime** — [`RouteDispatcher`](../Classes/Middleware/RouteDispatcher.php) applies the prefix gate, matches via `symfony/routing`, filters by environment, then resolves the controller method's typed arguments via [`ControllerArgumentResolver`](../Classes/Routing/ControllerArgumentResolver.php) and invokes it. `404`, `405` (with an `Allow` header), and `400` (unresolvable/invalid argument) responses are emitted as `{"error": "…", "status": …}`; the success response format is entirely the controller's choice.

## Debug command

> [!TIP]
> `routing:debug` reads the same compiled registry as the dispatcher, so it can never drift from actual runtime behaviour.

``` bash
vendor/bin/typo3 routing:debug          # human-readable table
vendor/bin/typo3 routing:debug --json   # machine-readable (tooling / LLM)
```
