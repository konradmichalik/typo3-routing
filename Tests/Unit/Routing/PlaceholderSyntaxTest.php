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

use KonradMichalik\Typo3Routing\Routing\PlaceholderSyntax;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * PlaceholderSyntaxTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(PlaceholderSyntax::class)]
final class PlaceholderSyntaxTest extends TestCase
{
    #[Test]
    public function acceptsAStaticPath(): void
    {
        self::assertSame([], PlaceholderSyntax::unsupported('/api/status'));
    }

    #[Test]
    public function acceptsPlainPlaceholders(): void
    {
        self::assertSame([], PlaceholderSyntax::unsupported('/api/user/{id}/posts/{slug}'));
    }

    #[Test]
    public function flagsAnInlineRequirement(): void
    {
        self::assertSame(['{id<\d+>}'], PlaceholderSyntax::unsupported('/api/user/{id<\d+>}'));
    }

    #[Test]
    public function flagsAnInlineRequirementContainingBraces(): void
    {
        self::assertSame(['{id<\d{2}>}'], PlaceholderSyntax::unsupported('/api/user/{id<\d{2}>}'));
    }

    #[Test]
    public function flagsAnInlineDefault(): void
    {
        self::assertSame(['{page<\d+>?1}'], PlaceholderSyntax::unsupported('/api/blog/{page<\d+>?1}'));
    }

    #[Test]
    public function flagsAnImportantPlaceholder(): void
    {
        self::assertSame(['{!page}'], PlaceholderSyntax::unsupported('/api/blog/{!page}'));
    }

    #[Test]
    public function flagsAnInlineEntityMapping(): void
    {
        self::assertSame(['{user:id}'], PlaceholderSyntax::unsupported('/api/user/{user:id}'));
    }

    #[Test]
    public function flagsEveryOffenderInOrderOfAppearance(): void
    {
        self::assertSame(['{!a}', '{c<\d+>}'], PlaceholderSyntax::unsupported('/api/{!a}/{b}/{c<\d+>}'));
    }

    #[Test]
    public function flagsAnUnclosedPlaceholder(): void
    {
        self::assertSame(['{id'], PlaceholderSyntax::unsupported('/api/user/{id'));
    }

    #[Test]
    public function flagsAnEmptyPlaceholder(): void
    {
        self::assertSame(['{}'], PlaceholderSyntax::unsupported('/api/user/{}'));
    }

    #[Test]
    public function flagsAStrayClosingBrace(): void
    {
        self::assertSame(['}'], PlaceholderSyntax::unsupported('/api/user/id}'));
    }
}
