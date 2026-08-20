# Background

Nothing here is needed to use the extension. These four pages answer the questions that come after it already works: whether it was the right choice, what it does underneath, what that costs, and how to build something on top of it.

| Page | What's inside |
|------|---------------|
| [How it compares](comparison.md) | When to reach for this versus `AjaxRoutes`, custom middleware, `eID`, or Extbase plugins |
| [How it works](how-it-works.md) | Compile-time discovery, the runtime dispatch pipeline step by step, and the response contract every route shares |
| [Performance](performance.md) | What a matched route costs against a hand-written middleware, what an unrelated page request costs, and how to reproduce both |
| [Extending](extending.md) | Reading the compiled metadata via `RouteRegistry`, invoking a route without HTTP via `RouteInvoker`, and the `@api`/`@internal` BC promise |

The short version of all four: routes are compiled into the DI container once, a matched request costs about 0.63 ms more than a hand-written middleware, an unrelated page request costs about 0.19 ms (nearly all of it building the middleware, not routing), and `RouteRegistry` is a supported API that satellite packages can build on.
