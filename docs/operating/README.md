# Operating it

Routes work with **no configuration at all**. The gate that keeps matching off the hot path for ordinary page requests is derived from your `#[Route]` paths at container build time, so it cannot be forgotten or set wrong. Everything under Configuration changes behaviour you may or may not want.

What is worth knowing before an installation goes live:

- **Nothing is cached separately.** Route discovery rides on the DI container cache, so a cache flush is the only publishing step.
- **Unmatched paths fall through to page rendering by default.** If API clients should get a machine-readable `404` for a mistyped endpoint instead of the TYPO3 error page, that is [`exclusivePrefixes`](configuration.md#exclusive-path-prefixes), and it is the one setting most API installations end up wanting.
- **Secrets stay out of the configuration.** Only the *name* of the environment variable holding a bearer token is ever stored, never its value — see the [deployment notes](../features/authentication.md#deployment-notes-bearer) for the three things that reliably break in the transition to production.

| Page | What's inside |
|------|---------------|
| [Configuration](configuration.md) | Every extension setting in one table, plus the derived path gate, trailing slashes, exclusive prefixes, environment-bound routes and middleware placement |
| [Console commands](commands.md) | `routing:debug` to list, filter and audit routes, `routing:match` to ask which route claims a given path |
| [OpenAPI export](openapi.md) | `routing:openapi`, what it derives from your attributes, and the development-only Swagger UI |

All three commands read the same compiled registry the dispatcher uses, so what they report is what a request will do.
