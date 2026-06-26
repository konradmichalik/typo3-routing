<div align="center">

![Extension icon](Resources/Public/Icons/Extension.png)

# TYPO3 extension `typo3_routing`

[![Latest Stable Version](https://typo3-badges.dev/badge/typo3_routing/version/shields.svg)](https://extensions.typo3.org/extension/typo3_routing)
[![Supported TYPO3 versions](https://typo3-badges.dev/badge/typo3_routing/typo3/shields.svg)](https://extensions.typo3.org/extension/typo3_routing)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/typo3-routing/php?logo=php)](https://packagist.org/packages/konradmichalik/typo3-routing)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-routing/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/typo3-routing/actions/workflows/cgl.yml)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-routing/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/typo3-routing/actions/workflows/tests.yml)
[![License](https://poser.pugx.org/konradmichalik/typo3-routing/license)](LICENSE.md)

</div>

This extension lets you register **frontend endpoints via PHP attributes** on controller methods — the attribute-based counterpart to the backend-only [`Configuration/Backend/AjaxRoutes.php`](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Backend/AjaxControllers.html). It is response-format agnostic: return JSON, HTML, XML, or a download.

- [**Attribute routing**](#-usage) — declare an endpoint with `#[Route]` directly on a controller method
- [**Zero-config discovery**](#-how-it-works) — routes are collected at container compile time, no extra cache
- [**URL generation**](#-url-generation) — a Fluid ViewHelper so the path lives *once*, not duplicated as a PHP constant and a JS string
- [**Opt-in caching**](#-caching) — cache responses with `#[Cache]`, with tag-based invalidation
- [**Debug command**](#-debug-command) — list every registered route as a table or JSON

> [!NOTE]
> TYPO3 ships attribute-like AJAX route registration for the **backend** (`AjaxRoutes.php`), but there is no frontend equivalent — you end up wiring a custom middleware and duplicating the path in PHP and JavaScript. This extension closes that gap with a single `#[Route]` attribute, the same way the core exposes `TYPO3.settings.ajaxUrls[...]` in the backend.

## 🔥 Installation

### Requirements

* TYPO3 >= 13.4
* PHP 8.2+

### Composer

[![Packagist](https://img.shields.io/packagist/v/konradmichalik/typo3-routing?label=version&logo=packagist)](https://packagist.org/packages/konradmichalik/typo3-routing)
[![Packagist Downloads](https://img.shields.io/packagist/dt/konradmichalik/typo3-routing?color=brightgreen)](https://packagist.org/packages/konradmichalik/typo3-routing)

``` bash
composer require konradmichalik/typo3-routing
```

### TER

[![TER version](https://typo3-badges.dev/badge/typo3_routing/version/shields.svg)](https://extensions.typo3.org/extension/typo3_routing)
[![TER downloads](https://typo3-badges.dev/badge/typo3_routing/downloads/shields.svg)](https://extensions.typo3.org/extension/typo3_routing)

Download the zip file from [TYPO3 extension repository (TER)](https://extensions.typo3.org/extension/typo3_routing).

> [!NOTE]
> The extension key is `typo3_routing`, not `routing`, to avoid colliding with TYPO3 core page routing.

## 🚀 Usage

Implement the marker interface [`RouteControllerInterface`](Classes/Routing/RouteControllerInterface.php) and annotate public methods with [`#[Route]`](Classes/Attribute/Route.php). No further configuration is needed beyond registering the controller as a service (autoconfiguration in your `Configuration/Services.yaml` is sufficient).

```php
use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class CourseSearchController implements RouteControllerInterface
{
    public function __construct(/* … injected services … */) {}

    #[Route(path: '/api/course-search/count', name: 'course_search_count')]
    public function count(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse(['count' => 42]);
    }
}
```

The `#[Route]` attribute is repeatable. Its parameters:

| Parameter | Type           | Default   | Description                                                              |
|-----------|----------------|-----------|--------------------------------------------------------------------------|
| `path`    | `string`       | –         | Full request path, written including the prefix (e.g. `/api/...`).       |
| `methods` | `list<string>` | `['GET']` | Allowed HTTP methods.                                                    |
| `name`         | `?string`               | `null`    | Route name; auto-derived from service id + method when omitted.          |
| `env`          | `?string`               | `null`    | Bind the route to a top-level application context (e.g. `Development`).  |
| `requirements` | `array<string, string>` | `[]`      | Constraints by parameter name → regex (`''` = presence only). See below.  |

`requirements` constrains parameters by name, with two enforcement layers depending on where the parameter lives:

- **Path placeholders** (a name that appears as `{name}` in the path) are enforced by the **matcher**: a violating path is treated as no match → **404**. Matched path parameters are passed as request attributes (`$request->getAttribute('id')`).
- **Any other name** is a required **query or POST-body** parameter, validated at **dispatch**: missing or format-violating → **400**, before your controller runs. (`''` means presence only.)

```php
#[Route(
    path: '/api/item/{id}',
    name: 'item_show',
    // {id} → matcher (404 if not digits); q → required query/body param (400 if missing or not digits)
    requirements: ['id' => '\d+', 'q' => '\d+'],
)]
public function show(ServerRequestInterface $request): ResponseInterface
{
    $id = (int) $request->getAttribute('id');     // only ever digits
    $q  = $request->getQueryParams()['q'];        // guaranteed present and valid
    // …
}
```

### URL Generation

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
> In PHP, inject [`RouteUrlGenerator`](Classes/Http/RouteUrlGenerator.php) and call `generate($request, $routeName, $parameters)`.

## 🧰 Configuration

### Path prefix gate

The dispatcher first checks whether the request path (after stripping the site/language base) starts with a configurable prefix. Paths outside the prefix fall through to normal page rendering at zero cost — this is a pure performance gate. Configure it via **Settings → Extension Configuration → typo3_routing**:

| Setting  | Description                                                                                     | Default  |
|----------|-------------------------------------------------------------------------------------------------|----------|
| `prefix` | Only paths starting with this are matched. Leave **empty** to match every path (see warning).   | `/api/`  |

> [!WARNING]
> Setting an **empty prefix** means every request path is matched against your routes. Any path that does not match a registered route returns `404` instead of falling through to the page router. Only use an empty prefix if attribute routes own your entire URL space.

Route paths in the `#[Route]` attribute are always written in full, including the prefix.

### Environment-bound routes

A route with `env: 'Development'` only exists while the top-level application context matches (case-insensitive). Outside that context the route behaves as if it does not exist (`404`) — no ExpressionLanguage, just a match-time check against `Environment::getContext()`.

```php
#[Route(path: '/api/debug/dump', name: 'debug_dump', env: 'Development')]
public function dump(ServerRequestInterface $request): ResponseInterface { /* … */ }
```

## ⚡ Caching

Add the optional `#[Cache]` attribute next to a `#[Route]` to cache the response — the controller stays unaware of caching:

```php
use KonradMichalik\Typo3Routing\Attribute\Cache;
use KonradMichalik\Typo3Routing\Attribute\Route;

// e.g. GET /api/news?page=2&search=foo
//   → cached per "page", but the volatile "search" query parameter is excluded from the cache key
#[Route(path: '/api/news', name: 'news_list')]
#[Cache(lifetime: 3600, tags: ['tx_news_domain_model_news'], ignoreParams: ['search'])]
public function list(ServerRequestInterface $request): ResponseInterface
{
    $page = (int) ($request->getQueryParams()['page'] ?? 1);
    // …
}
```

| Parameter      | Type                    | Default | Description                                                                       |
|----------------|-------------------------|---------|-----------------------------------------------------------------------------------|
| `lifetime`     | `int`                   | `86400` | Time to live in seconds (fallback when no tag is invalidated).                    |
| `tags`         | `list<string>`          | `[]`    | Cache tags. A tag matching a table name is flushed automatically when a record of that table changes (via DataHandler). |
| `ignoreParams` | `list<string>`          | `[]`    | Query parameters excluded from the cache key (e.g. an individual `search` term).  |

- Only **successful (`200`) GET responses** are cached. The cache key is built from route name, path, query string (minus `ignoreParams`) and language, so query/language variants are cached separately.
- Invalidation rides on the TYPO3 caching framework: changing a record of a tagged table flushes the matching entries immediately; `lifetime` is the fallback. The response is stored via the TYPO3 cache backend (no extra cache layer of its own).

> [!CAUTION]
> Only cache responses that are the **same for everyone**. With the default middleware placement the dispatcher runs before authentication, so responses are not user-specific — but if you move it after `typo3/cms-frontend/authentication` (see below) and cache a personalized response, you risk serving one user's data to another. `Set-Cookie` headers are never cached.

## 🐛 Debug Command

> [!TIP]
> `routing:debug` reads the same compiled registry as the dispatcher, so it can never drift from actual runtime behaviour.

``` bash
vendor/bin/typo3 routing:debug          # human-readable table
vendor/bin/typo3 routing:debug --json   # machine-readable (tooling / LLM)
```

## 🚦 Middleware Placement

The dispatcher middleware runs in the **frontend** stack, **after** `typo3/cms-frontend/site` (it needs the resolved site/language context) and **before** `typo3/cms-frontend/page-resolver`.

> [!IMPORTANT]
> If an endpoint needs an authenticated `fe_user`, move the dispatcher **after** `typo3/cms-frontend/authentication` by overriding the middleware ordering in your own `Configuration/RequestMiddlewares.php`. The default placement responds before authentication runs, which is correct for public APIs but wrong for protected endpoints.

## 🔧 How It Works

1. **Compile time** — [`RouteCompilerPass`](Classes/DependencyInjection/RouteCompilerPass.php) scans every service definition, picks those implementing `RouteControllerInterface`, reflects their `#[Route]` attributes into a plain array, and injects both that array and a `ServiceLocator` of the controllers into [`RouteRegistry`](Classes/Routing/RouteRegistry.php). Duplicate route names raise a build-time exception. There is no extra cache: invalidation rides on the DI container cache, which TYPO3 already clears correctly.

2. **Runtime** — [`RouteDispatcher`](Classes/Middleware/RouteDispatcher.php) applies the prefix gate, matches via `symfony/routing`, filters by environment, then dispatches to the controller method `(ServerRequestInterface): ResponseInterface`. `404` and `405` responses (the latter with an `Allow` header) are emitted as `{"error": "…", "status": …}`; the success response format is entirely the controller's choice.

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
