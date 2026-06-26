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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Http;

use KonradMichalik\Typo3Routing\Http\SiteBasePathResolver;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\{Site, SiteLanguage};

/**
 * SiteBasePathResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(SiteBasePathResolver::class)]
final class SiteBasePathResolverTest extends TestCase
{
    private SiteBasePathResolver $subject;

    protected function setUp(): void
    {
        $this->subject = new SiteBasePathResolver();
    }

    #[Test]
    public function stripsLanguageBasePrefixFromPath(): void
    {
        $request = (new ServerRequest('https://example.com/sub/api/count'))
            ->withAttribute('language', $this->makeLanguage('https://example.com/sub/'));

        self::assertSame('/api/count', $this->subject->stripSiteBase($request));
    }

    #[Test]
    public function stripsSiteBaseWhenNoLanguageAttributePresent(): void
    {
        $request = $this->requestWithSiteBase('https://example.com/sub/api/count', 'https://example.com/sub/');

        self::assertSame('/api/count', $this->subject->stripSiteBase($request));
    }

    #[Test]
    public function leavesPathUntouchedForRootBase(): void
    {
        $request = $this->requestWithSiteBase('https://example.com/api/count', 'https://example.com/');

        self::assertSame('/api/count', $this->subject->stripSiteBase($request));
    }

    #[Test]
    public function returnsNormalizedPathWhenNoSiteContextAvailable(): void
    {
        $request = new ServerRequest('https://example.com/api/count');

        self::assertSame('/api/count', $this->subject->stripSiteBase($request));
    }

    #[Test]
    public function prependSiteBaseIsSymmetricToStripping(): void
    {
        $request = $this->requestWithSiteBase('https://example.com/sub/', 'https://example.com/sub/');

        self::assertSame('/sub/api/count', $this->subject->prependSiteBase($request, '/api/count'));
    }

    #[Test]
    public function prependSiteBaseForRootBaseReturnsPlainPath(): void
    {
        $request = $this->requestWithSiteBase('https://example.com/', 'https://example.com/');

        self::assertSame('/api/count', $this->subject->prependSiteBase($request, 'api/count'));
    }

    private function makeLanguage(string $base): SiteLanguage
    {
        $site = new Site('main', 1, [
            'base' => $base,
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => $base,
                ],
            ],
        ]);

        return $site->getDefaultLanguage();
    }

    private function requestWithSiteBase(string $url, string $base): ServerRequest
    {
        $site = new Site('main', 1, ['base' => $base]);

        return (new ServerRequest($url))->withAttribute('site', $site);
    }
}
