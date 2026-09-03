# Extending

This page is for tooling built **on top of** this extension — a documentation generator, a static analyzer, an API client generator, or a satellite package like a planned MCP server that turns registered routes into callable tools. If you only want to *declare* routes, see [Defining routes](../routes/README.md) instead; this page is about *reading* the routes another package has declared.

## The public surface

Every class in `Classes/` carries either `@api` or `@internal` in its docblock. `@api` is the only surface covered by semver from `1.0.0` — `@internal` classes (and the `@internal`-marked methods described below) may change in a minor release without notice.

The route metadata itself is exposed through [`RouteRegistry`](../../Classes/Routing/RouteRegistry.php), fetched from the DI container:

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

## Invoking a route without an HTTP request

Reading metadata is one half of consuming routes; the other is *calling* one for a given set of values, without an inbound request against the route's own path — an MCP server turning routes into callable tools, a job replaying a stored payload, a smoke-test harness. [`RouteInvoker`](../../Classes/Routing/RouteInvoker.php) is the `@api` seam for that, fetched from the container like the registry:

```php
public function __construct(
    private readonly \KonradMichalik\Typo3Routing\Routing\RouteInvoker $invoker,
) {}

// …
$response = $this->invoker->invoke('example_item', ['id' => 42], $request);
```

`invoke(string $routeName, array $input, ServerRequestInterface $request): ResponseInterface`

- **`$routeName`** — a name from `getRoutes()`. An unknown name is a programming error rather than an HTTP condition, so it throws `InvalidArgumentException` instead of answering 404.
- **`$input`** — a flat map of argument values, keyed by the **wire name** the argument is read under: `getArguments()`'s `name`, which `#[Param(name: …)]` may have set to something other than the PHP parameter name. Each value is placed where that argument's `source` expects it (path segment, query parameter, or JSON body), so all six sources — `path`, `query`, `body`, `input`, `request`, `variadic` — resolve exactly as they would for an inbound request. Keys no argument and no requirement claims are ignored. The seventh source, `host`, is the exception: a route whose [`host`](../routes/route-attribute.md#wildcards-and-multiple-hosts) carries a placeholder cannot have its URL generated without a value for it, so such a route answers `404` here, exactly as an unmatched path would over HTTP.
- **`$request`** — the calling request. It supplies the context a controller may depend on (`site`, `language`, the authenticated user, the credentials) and is deliberately required: there is no sensible default for *which site* a programmatic invocation happens in. A caller without an inbound request (a CLI process, say) has to build one.

The request the controller finally sees is synthesised from it: the route's first declared method, the route's own URL as the request target, the inputs in query and body, path placeholders additionally exposed as request attributes — and always a fresh body stream, so nothing of the calling request's own payload can bleed into a body-sourced argument.

### What it replicates, and what it skips

| Step | Programmatic invocation | Why |
|---|---|---|
| Env filter (`Route::$env`) | enforced → 404 | a route unreachable over HTTP must stay unreachable |
| Site/language scope (`Route::$sites`/`$languages`) | enforced → 404 | mirrors the dispatcher: a route out of scope for the calling request's site/language must stay unreachable |
| Mandatory placeholders, path `requirements` | enforced → 404 | such a path could never have matched, so it must not reach a controller |

One consequence is worth spelling out: a violated path requirement is the single case where the answer *differs* from an HTTP call by design. Over HTTP that path matches no route, so — unless `exclusivePrefixes` claims it — the middleware declines and the page router takes over. An invocation names the route explicitly and has no page to fall back to, so the route's own "no resource for this value" answer stands as a 404.
| Query/body `requirements` | enforced → 400 | part of the route's input contract |
| Authentication (`#[Authenticate]`) | enforced → 401 | a route's own access rule, independent of transport |
| Request token (`#[RequireRequestToken]`) | **skipped** | CSRF protects browser-initiated state changes; no browser is involved, and the calling client's token is not the target route's |
| CORS, including preflight | **skipped** | no browser, no origin to negotiate |
| Rate limiting (`#[RateLimit]`) | **skipped** | HTTP-transport abuse control, not correctness |
| Response cache (`#[Cache]`, ETag, conditional GET) | **skipped** | a transport-level performance mechanism; an invocation always sees fresh output |

Exception handling is the dispatcher's, so error semantics match a real call to the same route: an unresolvable argument is a 400, a missing entity a 404, a controller-thrown [`HttpProblemException`](../../Classes/Http/HttpProblemException.php) keeps its own status, and every other exception stays untouched for TYPO3's error handling. `Tests/Functional/Routing/RouteInvokerTest.php` pins that agreement route by route.

### The two boundaries this shifts to you

**Credentials.** The synthetic request carries the calling request's headers verbatim, so `#[Authenticate]` is checked against whatever the caller presented — a bearer token reaching your own endpoint is the token checked against the target route's expectation. If those are not meant to be the same secret, the invocation answers 401; it never waves authentication through.

**Abuse control.** Because rate limiting is skipped, a consumer that re-exposes routes over its own transport takes over responsibility for rate limiting and authorisation *there*. `RouteInvoker` is a seam for trusted in-process callers, not a guard against the caller.

## Describing an argument as JSON Schema

`getArguments()` reports each argument's `type` as a string — a scalar name, a backed enum's class name, an Extbase domain object's FQCN, or `null` when the parameter is untyped. Turning that into a JSON Schema fragment is what both the OpenAPI export and any consumer describing routes to a schema-driven client (an MCP server's `inputSchema`, a client generator) needs to do, identically. [`JsonSchemaMapper`](../../Classes/OpenApi/JsonSchemaMapper.php) is the `@api` seam for it, so the mapping rules cannot drift between consumers:

