# How it works

Two phases, and the split between them is the whole design: everything that can be decided without a request is decided once, at container build time, so a request only ever reads pre-computed tables.

## Compile time

[`RouteCompilerPass`](../../Classes/DependencyInjection/RouteCompilerPass.php) scans every service definition, picks those implementing `RouteControllerInterface`, reflects their `#[Route]` attributes **and method parameter signatures** into plain arrays, and injects those plus a `ServiceLocator` of the controllers into [`RouteRegistry`](../../Classes/Routing/RouteRegistry.php). The route collection is also dumped into Symfony's `CompiledUrlMatcher` format, so request-time matching runs on pre-compiled tables instead of re-compiling every route's regex per request.

Anything contradictory is a **build-time exception** rather than a runtime surprise: duplicate route names, unsupported parameter shapes, and modifier attributes (`#[Cache]`, `#[RateLimit]`, `#[Authenticate]`, `#[RequireRequestToken]`) sitting on a method without a `#[Route]`.

There is no extra cache. Invalidation rides on the DI container cache, which TYPO3 already clears correctly.

### The one thing that is not compiled

Routes opting into [`caseInsensitive`](../routes/route-attribute.md#case-insensitive-paths) are matched in a **separate collection, by the plain `UrlMatcher`**, never through the dumped one. `CompiledUrlMatcherDumper` resolves placeholder-free routes through an exact-match table that no regex modifier can reach, so a compiled route could not be made case-tolerant at all.

That fallback is consulted only after the exact path and the trailing-slash retry have both already failed, which is why a route requested in its declared casing measures identically to a plain one. [Performance](performance.md#case-insensitive-paths) has the figures for both directions.

## Encoded vs. decoded paths

The request path travels through two encodings, and the boundary between them is deliberate, not incidental.

**Encoded**, from `SiteBasePathResolver::stripSiteBase()` up to and including the [path gate](../operating/configuration.md#path-gate): TYPO3's `Uri` always yields the percent-encoded path (`/api/über-uns` reads back as `/api/%C3%BCber-uns`), and `PathPrefixGate::matches()` compares against it with a plain `str_starts_with()` — deliberately: decoding in front of the gate would put extra work on the hot path of every ordinary page request, and would turn what is otherwise a pure "widening a filter is always safe" performance check into a separate security decision. The same encoded comparison also drives `PathPrefixGate`'s other use as the exclusive claim, deciding whether an unmatched path answers a JSON `404` or falls through to page rendering — decoding there would change that decision too, not only the performance filter.

**Decoded**, from the matcher inwards: both `UrlMatcher` and `CompiledUrlMatcher` call `rawurldecode()` on the path before matching it, so `RouteMatcher` hands back decoded placeholder values — a controller sees `hello world`, never `hello%20world`.

Two consequences follow directly from where that boundary sits:

- A route's **static prefix** is derived from its declared path, which is decoded — so a non-ASCII prefix cannot `str_starts_with()`-match the encoded request path on its own, and a route that needs to be reachable at all has to feed its encoded form into the gate too (see `RouteRegistry::staticPrefixes()`).
- Anything that transforms the path **inside** `RouteMatcher` — case folding, a future Unicode normalisation, a canonical-redirect target — operates on the *encoded* string the matcher receives, not the decoded one. Only a transformation that decodes the path as part of its own logic must re-encode the result before handing it back to `match()`; a transformation that never decodes (the trailing-slash variant, for instance) passes its output straight through unchanged. Re-encoding indiscriminately is its own bug: the matcher's `match()` decodes exactly once, so feeding it an already-encoded path re-encoded a second time turns `%2F` into `%252F` and breaks the match, while feeding it a **decoded** string without re-encoding first is the inverse mistake — `rawurldecode('%252e%252e')` returns `%2e%2e`, and the matcher's own decode then turns that into `..`.

## Runtime

[`RouteDispatcher`](../../Classes/Middleware/RouteDispatcher.php) is a frontend middleware, placed after `typo3/cms-frontend/site` and before `typo3/cms-frontend/page-resolver` (see [Middleware placement](../operating/configuration.md#middleware-placement)). It runs these steps in order, and every one of them can end the request:

| # | Step | Outcome when it does not pass |
|---|------|-------------------------------|
| 1 | [Path gate](../operating/configuration.md#path-gate) — could this path belong to any route at all? | Falls through to page rendering, at the cost of one string comparison |
| 2 | [CORS preflight](../features/cors.md) — an `OPTIONS` request announcing a cross-origin call | Answered with `204` before credentials are ever required |
| 3 | Matching, via the compiled matcher | `404` (or the page router, unless an [exclusive prefix](../operating/configuration.md#exclusive-path-prefixes) claims the path) / `405` with an `Allow` header |
| 4 | [Environment filter](../operating/configuration.md#environment-bound-routes) — is the route's `env` the current context? | `404`, as if the route did not exist |
| 5 | [Site/language scope](../routes/route-attribute.md#site--and-language-bound-routes) — is the route reachable from the request's site/language? | `404`, as if the route did not exist |
| 6 | [Canonical redirect](../routes/route-attribute.md#redirecting-instead-of-tolerating) (opt-in) — did the request only match a tolerated path variant? | `308` to the declared path |
| 7 | Request body shape (malformed JSON / unsupported content type) | `400` / `415` |
| 8 | [Query/body requirements](../routes/route-attribute.md#requirements) — path requirements were already enforced by the matcher | `400` |
| 9 | [Rate limit](../features/rate-limiting.md) | `429` with `Retry-After` |
| 10 | [Access control](../features/authentication.md) — authentication, then the CSRF request token | `401` / `403` |
| 11 | Dispatch: resolve the [typed arguments](../routes/arguments.md) and invoke the controller, [response cache](../features/caching.md) permitting | `400` for an unresolvable argument, `404` for a missing entity, `500` for any other exception on a route that [opted into automatic JSON errors](../routes/route-attribute.md#automatic-json-errors-for-jsonresponse-routes) |

Several ordering decisions in there are deliberate rather than incidental:

- **The env filter and site/language scope run before the canonical redirect**, so a redirect never reveals a route that is otherwise invisible in the current context.
- **Rate limiting runs before authentication**, so a coarse per-IP limit absorbs token brute-force attempts before any authentication logic executes.
- **The response cache is consulted after the rate limit**, so a cacheable response cannot be used to bypass the limit.

Every response reaching this decoration stage — a matched dispatch, but also an early error response from step 4 onward above — also gets the [deprecation headers](../routes/route-attribute.md#deprecating-a-route) of the route it came from (a no-op unless it opted in) stamped on afterward. The CORS preflight response (step 2) and a path-gate or matcher miss falling through to page rendering (steps 1 and 3) never reach this stage.

## What every response from this middleware carries

`400`, `401`, `403`, `404`, `405`, `415` and `429` emitted by this middleware, controller-thrown [`HttpProblemException`](../routes/route-attribute.md#error-responses-from-controllers), and the `500` from a route's [automatic JSON errors](../routes/route-attribute.md#automatic-json-errors-for-jsonresponse-routes), are all emitted as [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details: `application/problem+json` with `{"type": "about:blank", "title": …, "status": …, "detail"?: …}`, where `detail` is omitted when it would only repeat the title — the `500` case omits it outside the `Development` application context, since the underlying exception's message is otherwise never surfaced; in `Development`, it's included alongside `exception`, `code`, `file`, `line` and `trace` as extension members (see [automatic JSON errors](../routes/route-attribute.md#automatic-json-errors-for-jsonresponse-routes)). A path that falls through to page rendering (steps 1 and 3) is not this middleware's response, so it is not held to this format. The **success** response format is entirely the controller's choice.

Every response reaching the decoration stage above, success or error, also carries an `X-Request-ID` header — echoed back when the client sent one, otherwise generated, so a single id correlates a request across logs and proxies.

## Reading further

- [`routing:debug` and `routing:match`](../operating/commands.md) read this same compiled registry, so they can never drift from actual runtime behaviour.
- [Performance](performance.md) measures what each of the steps above costs.
- [Extending](extending.md) documents `RouteRegistry` as a public API for tooling built on the compiled metadata.
