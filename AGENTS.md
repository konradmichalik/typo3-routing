# AGENTS.md

Guidance for coding agents working in this repository.

## Project overview

TYPO3 extension `typo3_routing` (`konradmichalik/typo3-routing`). It registers frontend endpoints via PHP attributes on controller methods, the attribute-based counterpart to the backend-only `Configuration/Backend/AjaxRoutes.php`. Response-format agnostic (JSON, HTML, XML, downloads).

- Extension key: `typo3_routing` (not `routing`, to avoid a clash with core page routing)
- Namespace: `KonradMichalik\Typo3Routing\`
- PHP: `~8.2 || ~8.3 || ~8.4 || ~8.5`
- TYPO3: `^13.4 || ^14.0`

## Structure

- `Classes/Attribute/`: `#[Route]` (repeatable), `#[Authenticate]`, `#[Cache]`, `#[Cors]`, `#[RateLimit]`, `#[Param]`, `#[Returns]`, `#[RequireRequestToken]`, `#[DeprecatedRoute]`
- `Classes/DependencyInjection/RouteCompilerPass`: reflects `#[Route]` attributes of `RouteControllerInterface` services at container compile time into a plain array injected into `RouteRegistry`. Duplicate route names fail the build
- `Classes/Routing/`: `RouteRegistry`, `RouteMatcher` (trailing-slash retry, opt-in case-insensitive matching, scheme redirects), `PathPrefixGate`, `RouteControllerInterface`
- `Classes/Middleware/RouteDispatcher`: frontend middleware. Order: path gate, match, env filter, rate limit, response cache, dispatch. Registered in `Configuration/RequestMiddlewares.php` after `typo3/cms-frontend/site` and before `typo3/cms-frontend/page-resolver`
- `Classes/Http/`: base-path resolving, URL generation, CORS, error responses, conditional GET
- `Classes/Authentication/`, `Classes/Cache/`, `Classes/RateLimit/`, `Classes/OpenApi/`, `Classes/Command/`, `Classes/ViewHelpers/`, `Classes/Controller/`
- `Configuration/`: `Services.php` (registers the compiler pass), `Services.yaml` (`RouteDispatcher` and `RouteUrlGenerator` are public), `RequestMiddlewares.php`
- `ext_conf_template.txt`: `exclusivePrefixes` (404 semantics only) and `trailingSlash`
- `docs/`: user documentation
- `Tests/Unit/`, `Tests/Functional/`: PHPUnit tests, fixture extensions `routing_test` and `routing_benchmark` under `Tests/Functional/Fixtures/Extensions/`
- `Tests/CGL/`: isolated Composer project with the code style and analysis tooling

Routes are discovered at container compile time. There is no runtime scanning and no second cache, invalidation rides on the DI container cache. Route definitions are injected as a plain array so they bake into the compiled container.

Gotchas:
- Discovery reflects the concrete controller. Attributes on an abstract parent's methods are inherited, but PHP drops method attributes on an override. An override that repeats `#[Route]` without the parent's `#[Authenticate]` becomes a public endpoint, the compiler pass cannot detect that
- Class-level attributes are never inherited
- The EditorConfig step needs `.git` visible in the container. After `git init`, run `ddev restart`

## Development commands

Requires [DDEV](https://ddev.readthedocs.io/en/stable/). Quality tools live in `Tests/CGL/`, invoked via `ddev cgl` or `composer cgl`.

```bash
ddev start
ddev cgl lint                  # PHP CS Fixer, EditorConfig, composer-normalize
ddev cgl fix                   # auto-fix
ddev cgl sca                   # PHPStan
ddev cgl migration             # Rector
ddev cgl analyze:dependencies  # missing and unused dependencies

ddev install all               # real TYPO3 13 and 14 instances under .Build/<version>
ddev 14 typo3 routing:debug    # TYPO3 CLI in one instance
```

## Testing

```bash
ddev composer test             # unit tests, no coverage
ddev composer test:coverage    # unit tests with coverage (pcov, no Xdebug needed)
ddev composer test:functional  # functional tests, need a database
```

Functional tests need database credentials via `typo3Database*` environment variables and run inside DDEV. Unit tests use `konradmichalik/ttt`, registered as PHPUnit extension in `phpunit.xml` only, not in `phpunit.functional.xml`. The unit bootstrap `Tests/Unit/UnitTestsBootstrap.php` initializes the TYPO3 `Environment` once per process.

CI runs PHPUnit through a reusable workflow on PHP 8.2 to 8.5, TYPO3 13.4, 14.0 and 14.3, with highest and lowest dependencies. It also runs CGL, a security workflow and OpenSSF Scorecard.

## Code style and static analysis

- PHPStan level 8 with `phpstan-typo3-preset` (`Tests/CGL/phpstan.neon`). Custom request attributes need a `requestGetAttributeMapping` entry
- PHP CS Fixer with `konradmichalik/php-cs-fixer-preset` (`Tests/CGL/.php-cs-fixer.php`)
- Rector for TYPO3 and PHP 8.2 modernization (`Tests/CGL/rector.php`)
- `declare(strict_types=1)` everywhere, `final readonly` where sensible
- Keep 100% line coverage

## Git workflow

- Branch from `main`, open a pull request
- Commit format: `<type>: <description>` with type one of feat, fix, refactor, docs, test, chore, perf, ci
- No co-author trailers
