<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_routing" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3Routing\Routing;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\{SiteInterface, SiteLanguage};
use TYPO3\CMS\Core\Site\SiteFinder;

use function array_diff;
use function array_map;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * SiteLanguageScope.
 *
 * The one place that decides whether a route is visible in the current context: its application context
 * (`env`), its sites and its languages. An invisible route must be indistinguishable from a missing one,
 * so the matcher drops it before anything else sees it (see RouteMatcher::match()).
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteLanguageScope
{
    /**
     * Per-process memo of site lists already checked against the installation's configured sites, so a
     * route evaluated on every request does not re-warn (or re-query SiteFinder) each time.
     *
     * @var array<string, true>
     */
    private static array $warnedSiteLists = [];

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly LogManager $logManager,
    ) {}

    /**
     * Reads the internal keys a match (or a Symfony route's defaults) carries, see
     * RouteRegistry::buildCollection().
     *
     * @param array<string, mixed> $match
     */
    public function isMatchVisible(array $match, ServerRequestInterface $request): bool
    {
        return $this->isVisible($match['_env'] ?? null, $match['_sites'] ?? null, $match['_languages'] ?? null, $request);
    }

    /**
     * Reads a route definition as RouteRegistry::getRoutes() holds it.
     *
     * @param array<string, mixed> $route
     */
    public function isRouteVisible(array $route, ServerRequestInterface $request): bool
    {
        return $this->isVisible($route['env'] ?? null, $route['sites'] ?? null, $route['languages'] ?? null, $request);
    }

    private function isVisible(mixed $env, mixed $sites, mixed $languages, ServerRequestInterface $request): bool
    {
        return self::isVisibleInCurrentContext($env)
            && $this->isVisibleForSite($sites, $request)
            && $this->isVisibleForLanguage($languages, $request);
    }

    /**
     * An env-less route (null or empty) is visible everywhere; otherwise only the first segment of the
     * application context counts, case-insensitively.
     */
    private static function isVisibleInCurrentContext(mixed $env): bool
    {
        if (!is_string($env) || '' === $env) {
            return true;
        }

        $current = explode('/', (string) Environment::getContext())[0];

        return strtolower($current) === strtolower($env);
    }

    /**
     * Empty/null means every site. An unknown identifier among a non-empty `sites` is reported once
     * per distinct list (not fatal — see the attribute's own docblock for why this is a runtime check,
     * not a build-time one) regardless of whether the current request's site is among them.
     *
     * @param list<string>|null $sites
     */
    private function isVisibleForSite(?array $sites, ServerRequestInterface $request): bool
    {
        if (null === $sites || [] === $sites) {
            return true;
        }

        $this->warnOnUnknownSites($sites);

        $site = $request->getAttribute('site');

        return $site instanceof SiteInterface && in_array($site->getIdentifier(), $sites, true);
    }

    /**
     * @param list<int>|null $languages
     */
    private function isVisibleForLanguage(?array $languages, ServerRequestInterface $request): bool
    {
        if (null === $languages || [] === $languages) {
            return true;
        }

        $language = $request->getAttribute('language');

        return $language instanceof SiteLanguage && in_array($language->getLanguageId(), $languages, true);
    }

    /**
     * @param list<string> $sites
     */
    private function warnOnUnknownSites(array $sites): void
    {
        $key = implode(',', $sites);
        if (isset(self::$warnedSiteLists[$key])) {
            return;
        }
        self::$warnedSiteLists[$key] = true;

        $known = array_map(static fn (SiteInterface $site): string => $site->getIdentifier(), $this->siteFinder->getAllSites());
        $unknown = array_values(array_diff($sites, $known));
        if ([] === $unknown) {
            return;
        }

        $this->logManager->getLogger(self::class)->warning(sprintf('#[Route(sites: ...)] names unknown site identifier(s) not present in the current site configuration: "%s".', implode('", "', $unknown)));
    }
}
