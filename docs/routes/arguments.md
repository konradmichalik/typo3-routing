# Typed controller arguments

Instead of reading values off the request by hand, declare them as **typed method parameters**. The extension reflects each routed method's signature at container-compile time and resolves the arguments at dispatch:

| Parameter shape                   | Resolved from                          |
|-----------------------------------|----------------------------------------|
| `ServerRequestInterface $request` | The PSR-7 request itself.              |
| A name matching a `{placeholder}` | The matched path segment.              |
| Any other scalar name             | Query string, then request body.       |

Values are coerced to the declared type (`int`, `float`, `bool`, `string`, `array`, `mixed`; untyped = raw string) — including **backed enums**. A value that cannot be coerced, or a missing parameter without a default, yields a **400** before the controller runs. Optional parameters use their PHP default; nullable parameters become `null` when absent.

> [!NOTE]
> The request **body** is read as form fields for `application/x-www-form-urlencoded`/`multipart` POSTs, and decoded from the raw stream for `application/json` requests — so JSON payloads (and any `PUT`/`PATCH` body) bind to parameters the same way. The body stream stays rewound, so a controller that injects `ServerRequestInterface` can still read it.

```php
#[Route(path: '/api/courses/{id}', name: 'course_show', requirements: ['id' => '\d+'])]
public function show(int $id, int $page = 1, ?string $sort = null, ServerRequestInterface $request): ResponseInterface
{
    // $id   ← path placeholder, cast to int
    // $page ← ?page=… query param, defaults to 1
    // $sort ← ?sort=… query param, null when omitted
    // $request ← the full request, still available when you need headers/body
    // …
}
```

