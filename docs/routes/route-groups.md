# Route groups

```php
#[Route(path: '/api/v1/courses', name: 'v1_courses_')]
final class CourseController implements RouteControllerInterface { /* … */ }
```

Placing `#[Route]` on the **controller class** turns it into a prefix shared by every method route — handy for grouping related endpoints, or for versioning an API across `/api/v1` and `/api/v2`. At most one class-level `#[Route]` is allowed.

```php
use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ResponseInterface;

#[Route(path: '/api/v1/courses', name: 'v1_courses_', requirements: ['id' => '\d+'])]
final class CourseController implements RouteControllerInterface
{
    // → GET /api/v1/courses/{id}, route name "v1_courses_course_show"
    #[Route(path: '/{id}', name: 'course_show')]
    public function show(int $id): ResponseInterface { /* … */ }

    // → GET /api/v1/courses, route name "v1_courses_course_list"
    #[Route(path: '', name: 'course_list')]
    public function list(): ResponseInterface { /* … */ }
}
```

## How the values combine

Not every [`#[Route]` parameter](route-attribute.md) inherits the same way, and the differences are deliberate rather than incidental:

| Parameter      | Combination                                                                                   |
|----------------|-----------------------------------------------------------------------------------------------|
| `path`         | Class path is **prepended** to each method path.                                              |
| `name`         | Class name is **prepended** to each resolved method name (auto-derived name still applies).   |
| `env`          | Used as the **default** for methods that do not set their own `env`; a method `env` wins.     |
| `requirements` | **Merged** with method requirements; the method wins per key.                                 |
| `defaults`     | **Merged** with method defaults; the method wins per key.                                     |
| `description`  | Used as the **default** for methods that do not set their own `description`; a method `description` wins. |
| `caseInsensitive` | Used as the **default** for methods that do not set their own value; a method can opt back out with `false`. |
| `methods`      | **Ignored** at class level — the method default (`['GET']`) is indistinguishable from "unset". |
| `schemes`, `host` | **Not inherited** — same rule as `methods`.                                                |
| `exclusive`    | **Class-level only** — a value on a method-level `#[Route]` is a build-time error.             |

The three in the middle are the ones worth remembering: `methods`, `schemes` and `host` are never inherited, because a class-level value could not be told apart from an unset one, and silently applying `['GET']` (or an HTTPS-only constraint) to every method in a controller would be the wrong default.

## Exclusive class-level claim

A controller class can claim its own prefix exclusively, without touching extension configuration: `#[Route(path: '/api/reports', exclusive: true)]` on the class claims `/api/reports` — a request whose path is nested under it and matches none of the class's own routes gets a JSON `404`, scoped to that one class instead of a global setting (see [Exclusive path prefixes](../operating/configuration.md#exclusive-path-prefixes) for the extension-configuration equivalent). The claim is bound to that path segment: a sibling path that merely starts with the same characters (e.g. `/api/reports-archive`) is unaffected, unlike a hand-written `exclusivePrefixes` entry, where the operator is responsible for a trailing `/` if that boundary matters. It only takes effect on the class-level `#[Route]`; setting it on a method route is a build-time error, and a class path with no static prefix beyond the root (e.g. one starting with a placeholder, or one that is empty or just `/`) is rejected at build time too, since it would otherwise claim every unmatched path site-wide.

