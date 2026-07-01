<div align="center">

![Extension icon](Resources/Public/Icons/Extension.png)

# TYPO3 extension `typo3_routing`

[![Latest Stable Version](https://typo3-badges.dev/badge/typo3_routing/version/shields.svg)](https://extensions.typo3.org/extension/typo3_routing)
[![Supported TYPO3 versions](https://typo3-badges.dev/badge/typo3_routing/typo3/shields.svg)](https://extensions.typo3.org/extension/typo3_routing)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/typo3-routing/php?logo=php)](https://packagist.org/packages/konradmichalik/typo3-routing)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-routing/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/typo3-routing/actions/workflows/cgl.yml)
[![Coverage](https://img.shields.io/coverallsCoverage/github/konradmichalik/typo3-routing?logo=coveralls)](https://coveralls.io/github/konradmichalik/typo3-routing)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-routing/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/typo3-routing/actions/workflows/tests.yml)
[![License](https://poser.pugx.org/konradmichalik/typo3-routing/license)](LICENSE.md)

</div>

This extension lets you register **frontend endpoints via PHP attributes** on controller methods — the attribute-based counterpart to the backend-only [`Configuration/Backend/AjaxRoutes.php`](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Backend/Ajax.html). It is response-format agnostic: return JSON, HTML, XML, or a download.

> [!WARNING]
> This package is in early development stage and may change significantly in the future. I am working steadily to release a stable version as soon as possible.

> [!NOTE]
> The goal is a familiar, Symfony-Routing-like developer experience: declare a frontend endpoint with a single `#[Route]` attribute instead of wiring a custom middleware and duplicating the path across PHP and JavaScript.

## ✨ Features

- [**Attribute routing**](docs/USAGE.md) — declare an endpoint with `#[Route]` directly on a controller method
- [**Typed arguments**](docs/USAGE.md#typed-controller-arguments) — methods receive type-cast path/query/body values, no manual request reading
- [**Zero-config discovery**](docs/HOW-IT-WORKS.md) — routes are collected at container compile time, no extra cache
- [**URL generation**](docs/URL-GENERATION.md) — a Fluid ViewHelper so the path lives *once*, not duplicated as a PHP constant and a JS string
- [**Opt-in caching**](docs/CACHING.md) — cache responses with `#[Cache]`, with tag-based invalidation
- [**Opt-in rate limiting**](docs/RATE-LIMITING.md) — throttle requests per client IP with `#[RateLimit]`
- [**Opt-in authentication & CSRF**](docs/AUTHENTICATION.md) — protect routes with `#[Authenticate]` (bearer token / FE / BE user) and `#[RequireRequestToken]`
- [**CORS**](docs/CONFIGURATION.md#cors) — opt-in cross-origin support with automatic preflight handling, configured globally
- [**Debug command**](docs/HOW-IT-WORKS.md#debug-command) — list every registered route as a table or JSON, including an `--unprotected` audit

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

That's it — `GET /api/course-search/count` now returns your JSON.

Everything else is opt-in on top of that. A route can take typed arguments, validate input, cache its response, and throttle clients — all declared with attributes, the controller stays plain:

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

Protecting a route is just as declarative — require a logged-in frontend user (or a bearer token / BE user, OR-combined):

```php
#[Route(path: '/api/account', name: 'account')]
#[Authenticate(FrontendUserAuthenticator::class)]
public function account(): ResponseInterface
{
    // Reached only when a frontend user is logged in — 401 otherwise.
    return new JsonResponse(/* … */);
}
```

See [Usage](docs/USAGE.md) for the full `#[Route]` reference and typed arguments.

## 📚 Documentation

| Topic | What's inside |
|-------|---------------|
| [Usage](docs/USAGE.md) | The `#[Route]` attribute, `requirements`, and typed controller arguments |
| [URL Generation](docs/URL-GENERATION.md) | `routing:uri` / `routing:uris` Fluid ViewHelpers and the PHP generator |
| [Configuration](docs/CONFIGURATION.md) | Path prefix gate, environment-bound routes, middleware placement |
| [Caching](docs/CACHING.md) | Opt-in response caching with `#[Cache]` and tag-based invalidation |
| [Rate Limiting](docs/RATE-LIMITING.md) | Opt-in per-IP throttling with `#[RateLimit]` |
| [Authentication & CSRF](docs/AUTHENTICATION.md) | Protecting routes with `#[Authenticate]`, request tokens, and deployment notes |
| [How It Works](docs/HOW-IT-WORKS.md) | Compile-time discovery, runtime dispatch, and the `routing:debug` command |
| [How It Compares](docs/COMPARISON.md) | When to reach for this vs. `AjaxRoutes`, custom middleware, `eID`, or Extbase plugins |

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