> [!NOTE]
> [`requirements`](route-attribute.md#requirements) validates the *format* (regex) of inputs and runs first; typed parameters handle the *type* mapping. Use them together: a placeholder constrained by `requirements: ['id' => '\d+']` plus an `int $id` parameter gives you a guaranteed, type-safe value.
>
> Unsupported parameter shapes (union/intersection types, non-request objects, pure non-backed enums) are rejected at compile time with a clear `LogicException`, so misuse surfaces during container build, not at runtime.

## Backed enums

A **backed enum** parameter is resolved from its backing value (string-compared, so `?priority=5` resolves an `int`-backed case). An unknown value yields a **400**.

```php
enum Status: string { case Active = 'active'; case Inactive = 'inactive'; }

#[Route(path: '/api/users/{status}', name: 'users_by_status')]
public function byStatus(Status $status): ResponseInterface
{
    // /api/users/active → Status::Active
}
```

## Entity resolution

A parameter typed as an **Extbase domain object** — any class implementing `TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface` — resolves to the hydrated record. The raw identifier is read using the same source rules as any other parameter (path placeholder, query, or body), then looked up via TYPO3's `PersistenceManagerInterface::getObjectByIdentifier()` — no repository wiring needed.

```php
use MyVendor\MyExtension\Domain\Model\News;

#[Route(path: '/api/news/{news}', name: 'news_show')]
public function show(News $news): ResponseInterface
{
    // /api/news/42 → the News record with uid 42, already hydrated
}
```

A malformed identifier (not an integer) yields a **400**, same as an invalid `int` parameter. A well-formed identifier with no matching record yields a **404** — regardless of whether the parameter is nullable; nullability only governs a *missing* input, not one that fails to resolve. Variadic entity parameters (`News ...$items`) are rejected at compile time.

> [!NOTE]
> `getObjectByIdentifier()` respects Extbase's enable-fields (a hidden/deleted record resolves as **404**), but does not restrict by storage page or apply a workspace overlay — a record on any page/pid is resolvable by uid.

> [!WARNING]
> Entity binding resolves **any** valid identifier — it is a lookup, not an authorization check. A client can request `/api/news/{news}` for any uid, so a route exposing user- or tenant-scoped records must enforce access itself: guard it with [`#[Authenticate]`](../features/authentication.md) and verify ownership in the controller. Treat the resolved object like any untrusted `id` parameter (an IDOR risk if left unchecked).

Building a list *and* detail endpoint over the same table is common enough to have its own recipe: see [Records](records.md).

## Variadics

A **variadic** parameter collects zero or more values from a single input array (`?ids[]=1&ids[]=2`), each coerced to the element type. An absent input yields no arguments.

```php
#[Route(path: '/api/courses', name: 'courses_filter')]
public function filter(int ...$ids): ResponseInterface
{
    // /api/courses?ids[]=3&ids[]=7 → filter(3, 7)
}
```

## Overriding the source with `#[Param]`

By default the lookup key is the parameter name and the source is auto-derived. The [`#[Param]`](../../Classes/Attribute/Param.php) attribute overrides these, and can state the parameter's constraint next to the parameter itself:

| Argument      | Description                                                            |
|---------------|------------------------------------------------------------------------|
| `name`        | Read a different input/path key than the parameter name.               |
| `source`      | Pin the source: `path`, `query`, `body` (form or JSON), or `input` (query + body). |
| `requirement` | Regex the value must satisfy, equivalent to a [`requirements`](route-attribute.md#requirements) entry on the `#[Route]`. |
| `description` | Human-readable summary of the parameter, surfaced in the [OpenAPI export](../operating/openapi.md). |

```php
use KonradMichalik\Typo3Routing\Attribute\Param;

#[Route(path: '/api/search', name: 'search')]
public function search(
    #[Param(name: 'q')] string $term,        // reads ?q=… into $term
    #[Param(source: 'body')] int $page = 1,  // only from the request body (form or JSON)
): ResponseInterface {
    // …
}
```

### Declaring the constraint at the parameter

A `requirement` is folded into the route's `requirements` at build time, keyed by the **wire name** — so with `#[Param(name: 'q', requirement: '\w+')] string $term` the constraint lands under `q`, not `term`. Enforcement, `routing:debug` and the OpenAPI export are therefore identical to declaring it on the `#[Route]`; only the place you write it differs.

The reason to prefer it is that a `#[Param]` also carries the parameter's **PHP default** into the route:

```php
// Equivalent to requirements: ['page' => '\d+'], defaults: ['page' => 1] on the #[Route] —
// but the signature now states the optionality itself.
#[Route(path: '/api/blog/{page}', name: 'blog')]
public function blog(#[Param(requirement: '\d+')] int $page = 1): ResponseInterface { /* … */ }
```

Two rules follow from this:

- **A parameter with a `#[Param]` requirement *and* a PHP default is optional but still constrained.** A missing value falls back to the default instead of yielding a `400`; a value that *is* present is still checked against the regex. On the `#[Route]` a non-path requirement is always mandatory, so this combination can only be expressed here — and only the `#[Param]`'s own requirement is relaxed: `#[Route(requirements: ['page' => '\d+'])]` on `int $page = 1` stays mandatory, PHP default or not.
- **Only `#[Param]`-carrying parameters contribute.** A parameter without the attribute behaves exactly as before — a PHP default alone never makes a path placeholder optional.

`name` is resolved *before* the source is inferred, so the lookup key decides where the value comes from: `#[Param(name: 'page')] int $number` on a `/api/blog/{page}` route reads the **path** placeholder, and its default is hoisted like any other.

### Documenting the parameter

`description` is the per-parameter counterpart to `#[Route(description:)]`, which summarises the *operation*. It reaches the OpenAPI document as the parameter's `description` — or, for a body parameter, as the `description` of its property in the request-body schema. Parameters without one stay free of the key entirely.

```php
#[Route(path: '/api/courses', name: 'courses_filter')]
public function filter(
    #[Param(requirement: '\d+', description: 'Page number, 1-based.')] int $page = 1,
): ResponseInterface { /* … */ }
```

Rejected at build time, so the route definition never contradicts itself:

- A key **constrained** on both the method's `#[Route]` and a `#[Param]`, or **defaulted** on both — the two could disagree, and the `#[Param]` would silently win. A *class-level* `#[Route]` requirement or default is only a base and stays overridable.
- `requirement: ''` on a defaulted parameter — `''` means "must be present", which the default contradicts.
- A default on a placeholder that is not at the end of the path — only a trailing placeholder can become optional, so the default would be silently inert.
