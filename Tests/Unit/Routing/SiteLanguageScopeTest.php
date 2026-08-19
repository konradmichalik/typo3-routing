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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Routing;

use KonradMichalik\Ttt\Http\Requests;
use KonradMichalik\Typo3Routing\Routing\SiteLanguageScope;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SiteLanguageScopeTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(SiteLanguageScope::class)]
final class SiteLanguageScopeTest extends TestCase
{
    #[Test]
    public function everySiteIsVisibleWhenSitesIsNull(): void
    {
        self::assertTrue($this->scope()->isVisibleForSite(null, $this->request()));
    }

    #[Test]
    public function everySiteIsVisibleWhenSitesIsEmpty(): void
    {
        self::assertTrue($this->scope()->isVisibleForSite([], $this->request()));
    }

    #[Test]
    public function aMatchingSiteIdentifierIsVisible(): void
    {
        $request = $this->requestWithSite('main');

        self::assertTrue($this->scope()->isVisibleForSite(['main', 'intranet'], $request));
    }

    #[Test]
    public function aNonMatchingSiteIdentifierIsNotVisible(): void
    {
        $request = $this->requestWithSite('main');

        self::assertFalse($this->scope()->isVisibleForSite(['intranet'], $request));
    }

    #[Test]
    public function isNotVisibleWhenTheRequestCarriesNoSiteAttribute(): void
    {
        self::assertFalse($this->scope()->isVisibleForSite(['intranet'], $this->request()));
    }

    #[Test]
    public function everyLanguageIsVisibleWhenLanguagesIsNull(): void
    {
        self::assertTrue($this->scope()->isVisibleForLanguage(null, $this->request()));
    }

    #[Test]
    public function everyLanguageIsVisibleWhenLanguagesIsEmpty(): void
    {
        self::assertTrue($this->scope()->isVisibleForLanguage([], $this->request()));
    }

    #[Test]
    public function aMatchingLanguageIdIsVisible(): void
    {
        $request = $this->requestWithLanguage(0);

        self::assertTrue($this->scope()->isVisibleForLanguage([0, 2], $request));
    }

    #[Test]
    public function aNonMatchingLanguageIdIsNotVisible(): void
    {
        $request = $this->requestWithLanguage(0);

        self::assertFalse($this->scope()->isVisibleForLanguage([2], $request));
    }

    #[Test]
    public function isNotVisibleWhenTheRequestCarriesNoLanguageAttribute(): void
    {
        self::assertFalse($this->scope()->isVisibleForLanguage([0], $this->request()));
    }

    #[Test]
    public function logsAWarningOnceForASitesListNamingAnUnknownIdentifier(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('"totally-unknown-site-xyz"'));

        $logManager = $this->createMock(LogManager::class);
        $logManager->method('getLogger')->willReturn($logger);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([new Site('main', 1, [])]);

        $scope = new SiteLanguageScope($siteFinder, $logManager);
        $request = $this->requestWithSite('main');

        // Evaluated twice with the exact same list — the warning fires only the first time.
        $scope->isVisibleForSite(['totally-unknown-site-xyz'], $request);
        $scope->isVisibleForSite(['totally-unknown-site-xyz'], $request);
    }

    #[Test]
    public function doesNotLogWhenEverySiteIsKnown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $logManager = $this->createMock(LogManager::class);
        $logManager->method('getLogger')->willReturn($logger);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([new Site('main', 1, [])]);

        $scope = new SiteLanguageScope($siteFinder, $logManager);

        $scope->isVisibleForSite(['main'], $this->requestWithSite('main'));
    }

    private function scope(): SiteLanguageScope
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([new Site('main', 1, []), new Site('intranet', 2, [])]);

        return new SiteLanguageScope($siteFinder, $this->createMock(LogManager::class));
    }

    private function request(): ServerRequest
    {
        return Requests::get('https://example.com/')->build();
    }

    private function requestWithSite(string $identifier): ServerRequest
    {
        return Requests::get('https://example.com/')
            ->withAttribute('site', new Site($identifier, 1, ['base' => 'https://example.com/']))
            ->build();
    }

    private function requestWithLanguage(int $languageId): ServerRequest
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => $languageId, 'title' => 'Lang', 'locale' => 'en_US.UTF-8', 'base' => 'https://example.com/'],
            ],
        ]);

        return Requests::get('https://example.com/')
            ->withAttribute('language', $site->getLanguageById($languageId))
            ->build();
    }
}
