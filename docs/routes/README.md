# Defining routes

An endpoint takes three things, and none of them is configuration:

1. The controller implements the marker interface [`RouteControllerInterface`](../../Classes/Routing/RouteControllerInterface.php).
2. It is registered as a service — autoconfiguration in your `Configuration/Services.yaml` is enough.
3. A public method carries a [`#[Route]`](../../Classes/Attribute/Route.php) attribute.

```php
use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class CourseSearchController implements RouteControllerInterface
{
    public function __construct(/* … injected services … */) {}

    #[Route(path: '/api/course-search/count', name: 'course_search_count')]
    public function count(): ResponseInterface
    {
        return new JsonResponse(['count' => 42]);
    }
}
```

`GET /api/course-search/count` now returns that JSON. The route is discovered when the DI container is built, so a cache flush is all that publishes it.

Two consequences of that shape are worth stating up front, because they explain most of what follows:

- **The method signature is the input contract.** A controller method declares only the parameters it needs — there is no fixed signature. Type-hint `ServerRequestInterface` to receive the request; every other parameter is resolved by name from the path, query string or body and cast to its declared type. See [Typed controller arguments](arguments.md).
- **Contradictions fail at build time, not at request time.** Duplicate route names, unsupported parameter shapes, a `defaults` entry on a non-trailing placeholder, a modifier attribute on a method without a `#[Route]` — all of these stop the container build with an explicit message.

One gap in that second promise is worth knowing about if you [share route definitions through a base class](route-groups.md#declare-the-route-methods-final): a method override that repeats the parent's `#[Route]` but drops a modifier it carried is not detected, because nothing in the build knows what the overridden method declared.

| Page | What's inside |
|------|---------------|
| [The `#[Route]` attribute](route-attribute.md) | Every parameter: `requirements`, priority, optional placeholders, schemes, host, case tolerance, and how a controller returns an error |
| [Route groups](route-groups.md) | A class-level `#[Route]` as a shared prefix, which parameters inherit from it, and sharing route definitions through a base class |
| [Typed controller arguments](arguments.md) | Type coercion, backed enums, Extbase entity binding, variadics, and overriding the source with `#[Param]` |
| [URL generation](url-generation.md) | `routing:uri` / `routing:uris` Fluid ViewHelpers and the PHP generator, so a path is never duplicated |
| [List/detail endpoints for database records](records.md) | A recipe combining the above for exposing a table, and why that stays a recipe rather than a feature |

Once a route exists, everything else is optional: [caching, rate limiting, authentication, request tokens and CORS](../features/README.md) are separate attributes you add next to `#[Route]`, and [`routing:debug`](../operating/commands.md#routingdebug) lists what you have declared.
