# URL Generation

Use the `routing` Fluid ViewHelper to generate URLs — no need to hardcode the path as a PHP constant and a separate JS string:

```html
<a href="{routing:uri(route: 'course_search_count')}">Count</a>

<script>
    const countUrl = '{routing:uri(route: \'course_search_count\')}';
</script>
```

With path parameters:

```html
{routing:uri(route: 'course_search_item', parameters: '{id: 5}')}
```

Need several URLs in JavaScript at once? `routing:uris` renders a JSON map of the routes you name — the controlled, opt-in counterpart to the core's `TYPO3.settings.ajaxUrls` (you choose what to expose, nothing is injected globally):

```html
<script>
    window.routingUrls = {routing:uris(routes: {
        count: 'course_search_count',
        item:  'course_search_item'
    })};
    // → {"count":"/api/course-search/count","item":"/api/course-search/item"}
</script>
```

Generated URLs automatically include the current site/language base, so they are reachable as-is.

> [!TIP]
> In PHP, inject [`RouteUrlGenerator`](../Classes/Http/RouteUrlGenerator.php) and call `generate($request, $routeName, $parameters)`.

```php
use KonradMichalik\Typo3Routing\Http\RouteUrlGenerator;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CourseLinkProvider
{
    public function __construct(
        private RouteUrlGenerator $urlGenerator,
    ) {}

    public function courseUrl(ServerRequestInterface $request, int $id): string
    {
        // e.g. "/api/courses/5" — already includes the current site/language base.
        return $this->urlGenerator->generate($request, 'course_show', ['id' => $id]);
    }
}
```
