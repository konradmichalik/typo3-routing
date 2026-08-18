# OpenAPI export

```bash
vendor/bin/typo3 routing:openapi [--title=…] [--api-version=…] [--server=…] [--pretty]
```

`routing:openapi` turns the compiled registry into an [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.0) document, so the routes stay the single source of truth for your API contract, Swagger UI, and client generators.

```bash
vendor/bin/typo3 routing:openapi                 # compact JSON to stdout
vendor/bin/typo3 routing:openapi --pretty        # pretty-printed
vendor/bin/typo3 routing:openapi --pretty > openapi.json
```

| Option              | Effect                                                            |
| ------------------- | ----------------------------------------------------------------- |
| `--title=…`         | API title (default `TYPO3 Routing API`)                           |
| `--api-version=…`   | API version (default `1.0.0`)                                     |
| `--server=…`        | Server base URL; omitted by default, which per the OpenAPI spec means `/` — correct, since route paths are exported in full |
| `--pretty`          | Pretty-print the JSON                                             |

## What is derived from the attributes

- **Paths & operations** — one operation per HTTP method; `{placeholder}` path segments become path parameters.
- **Parameters & request bodies** — from the [typed method signature](../routes/arguments.md): path/query parameters for `GET`-style routes, a JSON request body for `POST`/`PUT`/`PATCH`. Scalar types map to JSON Schema, backed enums become `enum` schemas, a [`requirements`](../routes/route-attribute.md#requirements) regex becomes a `pattern`, and a [`#[Param(description:)]`](../routes/arguments.md#documenting-the-parameter) becomes the parameter's (or body property's) `description`.
- **Security** — [`#[Authenticate]`](../features/authentication.md) routes reference a matching security scheme (`BearerTokenAuthenticator` → HTTP bearer; FE/BE user → cookie API key). OR-combined authenticators emit multiple requirements.
- **Responses** — a generic `200` plus the error responses each route can actually produce (`400`/`401`/`403`/`404`/`405`/`429`), all served as `application/problem+json` sharing the RFC 9457 `{type, title, status, detail}` `Error` schema.
- **Description & summary** — a route's [`#[Route(description:)]`](../routes/route-attribute.md#description) becomes the operation `description`; if it splits into more than one sentence, the first sentence is also emitted as `summary`. Routes without a `description` fall back to a synthesized one-liner naming the controller, CSRF requirement, and application context.

The exact type-to-schema mapping is documented in [Extending](../background/extending.md#describing-an-argument-as-json-schema), because consumers other than this export need it too.

## Declaring a response schema

The generic `200` above says nothing about a route's response shape — controller methods are all typed `ResponseInterface`, so there is nothing to reflect on for the success case (unlike request parameters, which come from the typed method signature). `#[Returns]` fills that gap:

```php
#[Route(path: '/api/courses/{id}', name: 'course_show')]
#[Returns(CourseDto::class)]
#[Returns(status: 404, description: 'Course not found')]
public function show(int $id): ResponseInterface { /* … */ }
```

- **Repeatable**, one declaration per status code a route can answer with. A declared status **merges with** a generator-derived one instead of living alongside a duplicate — declaring `404` here replaces the generic `Error`-schema `404` with your own description (and schema, if you give one), while `405`/`400`/etc. the route can still produce stay exactly as before.
- **`schema`** is a DTO class-string; its **public properties** (plain or promoted constructor properties — reflection treats both alike) map to an object schema the same way argument types already do — a nested DTO property is mapped recursively, an enum or Extbase-entity property goes through the same rules as [request parameters](../background/extending.md#describing-an-argument-as-json-schema). A non-nullable property without a default is `required`. Pass `schema: null` (the default) for a response with no body, e.g. `204` or a schema-less `404`.
- **`collection: true`** wraps the schema in a JSON array instead of describing a single instance.
- The same DTO class referenced by more than one route produces **one shared `components/schemas` entry**, referenced everywhere by `$ref` — never inlined twice. Two different classes that happen to share a short name is a build-time error, not a silent collision.
- A route without `#[Returns]` produces exactly today's output — nothing changes until you opt in.

## Swagger UI (development only)

A live Swagger UI is a thin, opt-in HTTP layer on top of the same export — no separate spec generation logic:

- `GET /api/_routing/openapi.json` — the OpenAPI document, generated the same way as `routing:openapi`
- `GET /api/_routing/docs` — a minimal HTML page loading Swagger UI from a CDN, pointed at the JSON route above

Both routes are double-gated: they only exist in the `Development` [application context](configuration.md#environment-bound-routes) **and** only when explicitly enabled. Outside either condition they behave as if they don't exist (`404`), same as any other environment-bound route.

| Setting     | Description                                                                                   | Default |
|-------------|-------------------------------------------------------------------------------------------------|---------|
| `swaggerUi` | Serve the Swagger UI (`/api/_routing/docs`) and its OpenAPI JSON document (`/api/_routing/openapi.json`). | `0` (off) |

Set it under **Settings → Extension Configuration → typo3_routing**.

Both are ordinary attribute routes, so the [path gate](configuration.md#path-gate) covers them automatically — no configuration can accidentally make them unreachable. They are excluded from the OpenAPI document itself, since they describe tooling rather than your API surface.

> [!WARNING]
> Never enable `swaggerUi` in a production context. The `Development` gate alone already prevents this in a correctly configured instance, but the flag exists as a second, independent safeguard.
