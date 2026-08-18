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
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\{SiteInterface, SiteLanguage};
use TYPO3\CMS\Core\Site\SiteFinder;

use function array_diff;
use function array_map;
use function array_values;
use function implode;
use function in_array;
use function sprintf;

/**
 * SiteLanguageScope.
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
     * Empty/null means every site. An unknown identifier among a non-empty `sites` is reported once
     * per distinct list (not fatal — see the attribute's own docblock for why this is a runtime check,
     * not a build-time one) regardless of whether the current request's site is among them.
     *
     * @param list<string>|null $sites
     */
    public function isVisibleForSite(?array $sites, ServerRequestInterface $request): bool
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
    public function isVisibleForLanguage(?array $languages, ServerRequestInterface $request): bool
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
