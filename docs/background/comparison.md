# How it compares

There are several ways to answer a frontend HTTP request in TYPO3. This extension targets attribute-declared API endpoints — the gap between "too much" (a full page or plugin) and "too manual" (hand-wired middleware).

| Approach | Where it fits | Trade-off this extension avoids |
|----------|---------------|----------------------------------|
| [**`AjaxRoutes.php`**](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Backend/Ajax.html) | Backend (BE) AJAX only | No frontend equivalent exists — this is the FE counterpart |
| [**Custom PSR-15 middleware**](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/RequestLifeCycle/Middlewares.html) | Any frontend request | You hand-wire matching, method/format handling, and duplicate the path in PHP + JS |
| [**`eID` scripts**](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/RequestLifeCycle/Bootstrapping.html) | Lightweight FE endpoints | Procedural entry points, no DI/typed arguments, manual routing and input handling |
| [**Extbase plugin / `typeNum`**](https://docs.typo3.org/m/typo3/reference-typoscript/main/en-us/TopLevelObjects/Page/Index.html) | Content-bound output | Heavyweight for a JSON endpoint; tied to a page and the rendering chain |
| **`typo3_routing`** | Attribute-declared FE endpoints | `#[Route]` on a service method — DI, typed arguments, URL generation, opt-in cache & rate limit |

## When to reach for something else

Three cases where one of the alternatives is the better answer, stated plainly because they do not change:

- **The endpoint belongs to the backend.** `AjaxRoutes.php` is the right mechanism there and this extension does not replace it.
- **The output is a page.** Anything that should participate in TYPO3's rendering chain — TypoScript, content elements, page caching, a URL editors control — belongs in a plugin or a page type, not in a route.
- **The path must be configurable at runtime.** Routes are compiled into the DI container, so declaring one is a code change and publishing it is a cache flush. There is deliberately no runtime registration API and no editor-facing route table.

On raw speed, the hand-wired middleware wins: dispatching a matched attribute route costs about 0.63 ms more than an equivalent middleware doing the matching by hand, which is roughly 3% of a minimal JSON request. Requests that are not routes at all cost about 0.19 ms, nearly all of it building the middleware rather than routing. [Performance](performance.md) has the figures, what was measured, and how to reproduce it.

## Symfony routing features deliberately not adopted

Routes are matched by [`symfony/routing`](https://symfony.com/doc/current/routing.html), so its option surface is the natural yardstick for what [`#[Route]`](../routes/route-attribute.md) should offer. Most of it is there. The following is not, and is not planned. Anything that is merely unscheduled rather than rejected is tracked as an issue instead, so this list stays a list of decisions.

| Symfony feature | Why not |
|---|---|
| `stateless` | Flags a route as session-free so the framework can warn when a session is started anyway. TYPO3 owns session handling upstream of this middleware, leaving nothing here to assert against. |
| `_format` and content negotiation | The controller owns its response format. A route that negotiated a format would also have to build the response, which is exactly the layer this extension does not have. |
| YAML, XML and PHP route loaders | Routes compile into the DI container from attributes. A second source needs its own invalidation and reintroduces the duplicate-path problem that attributes exist to remove. |
| `import()` with path or name prefix | Covered: a class-level `#[Route]` prefixes `path`, `name`, `env`, `requirements` and `defaults` for every method in the class. See [route groups](../routes/route-groups.md). |
| `strict_requirements` | Lets URL generation return `null` instead of throwing when a parameter violates its requirement. A generated URL that silently vanishes is worse than a loud failure, and the cause is always a code-level mistake. |
| Arbitrary `options` passthrough | `options` is Symfony's internal escape hatch (`compiler_class`, `utf8`). Exposing it would let route behaviour hinge on undeclared keys that nothing validates when the container is built. |
| Explicit `utf8` | Derived instead. A route whose path, `host` or `requirements` contain non-ASCII gets the option automatically. Setting it everywhere is not harmless: its `u` modifier makes a request path containing invalid UTF-8 fail the regex rather than simply not match. |
| `#[Route]` on an invokable class | A class-level `#[Route]` is a prefix, never an endpoint. One rule for the class attribute reads better than two that diverge on whether `__invoke()` happens to exist. |

This extension gives you the endpoints; you own the data layer. There is deliberately no record, query, or serialisation layer on top of `#[Route]` — see [Records](../routes/records.md) for the recipe and the reasoning.
