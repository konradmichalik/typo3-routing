<div align="center">

![Extension icon](Resources/Public/Icons/Extension.png)

# TYPO3 extension `typo3_routing`

[![Latest Stable Version](https://typo3-badges.dev/badge/typo3_routing/version/shields.svg)](https://extensions.typo3.org/extension/typo3_routing)
![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014.3-orange.svg)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/typo3-routing/php?logo=php)](https://packagist.org/packages/konradmichalik/typo3-routing)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-routing/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/typo3-routing/actions/workflows/cgl.yml)
[![Coverage](https://img.shields.io/coverallsCoverage/github/konradmichalik/typo3-routing?logo=coveralls)](https://coveralls.io/github/konradmichalik/typo3-routing)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-routing/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/typo3-routing/actions/workflows/tests.yml)
[![License](https://poser.pugx.org/konradmichalik/typo3-routing/license)](LICENSE.md)

</div>

This extension lets you register **frontend endpoints via PHP attributes** on controller methods — the path, its typed arguments and its caching, rate limiting, authentication and CORS policy all declared where the endpoint lives. It is response-format agnostic: return JSON, HTML, XML, or a download.

The frontend has no equivalent to the backend-only [`Configuration/Backend/AjaxRoutes.php`](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Backend/Ajax.html); closing that gap is where this extension started, though a `#[Route]` today carries considerably more than a path-to-controller mapping.

> [!NOTE]
> The goal is a familiar, Symfony-Routing-like developer experience: declare a frontend endpoint with a single `#[Route]` attribute instead of wiring a custom middleware and duplicating the path across PHP and JavaScript.

## ✨ Features

- [**Attribute routing**](docs/routes/route-attribute.md): declare an endpoint with `#[Route]` directly on a controller method
- [**Route groups**](docs/routes/route-groups.md): a class-level `#[Route]` prefixes every method route, e.g. for API versioning or a shared base controller
- [**Typed arguments**](docs/routes/arguments.md): methods receive type-cast path/query/body values, no manual request reading
- [**Zero-config discovery**](docs/background/how-it-works.md): routes are collected at container compile time, no extra cache
- [**Measured overhead**](docs/background/performance.md): requests that cannot be a route are filtered out in under a microsecond; dispatching a matched one costs ~0.6 ms more than a hand-written middleware, a few percent of a minimal JSON endpoint
- [**URL generation**](docs/routes/url-generation.md): a Fluid ViewHelper so the path lives *once*, not duplicated as a PHP constant and a JS string
- [**Opt-in caching**](docs/features/caching.md): cache responses with `#[Cache]`, with tag-based invalidation
- [**Opt-in rate limiting**](docs/features/rate-limiting.md): throttle requests per client IP with `#[RateLimit]`
- [**Opt-in authentication & CSRF**](docs/features/authentication.md): protect routes with `#[Authenticate]` (bearer token / FE / BE user) and `#[RequireRequestToken]`
- [**CORS**](docs/features/cors.md): opt-in cross-origin support with automatic preflight handling, configured globally or per route with `#[Cors]`
- [**Debug command**](docs/operating/commands.md): list every registered route as a table or JSON, including an `--unprotected` audit
- [**OpenAPI export**](docs/operating/openapi.md): generate an OpenAPI 3.1 document from your routes with `routing:openapi`
- [**Swagger UI**](docs/operating/openapi.md#swagger-ui-development-only): opt-in, development-only Swagger UI served over the same OpenAPI export

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

## 🚀 Quick start

Implement [`RouteControllerInterface`](Classes/Routing/RouteControllerInterface.php), register the controller as a service, and annotate a public method with `#[Route]`:

```php
use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class CourseSearchController implements RouteControllerInterface
{
    #[Route(path: '/api/course-search/count', name: 'course_search_count')]
    public function count(): ResponseInterface
    {
        return new JsonResponse(['count' => 42]);
    }
}
```

That's it: `GET /api/course-search/count` now returns your JSON.

Everything else is opt-in on top of that. A route can take typed arguments, validate input, cache its response, and throttle clients, all declared with attributes, while the controller stays plain:

```php
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/api/courses/{id}', name: 'course_show', requirements: ['id' => Requirement::DIGITS])]
#[Cache(lifetime: 3600, tags: ['tx_courses_domain_model_course'])]
#[RateLimit(limit: 60, interval: '1 minute')]
public function show(int $id, int $page = 1): ResponseInterface
{
    // $id  ← path placeholder, cast to int (404 if not digits)
    // $page ← ?page=… query param, defaults to 1
    return new JsonResponse(/* … */);
}
```

See [The `#[Route]` attribute](docs/routes/route-attribute.md) for the full reference and [Typed controller arguments](docs/routes/arguments.md) for how the signature is fed.

## 📚 Documentation

Full documentation lives in [`docs/`](docs/README.md), grouped into four parts:

| Part | What's inside |
|------|---------------|
| [Defining routes](docs/routes/README.md) | The `#[Route]` attribute, route groups, typed controller arguments, and URL generation — the only part you need to ship an endpoint |
| [Route features](docs/features/README.md) | The opt-in attributes: `#[Authenticate]`, `#[Cache]`, `#[RateLimit]`, `#[Cors]`, and how they interact |
| [Operating it](docs/operating/README.md) | Every extension setting, the `routing:debug` / `routing:match` commands, and the OpenAPI export |
| [Background](docs/background/README.md) | How it compares to the alternatives, how it works underneath, measured performance, and the `@api` surface for tooling |

> [!TIP]
> Want to expose these routes to AI agents/MCP clients? <img src="https://github.com/konradmichalik/typo3-routing-mcp/raw/main/Resources/Public/Icons/Extension.png?raw=true" width="16" height="16" alt=""> [`typo3-routing-mcp`](https://github.com/konradmichalik/typo3-routing-mcp) adds a `#[McpTool]` attribute next to an existing `#[Route]` and serves it over Streamable HTTP.

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