> [!NOTE]
> A class combining `exclusive: true` with [`caseInsensitive: true`](route-attribute.md#case-insensitive-paths) claims only the path's declared casing today: `/api/reports/unknown` gets the JSON `404`, but `/API/REPORTS/unknown` falls through to page rendering instead. Known limitation, not yet implemented.

## Sharing routes through an abstract base controller

Route discovery reflects the concrete controller's public methods, including ones inherited from a parent class, so an abstract base controller can declare the route methods once while each concrete subclass supplies only its own class-level prefix.

A method path of `''` (e.g. `#[Route(path: '', name: 'course_list')]` above) needs the class-level prefix to resolve to something non-empty. Without a class prefix, `path` would resolve to the empty string, which Symfony silently normalizes to `/` — claiming the site's root ahead of TYPO3's own page rendering. The compiler pass rejects this at build time.

PHP does not carry method attributes onto an override, so overriding an inherited route method without repeating its `#[Route]` silently removes that route, and repeating `#[Route]` while dropping a modifier such as `#[Authenticate]` silently removes only that modifier. Both are caught at build time with a warning naming the overriding method and the parent method it overrides; a controller that ends up with no route at all (for example because every inherited route method was overridden this way) is warned about separately.

## What else can sit on the class

Of the [opt-in feature attributes](../features/README.md), only [`#[Cors]`](../features/cors.md#per-route-overrides-with-cors) is inheritable in the same way. `#[Authenticate]`, `#[Cache]` and `#[RequireRequestToken]` are method-only by design — a protection or a cache policy that silently extends to every method added later is not a safe default.

## Sharing route definitions through a base class

Route discovery reflects the **public methods of the concrete controller**, and those include methods inherited from a parent class. An abstract base class can therefore hold the route definitions while each concrete controller supplies nothing but its own prefix.

```php
abstract class ResourceController implements RouteControllerInterface
{
    #[Route(path: '', name: 'list')]
    final public function list(#[Param(requirement: '\d+')] int $page = 1): ResponseInterface { /* … */ }

    #[Route(path: '/{uid}', name: 'detail', requirements: ['uid' => Requirement::DIGITS])]
    final public function detail(int $uid): ResponseInterface { /* … */ }

    /** @return list<string> */
    abstract protected function fields(): array;
}

// → /api/products and /api/products/{uid}, names "products_list" and "products_detail"
#[Route(path: '/api/products', name: 'products_')]
final class ProductController extends ResourceController { /* … */ }

// → /api/news and /api/news/{uid}, names "news_list" and "news_detail"
#[Route(path: '/api/news', name: 'news_')]
final class NewsController extends ResourceController { /* … */ }
```

The abstract class contributes no routes of its own: Symfony's service autodiscovery tags abstract classes as `container.excluded`, and the compiler pass skips them a second time. Everything else is resolved per subclass. Paths and names are prefixed, `requirements` and [`#[Param]`](arguments.md#declaring-the-constraint-at-the-parameter) contributions are hoisted separately for each one, and a `#[Cache]` or `#[RateLimit]` on the parent method applies to every subclass that inherits it.

Two rules separate this working from it failing quietly.

### Every concrete controller needs its own class-level `#[Route]`

Class attributes are not inherited. A `#[Route]` on the abstract parent is ignored for the subclass, and its prefix disappears without a message.

The `name` prefix is what keeps two subclasses apart. Without it both inherit the same route name and the container build stops with `Duplicate attribute route name`. That is the good failure: loud, and at build time.

The `path` prefix has no such safety net. If the base declares `#[Route(path: '')]` for a list route and a subclass forgets its class-level `#[Route]`, that route's path is `''`. Symfony's `Route::setPath()` silently normalizes an empty path to `/`, so the route does not merely open the [path gate](../operating/configuration.md#path-gate) for every frontend request, it answers the site's root path, ahead of TYPO3's own page rendering.

### Declare the route methods `final`

PHP does not carry method attributes onto an override. A subclass that overrides an inherited route method to adjust its behaviour **removes the route**: no attribute, no route, no warning.

Repeating the `#[Route]` on the override restores the endpoint but not the parent's other attributes. An override that repeats `#[Route]` while omitting the parent's `#[Authenticate]` is a public endpoint with a green build. The [orphaned-modifier check](README.md) cannot catch it: it fires when a modifier sits on a method without a `#[Route]`, not when a `#[Route]` arrives without the modifier the parent method had.

Marking the route methods `final` turns that mistake into a PHP fatal error instead of a silent change in behaviour. Variation belongs in `abstract protected` hooks, which carry no attributes and can be overridden freely.
