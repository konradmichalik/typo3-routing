# Documentation

Four groups, roughly in the order you meet them: declare a route, add the behaviour it needs, run it in an installation, understand what it does underneath.

New here? [Defining routes](routes/README.md) is the only group you need to read to ship an endpoint. Everything in [Route features](features/README.md) is opt-in, and [Configuration](operating/configuration.md) has no required setting.

## [Defining routes](routes/README.md)

The everyday surface: what you write on a controller to make an endpoint exist.

| Page | What's inside |
|------|---------------|
| [The `#[Route]` attribute](routes/route-attribute.md) | Every parameter at a glance, plus `requirements`, priority, optional placeholders, description and the matched route name |
| [Hosts, schemes, sites and languages](routes/host-and-scope.md) | Restricting where a route answers: `schemes` with its redirect, `host` with wildcards, `sites` and `languages` |
| [Case-insensitive paths and canonical redirects](routes/path-tolerance.md) | `caseInsensitive` and `canonical`: tolerating a variant of the path, or redirecting it to the declared one |
| [Renaming and deprecating a route](routes/route-lifecycle.md) | `legacyPaths` for an old path after a rename, `#[DeprecatedRoute]` with its standard headers |
| [Error responses from controllers](routes/error-responses.md) | `HttpProblemException` for expected failures, automatic JSON errors for `JsonResponse` routes |
| [Route groups](routes/route-groups.md) | A class-level `#[Route]` as a shared prefix, which parameters inherit from it, and sharing route definitions through a base class |
| [Typed controller arguments](routes/arguments.md) | Type coercion, backed enums, Extbase entity binding, variadics, uploaded files, and overriding the source with `#[Param]` |
| [URL generation](routes/url-generation.md) | `routing:uri` / `routing:uris` Fluid ViewHelpers and the PHP generator, so a path is never duplicated |
| [List/detail endpoints for database records](routes/records.md) | A recipe combining the above for exposing a table, and why that stays a recipe rather than a feature |
