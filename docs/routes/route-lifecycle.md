# Renaming and deprecating a route

Two tools for changing a route that clients already use: `legacyPaths` keeps an old path reachable after a rename, and `#[DeprecatedRoute]` announces the end of a route through standard response headers.

Table of contents:

- [Legacy paths for renamed routes](#legacy-paths-for-renamed-routes)
- [Deprecating a route](#deprecating-a-route)

## Legacy paths for renamed routes

Renaming a route's path breaks any client that hardcoded the old one. `#[Route(legacyPaths:)]` keeps the old path reachable through the same route:

```php
#[Route(path: '/api/courses', name: 'courses_index', legacyPaths: ['/api/course-search/count'])]
```

By default a legacy path `308`s to the declared path — the same mechanism and the same reasoning as `canonical` above, but the decision is governed by its own `legacyAlias` flag, independent of whether the route separately opted into `canonical`:

```text
/api/courses                     →  served directly
/api/course-search/count         →  308 → /api/courses
```

For a client that cannot follow redirects, `legacyAlias: true` answers every legacy path directly instead:

```php
#[Route(path: '/api/courses', name: 'courses_index', legacyPaths: ['/api/course-search/count'], legacyAlias: true)]
```

A legacy path is never canonical: it is invisible to `routing:debug`'s route list and the OpenAPI export (both list only the declared path; `routing:debug <name>` shows the legacy paths in the detail view of the route that owns them), and URL generation (`{routing:uri()}`, `RouteUrlGenerator`) never produces one. It costs nothing for a route that declares none — matching only tries the legacy paths once every other tier has already failed, mirroring the case-insensitive fallback. `405` beats the redirect exactly as it does for a tolerated variant, and a legacy path may not collide with any declared route path or with another route's legacy path — either fails the container build. A site/language-scoped route stays out of scope through its legacy path too. Combined with [`#[DeprecatedRoute]`](#deprecating-a-route), a request that reaches the route through its old path carries the same `Deprecation`/`Sunset` headers as any other request to that route, since the dispatcher resolves both to the same route identity.

Legacy paths do not inherit the owning route's [`caseInsensitive`](path-tolerance.md#case-insensitive-paths) tolerance — they match exactly as declared, and are not inherited from a class-level `#[Route]` (same rule as `methods`).

## Deprecating a route

`#[DeprecatedRoute]` is a separate, non-repeatable attribute — not a `#[Route]` parameter — so it stays optional and out of the way for routes that never need it:

```php
#[Route(path: '/api/v1/courses', name: 'courses_v1')]
#[DeprecatedRoute(since: '2026-03-01', sunset: '2026-12-31', successor: 'courses_v2', documentation: 'https://example.com/migrate-to-v2')]
public function indexV1(): ResponseInterface { /* … */ }
```

| Parameter       | Type          | Default | Description |
|-----------------|---------------|---------|--------------|
| `since`         | `string`      | –       | When the route became deprecated. Any format `DateTimeImmutable` accepts (e.g. `'2026-03-01'`); rejected at build time if unparseable. |
| `sunset`        | `?string`     | `null`  | When the route stops being supported, same format as `since`. Must not precede `since` — rejected at build time, naming the route. |
| `successor`     | `?string`     | `null`  | Route name replacing this one, resolved through `RouteUrlGenerator`. An unknown route name fails the build. |
| `documentation` | `?string`     | `null`  | URL with migration guidance. |

Every response the route produces — success, a cached hit, a conditional `304`, or any `4xx` further down the gauntlet — carries:

| Header | Format | Note |
|--------|--------|------|
| `Deprecation` | `@1772323200` | [RFC 9745](https://www.rfc-editor.org/rfc/rfc9745) section 2 — an Item Structured Field Date, `@` plus a Unix timestamp. **Never** an HTTP-date; early drafts of the RFC used one, and that is the standard implementation mistake. |
| `Sunset` | `Thu, 31 Dec 2026 23:59:59 GMT` | [RFC 8594](https://www.rfc-editor.org/rfc/rfc8594) section 3 — an HTTP-date, a different format for historical reasons. Omitted when `sunset` is not given. |
| `Link: …; rel="successor-version"` | — | Present when `successor` is given. |
| `Link: …; rel="deprecation"` | — | Present when `documentation` is given. Both `Link` values ride in the same header when both are given. |

The same declaration also sets `deprecated: true` on the OpenAPI operation, and shows in `routing:debug` (detail view and `--json`; `--deprecated` filters the table to only these routes).

At class level, `#[DeprecatedRoute]` applies to every method route without its own — same rule as [`#[Cors]`](../features/cors.md#per-route-overrides-with-cors) and `description` above; a method's own attribute wins entirely rather than merging field by field. There is no `410 Gone` after `sunset` passes: [RFC 9745](https://www.rfc-editor.org/rfc/rfc9745) is explicit that deprecation is a hint, and turning an endpoint off has to stay a deliberate, separate act.
