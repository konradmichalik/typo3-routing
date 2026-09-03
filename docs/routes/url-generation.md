# URL generation

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

## Absolute URLs

Both ViewHelpers take an `absolute` flag. It adds the scheme and host in front of the very same path, base included:

```html
{routing:uri(route: 'course_search_count', absolute: 1)}
<!-- → https://example.com/sub/api/course-search/count -->

{routing:uris(routes: {count: 'course_search_count'}, absolute: 1)}
```

Use it wherever the URL leaves the page it was rendered on: mail templates, JSON payloads consumed elsewhere, feeds.

Scheme and host come from the current request, not from the site configuration, so a site reachable under several domains keeps producing links to the one the visitor is actually on. A route that constrains [`schemes`](route-attribute.md#schemes) or [`host`](route-attribute.md#host) still overrides both, with or without the flag: it already forces the absolute form on its own.

> [!NOTE]
> Because the host is the request's, an absolute URL rendered this way is only as trustworthy as the incoming `Host` header — TYPO3's `trustedHostsPattern` is what keeps that honest. For a URL that has to outlive the request (a mail body, a stored payload), prefer the request-less form below, where the host comes from the site configuration.

## Without a request

`generateForSite()` resolves the context from a TYPO3 `Site` instead of a request, for CLI commands, scheduler tasks, queue workers and mail rendered outside a frontend request:

```php
$this->urlGenerator->generateForSite($site, 'course_show', ['id' => 5], absolute: true);
// → "https://example.com/sub/api/courses/5"
```

There is no current request to read a scheme and host from in that context, so **the site's configured base is the sole authority** for both. Pass a `SiteLanguage` to generate against that language's base instead:

```php
$this->urlGenerator->generateForSite($site, 'course_show', ['id' => 5], absolute: true, language: $site->getLanguageById(1));
```

A site base configured as a bare path (`base: /`) carries neither scheme nor host, so nothing can be made absolute from it — such a base can only ever yield a path.

## In PHP

> [!TIP]
> Inject [`RouteUrlGenerator`](../../Classes/Http/RouteUrlGenerator.php) and call `generate($request, $routeName, $parameters, $absolute)`.

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

    public function absoluteCourseUrl(ServerRequestInterface $request, int $id): string
    {
        // e.g. "https://example.com/api/courses/5".
        return $this->urlGenerator->generate($request, 'course_show', ['id' => $id], true);
    }
}
```
