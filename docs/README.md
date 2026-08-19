# Documentation

Four groups, roughly in the order you meet them: declare a route, add the behaviour it needs, run it in an installation, understand what it does underneath.

New here? [Defining routes](routes/README.md) is the only group you need to read to ship an endpoint. Everything in [Route features](features/README.md) is opt-in, and [Configuration](operating/configuration.md) has no required setting.

## [Defining routes](routes/README.md)

The everyday surface: what you write on a controller to make an endpoint exist.

| Page | What's inside |
|------|---------------|
| [The `#[Route]` attribute](routes/route-attribute.md) | Every parameter: `requirements`, priority, optional placeholders, schemes, host, case tolerance, and how a controller returns an error |
| [Route groups](routes/route-groups.md) | A class-level `#[Route]` as a shared prefix, which parameters inherit from it, and sharing route definitions through a base class |
| [Typed controller arguments](routes/arguments.md) | How a method signature is fed from the request: type coercion, backed enums, entity binding, variadics, `#[Param]` |
| [URL generation](routes/url-generation.md) | `routing:uri` / `routing:uris` Fluid ViewHelpers and the PHP generator, so a path is never duplicated |
| [Records](routes/records.md) | A recipe for list/detail endpoints over database records, and why it stays a recipe |

## [Route features](features/README.md)

Opt-in behaviour, declared as its own attribute next to `#[Route]`. Nothing here applies unless you ask for it.

| Page | What's inside |
|------|---------------|
| [Authentication & CSRF](features/authentication.md) | `#[Authenticate]`, the built-in authenticators, `#[RequireRequestToken]`, and the Bearer deployment traps |
| [Caching](features/caching.md) | `#[Cache]`, tag-based invalidation, ETag / conditional GET, and what must never be cached |
| [Rate limiting](features/rate-limiting.md) | `#[RateLimit]`, throttling per IP or per user, and the quota headers on every response |
| [CORS](features/cors.md) | Global configuration, per-route `#[Cors]`, and automatic preflight handling |

## [Operating it](operating/README.md)

What an installation is configured with, and the tooling that inspects it.

| Page | What's inside |
|------|---------------|
| [Configuration](operating/configuration.md) | Every extension setting in one table, plus the derived path gate, trailing slashes, exclusive prefixes, environment-bound routes and middleware placement |
| [Console commands](operating/commands.md) | `routing:debug` to list and audit routes, `routing:match` to ask which route claims a path |
| [OpenAPI export](operating/openapi.md) | `routing:openapi`, what it derives from your attributes, and the development-only Swagger UI |

## [Background](background/README.md)

Why it exists, how it works, what it costs, and how to build on it.

| Page | What's inside |
|------|---------------|
| [How it compares](background/comparison.md) | When to reach for this versus `AjaxRoutes`, custom middleware, `eID`, or Extbase plugins |
| [How it works](background/how-it-works.md) | Compile-time discovery, the runtime dispatch pipeline step by step, and the response contract |
| [Performance](background/performance.md) | Measured dispatch overhead, what the path gate costs unrelated traffic, and how to reproduce both |
| [Extending](background/extending.md) | Building tooling on the route metadata (`RouteRegistry`, `RouteInvoker`), the `@api`/`@internal` surface, and the BC promise |
