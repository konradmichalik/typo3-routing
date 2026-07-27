# Extending

This page is for tooling built **on top of** this extension — a documentation generator, a static analyzer, an API client generator, or a satellite package like a planned MCP server that turns registered routes into callable tools. If you only want to *declare* routes, see [Usage](USAGE.md) instead; this page is about *reading* the routes another package has declared.

## The public surface

Every class in `Classes/` carries either `@api` or `@internal` in its docblock. `@api` is the only surface covered by semver from `1.0.0` — `@internal` classes (and the `@internal`-marked methods described below) may change in a minor release without notice.

The route metadata itself is exposed through [`RouteRegistry`](../Classes/Routing/RouteRegistry.php), fetched from the DI container:

```php
public function __construct(
    private readonly \KonradMichalik\Typo3Routing\Routing\RouteRegistry $routes,
) {}
```

`RouteRegistry` serves two purposes — reading route metadata, and providing plumbing the dispatcher/URL generator need internally. Only the metadata-reading methods below are `@api`; `getRouteCollection()`, `getMatcher()`, `getControllerLocator()`, `getAuthenticatorLocator()`, and the static `buildCollection()` are marked `@internal` on the method itself, even though the class as a whole is `@api`.

| Method | Returns |
|---|---|
| `getRoutes(): array<string, array{path, methods, controller, env, requirements, priority?, defaults?, schemes?, host?, description?}>` | Every compiled route, keyed by name |
| `getArguments(string $routeName): list<array{name, type, source, nullable, hasDefault, default}>` | The controller method's parameters, in declaration order |
| `getAuthenticators(string $routeName): list<array{service, options}>` | OR-combined authenticators; empty means public |
| `getRequestTokenScope(string $routeName): ?string` | The expected CSRF scope, or `null` |
| `getCacheConfig(string $routeName): ?array{lifetime, tags, ignoreParams}` | Response-cache config, or `null` |
| `getRateLimit(string $routeName): ?array{limit, interval, policy, keyBy}` | Rate-limit config, or `null` |
| `getCorsConfig(string $routeName): ?array{allowedOrigins, allowedHeaders, allowCredentials, exposeHeaders, maxAge}` | The route's own `#[Cors]` override, or `null` when it falls back to the global CORS configuration |

The per-route array shapes (the `array{...}` types above) are part of the `@api` contract: adding a key is a minor-version change, removing or renaming one is a breaking change from `1.0.0` on.

## Reference consumer: the OpenAPI export

[`Classes/OpenApi/OpenApiGenerator.php`](../Classes/OpenApi/OpenApiGenerator.php) (internal) is the extension's own reference consumer of this API — it builds an OpenAPI 3.1 document purely from `RouteRegistry::getRoutes()`, `getArguments()`, `getAuthenticators()`, `getRequestTokenScope()`, `getCacheConfig()`, and `getRateLimit()`, with no access to anything `@internal`. Reading it end to end is the fastest way to see the metadata API used for something non-trivial: parameter/request-body construction from argument specs, security scheme mapping from authenticators, and error-response generation from which modifiers are present.

## What's deliberately not exposed

- **Compile-time internals** (`RouteCompilerPass`, `CollectedRoutes`, `ArgumentSpecFactory`, `CorsResolver`) exist only to build the arrays `RouteRegistry` holds. They run once per container build and are never available at runtime — there is nothing to consume there even if you wanted to.
- **Dispatch internals** (`RouteDispatcher`, `ControllerArgumentResolver`, `AccessGuard`, `CorsPreflightResolver`, the `Cache`/`RateLimit`/`Http` namespaces) implement request handling. Their behavior is documented (see [How It Works](HOW-IT-WORKS.md), [Configuration](CONFIGURATION.md)) but their classes are not a PHP API — nothing about them is meant to be called, extended, or type-hinted against from outside.
- **Commands** (`routing:debug`, `routing:match`, `routing:openapi`) are a documented, stable *CLI* interface (arguments, options, `--json` output shape), but the PHP command classes themselves — including the `RouteTableFormatter` helper behind `routing:debug` — are `@internal`; consume the CLI, not the classes.
- **The Swagger UI route** (`SwaggerUiController`, see [Configuration](CONFIGURATION.md#swagger-ui-development-only)) is a development-only HTTP endpoint, not a PHP extension point — consume it over HTTP like any other route, not by referencing the controller class.

## BC promise

Starting at `1.0.0`, `@api`-marked classes (and `@api`-marked methods on otherwise-`@internal` classes) follow [semantic versioning](https://semver.org/): a minor release adds but never removes or changes existing public signatures; a breaking change to `@api` surface requires a major version. `@internal` classes and methods may change — including removal — in any release, including a patch release, without that counting as a breaking change.

There is currently no automated check enforcing this boundary (e.g. a PHPStan rule flagging `@internal` symbols referenced from outside their own namespace) — a `@internal`/`@api` mismatch would be caught in code review, not CI. This may be revisited if it becomes a real problem in practice.