```php
public function __construct(
    private readonly \KonradMichalik\Typo3Routing\OpenApi\JsonSchemaMapper $schemas,
) {}

// …
$argument = $this->routes->getArguments('example_item')[0];
$pattern = $this->routes->getRoutes()['example_item']['requirements'][$argument['name']] ?? null;
// A requirement of '' means "presence only" (see #[Route]), not a regex — normalise it away.
$schema = $this->schemas->schemaForType($argument['type'], '' === $pattern ? null : $pattern);
```

`schemaForType(?string $type, ?string $pattern = null): array`

| `$type` | Resulting schema |
|---|---|
| `int` | `{"type": "integer"}` |
| `float` | `{"type": "number"}` |
| `bool` | `{"type": "boolean"}` |
| `array` | `{"type": "array", "items": {}}` |
| `mixed` | `{}` — constrains nothing |
| `string`, `null` (untyped), or any unrecognised type | `{"type": "string"}` |
| A **backed** enum's class name | `{"type": …, "enum": [<backing values accepted by `$pattern`>]}`, `integer` for an `int` backing type, `string` otherwise |
| An Extbase domain object's class name | `{"type": "integer"}` — the record UID |

Four details are part of the contract rather than incidental:

- **`$pattern` becomes a `pattern` keyword only on a `{"type": "string"}` schema.** It is the route's `requirements` regex for that argument. It is applied as `pattern` *only* when the mapping above produced a plain string schema — never to an `integer`/`number`/`boolean`/`array` schema, never to `mixed`, and never to a domain object. Pass `null` when there is no pattern — the mapper treats **only** `null` that way. A requirement of `''` means "presence only" rather than a regex (see [`#[Route]`](../../Classes/Attribute/Route.php)), so normalise it to `null` yourself before calling; `''` would otherwise land in the schema as `"pattern": ""`.
- **On an enum, `$pattern` narrows the `enum` list instead of becoming a `pattern`.** A requirement applies to the raw wire value regardless of the argument's PHP type, so a route may accept fewer cases than the enum declares — `requirements: ['status' => 'active']` on a `Status{active,inactive}` argument matches only `active`, and a document listing both would advertise a value the router answers with a 404. The cases are therefore filtered and `enum` alone states the result; no `pattern` is added, since it would restate the same constraint. Matching mirrors enforcement exactly — anchored and grouped (`#^(?:…)$#`), the form [`ControllerInvoker`](../../Classes/Routing/ControllerInvoker.php) and Symfony's matcher both use — so a case merely *containing* the requirement is dropped. Two edge cases: a requirement no case satisfies yields `"enum": []`, which honestly describes a route that can never match; a requirement that is not a usable regex cannot be evaluated, so the list stays unnarrowed rather than claiming nothing is acceptable. Note this narrowing arrived in **`0.5.0`** — earlier releases ignored the requirement on enum-typed arguments entirely.
- **An Extbase domain object describes its UID, not the object.** Such an argument is resolved by looking the record up by UID, and `ControllerArgumentResolver::toEntity()` accepts nothing but an integer — so `integer` is what a client has to send. Note this is a **change from earlier `0.x` releases**, which described these arguments as `{"type": "string"}`; an OpenAPI document regenerated after upgrading will differ for every entity-typed argument.
- **Only backed enums are expected.** The extension's own compile step rejects a pure enum outright, so one cannot reach this mapper through a registered route. An external caller passing one gets `{"type": "string", "enum": []}` rather than an exception.

