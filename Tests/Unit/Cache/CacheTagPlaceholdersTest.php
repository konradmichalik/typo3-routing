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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Cache;

use KonradMichalik\Typo3Routing\Cache\CacheTagPlaceholders;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

use function hash;
use function str_repeat;
use function substr;

/**
 * CacheTagPlaceholdersTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CacheTagPlaceholders::class)]
final class CacheTagPlaceholdersTest extends TestCase
{
    #[Test]
    public function substitutesThePlaceholderWithTheMatchedPathValue(): void
    {
        $tags = CacheTagPlaceholders::resolve(['tx_news_domain_model_news', 'tx_news_domain_model_news_{id}'], ['_route' => 'news_detail', 'id' => '5']);

        self::assertSame(['tx_news_domain_model_news', 'tx_news_domain_model_news_5'], $tags);
    }

    #[Test]
    public function substitutesEveryPlaceholderOfATag(): void
    {
        self::assertSame(['news_en_5'], CacheTagPlaceholders::resolve(['news_{lang}_{id}'], ['lang' => 'en', 'id' => 5]));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function unsafeValues(): iterable
    {
        yield 'dot' => ['a.b', 'a.b'];
        yield 'umlaut' => ['über', 'über'];
        yield 'percent, which the tag pattern allows but the value rule does not' => ['a%20b', 'a%20b'];
        yield 'empty' => ['', ''];
        yield 'missing value' => [null, ''];
        yield 'longer than 64 characters' => [str_repeat('a', 65), str_repeat('a', 65)];
    }

    #[Test]
    #[DataProvider('unsafeValues')]
    public function hashesAValueOutsideTheSafeCharacters(mixed $value, string $hashed): void
    {
        $tags = CacheTagPlaceholders::resolve(['news_{id}'], ['id' => $value]);

        self::assertSame(['news_h'.substr(hash('sha256', $hashed), 0, 16)], $tags);
    }

    #[Test]
    public function keepsAValueOfExactly64SafeCharacters(): void
    {
        $value = str_repeat('a', 64);

        self::assertSame(['news_'.$value], CacheTagPlaceholders::resolve(['news_{id}'], ['id' => $value]));
    }

    #[Test]
    public function leavesTagsWithoutPlaceholdersUntouched(): void
    {
        self::assertSame(['pages', 'news'], CacheTagPlaceholders::resolve(['pages', 'news'], ['id' => 'a.b']));
    }

    #[Test]
    public function listsThePlaceholderNamesOfATagOrPath(): void
    {
        self::assertSame(['lang', 'id'], CacheTagPlaceholders::names('/api/{lang}/news/{id}'));
        self::assertSame([], CacheTagPlaceholders::names('news_{}_{1x}'));
    }

    #[Test]
    public function listsThePlaceholdersAPathDoesNotDeclare(): void
    {
        self::assertSame(['slug', 'uid'], CacheTagPlaceholders::unbound(['news_{id}', 'news_{slug}', 'pages_{uid}_{id}'], '/api/news/{id}'));
        self::assertSame([], CacheTagPlaceholders::unbound(['news', 'news_{id}'], '/api/news/{id}'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function tagsToValidate(): iterable
    {
        yield 'placeholder after a valid prefix' => ['tx_news_{id}', true];
        yield 'invalid static part' => ['tx.news_{id}', false];
        yield 'empty braces stay literal' => ['tx_news_{}', false];
        yield 'static part long enough to overflow with the longest value' => [str_repeat('a', 187).'_{id}', false];
        yield 'static part leaving room for the longest value' => [str_repeat('a', 186).'{id}', true];
    }

    #[Test]
    #[DataProvider('tagsToValidate')]
    public function standInsValidateOnlyTheStaticPartsAgainstTheTagPattern(string $tag, bool $valid): void
    {
        self::assertSame($valid, 1 === preg_match(FrontendInterface::PATTERN_TAG, CacheTagPlaceholders::withStandIns($tag)));
    }
}
