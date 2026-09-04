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

Routes are matched by [`symfony/routing`](https://symfony.com/doc/current/routing.html), so its option surface is the natural yardstick for what [`#[Route]`](../routes/route-attribute.md) should offer. Most of it is there. The options below are not adopted, and are not planned — either because nothing here could act on them, or because the same result is already reached another way. Anything that is merely unscheduled rather than rejected is tracked as an issue instead, so this list stays a list of decisions.

| Symfony feature | Why not |
|---|---|
| `stateless` | Flags a route as session-free so the framework can warn when a session is started anyway. TYPO3 owns session handling upstream of this middleware, leaving nothing here to assert against. |
| `_format` and content negotiation | Symfony's `_format` matches a path segment and sets the request format on the `Request`; acting on it stays the application's job. Here the controller already returns a finished response and picks its own content type, so a route-level format would set a value nothing downstream reads. |
| YAML, XML and PHP route loaders | Routes compile into the DI container from attributes. A second source needs its own invalidation and reintroduces the duplicate-path problem that attributes exist to remove. |
| `import()` with path or name prefix | Nothing to import from — routes come from attributes, not files. The prefixing it exists for is already available: a class-level `#[Route]` prefixes `path`, `name`, `env`, `requirements` and `defaults` for every method in the class. See [route groups](../routes/route-groups.md). |
| `strict_requirements` | Lets URL generation log an error and return an empty string instead of throwing when a parameter violates its requirement. A generated URL that silently collapses to `""` is worse than a loud failure, and the cause is always a code-level mistake. |
| Arbitrary `options` passthrough | `options` is Symfony's internal escape hatch (`compiler_class`, `utf8`). Exposing it would let route behaviour hinge on undeclared keys that nothing validates when the container is built. |
| Explicit `utf8` | Derived instead. A route whose path, `host` or `requirements` contain non-ASCII gets the option automatically. Setting it everywhere is not harmless: its `u` modifier makes a request path containing invalid UTF-8 fail the regex rather than simply not match. |
| `#[Route]` on an invokable class | A class-level `#[Route]` is a prefix, never an endpoint. One rule for the class attribute reads better than two that diverge on whether `__invoke()` happens to exist. |

## Laravel routing features deliberately not adopted

[Laravel's router](https://laravel.com/docs/12.x/routing) is the other yardstick worth holding this against, not because anything here depends on it, but because it is what a PHP developer usually pictures when they hear "attribute-declared API endpoint". Most of its route surface has an equivalent here, and several parts of it (per-route CORS, compile-time validation, no manual route cache to warm) are answered more strictly. The following is not, and is not planned. As above, anything merely unscheduled is an issue instead.

| Laravel feature | Why not |
|---|---|
| `Route::view()` | Renders a template for a path with no controller in between. That is a page, and pages belong to TYPO3's rendering chain rather than to a route. See [when to reach for something else](#when-to-reach-for-something-else). |
| `Route::redirect()`, `permanentRedirect()` | The case that actually occurs is a renamed or retired endpoint, and [`canonical`, `legacyPaths` and `#[DeprecatedRoute]`](../routes/route-attribute.md#legacy-paths-for-renamed-routes) already redirect it inside the dispatcher. A controller-less route declaration would be a second shape of route for the one case that is covered. |
| Form method spoofing (`_method`) | Takes the intended verb from a field in a POST body so an HTML form can reach a `PUT` or `DELETE` route. It makes the method a property of the payload instead of the request, which weakens both method gating and the CSRF reasoning behind [`#[RequireRequestToken]`](../features/authentication.md). A browser form posting into TYPO3 is a plugin's job. |
| Resource controllers (`Route::apiResource`, singleton resources) | Derives a set of CRUD routes from a model name. It presumes the record, query and serialisation layer this extension deliberately does not have; the same ground is covered explicitly by the [records recipe](../routes/records.md). |
| Per-route middleware (`->middleware()`, `withoutMiddleware()`) | Route features here are a fixed set of attributes running in a [documented order](../features/README.md#where-they-take-effect), two positions of which carry a security decision (rate limit before authentication, response cache last). An arbitrary middleware list per route would turn that order into a per-route property, and the guarantee is the point. |
| Scoped bindings | Infers a relationship from parameter names and constrains the child query by its parent. Extbase has no such convention to infer from, and an ownership check that happens implicitly is precisely the [access control that has to be explicit](../routes/arguments.md#entity-resolution). |
| `Route::pattern()` (global constraints) | Applies a regex to every route using a given parameter name, wherever it was declared. Class-level `requirements` cover the group case with a visible owner. A rule that silently constrains routes in another extension is the opposite of what build-time validation buys. |
| `throttleWithRedis` | A storage choice, not a routing feature. [Rate limiting](../features/rate-limiting.md) is backed by the TYPO3 caching framework, so its backend is configured where every other cache backend is. |
| `route:cache`, `route:clear` | Nothing to add: routes bake into the DI container when it is built, so a cache flush is what publishes them and there is no second cache to warm or clear. [`routing:debug`](../operating/commands.md#routingdebug) is the counterpart to `route:list`. |

This extension gives you the endpoints; you own the data layer. There is deliberately no record, query, or serialisation layer on top of `#[Route]` — see [Records](../routes/records.md) for the recipe and the reasoning.