`JsonSchemaMapper` gained a second `@api` method in **`1.1.0`**: `objectSchemaForClass(string $class): array`. Where `schemaForType()` maps a single argument's type, `objectSchemaForClass()` maps a whole DTO class — its public properties (plain or promoted constructor properties alike) become an object schema's `properties`, used by [`#[Returns]`](../operating/openapi.md#declaring-a-response-schema) to describe a route's response body:

```php
$schema = $this->schemas->objectSchemaForClass(CourseDto::class);
// {"type": "object", "properties": {"id": {"type": "integer"}, "title": {"type": "string"}, …}, "required": ["id", "title"]}
```

- **A property's own type is mapped through the same rules as `schemaForType()`** — a backed enum or Extbase domain object property is never treated as a nested DTO, so the two methods never disagree about them. Any other class-typed property is mapped recursively via `objectSchemaForClass()` itself.
- **`required` lists every non-nullable property without a default.** A nullable property, or one with a default value (including a promoted constructor parameter's default), is omitted from `required`; the key itself is left out of the schema entirely when nothing qualifies, rather than emitted as `"required": []`.

## Reference consumer: the OpenAPI export

[`Classes/OpenApi/OpenApiGenerator.php`](../../Classes/OpenApi/OpenApiGenerator.php) (internal) is the extension's own reference consumer of this API — it builds an OpenAPI 3.1 document purely from `RouteRegistry::getRoutes()`, `getArguments()`, `getAuthenticators()`, `getRequestTokenScope()`, `getCacheConfig()`, `getRateLimit()`, `getReturns()`, and `getDeprecation()`, with no access to anything `@internal`. Reading it end to end is the fastest way to see the metadata API used for something non-trivial: parameter/request-body construction from argument specs, security scheme mapping from authenticators, response-schema construction from `#[Returns]`, and error-response generation from which modifiers are present.

## What's deliberately not exposed

- **Compile-time internals** (`RouteCompilerPass`, `CollectedRoutes`, `ArgumentSpecFactory`, `CorsResolver`) exist only to build the arrays `RouteRegistry` holds. They run once per container build and are never available at runtime — there is nothing to consume there even if you wanted to.
- **Dispatch internals** (`RouteDispatcher`, `ControllerInvoker`, `ControllerArgumentResolver`, `AccessGuard`, `CorsPreflightResolver`, the `Cache`/`RateLimit`/`Http` namespaces) implement request handling. Their behavior is documented (see [How it works](how-it-works.md), [Configuration](../operating/configuration.md)) but their classes are not a PHP API — nothing about them is meant to be called, extended, or type-hinted against from outside. `ControllerInvoker` in particular is the step-sharing collaborator behind both `RouteDispatcher` and `RouteInvoker`: consume the latter, never it.
- **Commands** (`routing:debug`, `routing:match`, `routing:openapi`) are a documented, stable *CLI* interface (arguments, options, `--json` output shape), but the PHP command classes themselves — including the `RouteTableFormatter` helper behind `routing:debug` — are `@internal`; consume the CLI, not the classes.
- **The Swagger UI route** (`SwaggerUiController`, see [Configuration](../operating/openapi.md#swagger-ui-development-only)) is a development-only HTTP endpoint, not a PHP extension point — consume it over HTTP like any other route, not by referencing the controller class.

## BC promise

Starting at `1.0.0`, `@api`-marked classes (and `@api`-marked methods on otherwise-`@internal` classes) follow [semantic versioning](https://semver.org/): a minor release adds but never removes or changes existing public signatures; a breaking change to `@api` surface requires a major version. `@internal` classes and methods may change — including removal — in any release, including a patch release, without that counting as a breaking change.

There is currently no automated check enforcing this boundary (e.g. a PHPStan rule flagging `@internal` symbols referenced from outside their own namespace) — a `@internal`/`@api` mismatch would be caught in code review, not CI. This may be revisited if it becomes a real problem in practice.
