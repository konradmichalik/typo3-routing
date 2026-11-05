# Case-insensitive paths and canonical redirects

A route matches its declared path exactly, apart from the trailing slash, which is tolerated by default (see [Trailing slashes](../operating/configuration.md#trailing-slashes)). `caseInsensitive` widens that tolerance to letter case, and `canonical` turns any tolerated variant into a `308` redirect to the declared path.

Table of contents:

- [Case-insensitive paths](#case-insensitive-paths)
- [Redirecting instead of tolerating](#redirecting-instead-of-tolerating)

## Case-insensitive paths

URL paths are case-sensitive by [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986#section-6.2.2.1), and that is the default here. A single route can opt into case-insensitive matching, for instance when a legacy client or a hand-typed URL varies the casing:

```php
#[Route(path: '/api/courses/{slug}', name: 'course_show_slug', caseInsensitive: true)]
public function show(string $slug): ResponseInterface { /* … */ }
```

```text
/api/courses/Intro-To-Php  →  match, $slug = 'Intro-To-Php'
/API/Courses/Intro-To-Php  →  match, $slug = 'Intro-To-Php'
/ApI/CoUrSeS/Intro-To-Php  →  match, $slug = 'Intro-To-Php'
```

Three things this deliberately does **not** do:

- **Placeholder values are never folded.** The tolerance covers the path's literal segments only, so `{slug}` reaches the controller exactly as it was sent. Lower-casing the whole path would silently corrupt identifiers.
- **`requirements` stay case-sensitive.** `['slug' => '[a-z-]+']` still rejects `Intro-To-Php`, opted in or not. The tolerance is about finding the route, never about relaxing its constraints. Since the client just sees a `404`, [`routing:match`](../operating/commands.md#routingmatch) reports this case separately instead of claiming no route matched.
- **No redirect is issued by default.** Both forms answer directly, exactly as with [trailing slashes](../operating/configuration.md#trailing-slashes). Add `#[Route(canonical: true)]` to redirect a differently-cased request to the declared path instead, see [Redirecting instead of tolerating](#redirecting-instead-of-tolerating).

Generated URLs (`{routing:uri(...)}`, `RouteUrlGenerator`) always use the declared casing.

Nothing is opted in by default, and the extra matching pass runs only after the exact path has already failed, so routes that do not use it are unaffected.

The same opt-in applies to [`host`](host-and-scope.md#host)'s literal labels: a route combining `caseInsensitive: true` with a `host` constraint also accepts mixed-case host input. Host placeholders and their `requirements` are unaffected, same as for path placeholders above.

## Redirecting instead of tolerating

Answering both forms directly is right for API clients, who should not pay a second round trip. For a route serving HTML or a download, the same tolerance produces two URLs for one resource, which fragments caches and splits search ranking. `#[Route(canonical: true)]` opts a route into the other behaviour: a request that only matched a tolerated variant (trailing slash, or case via `caseInsensitive`) gets a `308 Permanent Redirect` to the declared path instead of the response.

```php
#[Route(path: '/downloads/report', name: 'report', canonical: true)]
```

```text
/downloads/report      →  served directly
/downloads/report/     →  308 → /downloads/report
```

`308` rather than `301` or `302`, because it preserves the request method and body — a tolerated `POST` is never silently downgraded to `GET`. A route with placeholders redirects to the concrete resolved path, never to the `{id}` template, and the query string carries over unchanged. `405` still wins over the redirect: a path that matches with the wrong method answers `405` regardless of which variant it was. Nullable and class-level inheritance work exactly like `caseInsensitive`. Not opting in (the default) keeps today's behaviour: both forms answered directly, no redirect.
