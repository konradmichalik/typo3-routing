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

use KonradMichalik\Typo3Routing\Routing\PathPrefixGate;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * PathPrefixGateTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(PathPrefixGate::class)]
final class PathPrefixGateTest extends TestCase
{
    #[Test]
    public function matchesPathUnderAnyPrefixOfACommaSeparatedList(): void
    {
        $gate = PathPrefixGate::fromCommaList('/api/, /va/');

        self::assertTrue($gate->matches('/api/count'));
        self::assertTrue($gate->matches('/va/count'));
        self::assertFalse($gate->matches('/some/page'));
    }

    #[Test]
    public function ignoresSurroundingWhitespaceAndEmptyEntries(): void
    {
        $gate = PathPrefixGate::fromCommaList('  /api/ ,, ,  /va/  ');

        self::assertTrue($gate->matches('/api/count'));
        self::assertTrue($gate->matches('/va/count'));
    }

    #[Test]
    public function anEmptyListMatchesNothing(): void
    {
        $gate = PathPrefixGate::fromCommaList('');

        self::assertFalse($gate->matches('/api/count'));
        self::assertFalse($gate->matches('/'));
        self::assertFalse($gate->matches(''));
    }

    #[Test]
    public function anEmptyPrefixListMatchesNothing(): void
    {
        self::assertFalse((new PathPrefixGate([]))->matches('/api/count'));
    }

    /**
     * A route without a static prefix contributes the empty string, which matches every path — the
     * matcher then has to decide, exactly as it would without a gate.
     */
    #[Test]
    public function anEmptyStringPrefixMatchesEveryPath(): void
    {
        $gate = new PathPrefixGate(['']);

        self::assertTrue($gate->matches('/api/count'));
        self::assertTrue($gate->matches('/some/page'));
        self::assertTrue($gate->matches(''));
    }

    #[Test]
    public function mergingCombinesBothSidesOfTheGate(): void
    {
        $gate = (new PathPrefixGate(['/va/count']))->mergedWith(PathPrefixGate::fromCommaList('/api/'));

        self::assertTrue($gate->matches('/va/count'));
        self::assertTrue($gate->matches('/api/anything'));
        self::assertFalse($gate->matches('/va/other'));
    }

    /**
     * A case-insensitive route's prefix has to pass the gate in every casing, or the request never
     * reaches the matcher that would tolerate it.
     */
    #[Test]
    public function matchesADifferentlyCasedPathUnderACaseInsensitivePrefix(): void
    {
        $gate = new PathPrefixGate([], ['/api/']);

        self::assertTrue($gate->matches('/api/count'));
        self::assertTrue($gate->matches('/API/Count'));
        self::assertFalse($gate->matches('/some/page'));
    }

    #[Test]
    public function aCaseSensitivePrefixStillRejectsADifferentlyCasedPath(): void
    {
        self::assertFalse((new PathPrefixGate(['/api/']))->matches('/API/count'));
    }

    #[Test]
    public function aCaseInsensitivePrefixIsNormalisedRegardlessOfHowItWasDeclared(): void
    {
        self::assertTrue((new PathPrefixGate([], ['/API/']))->matches('/api/count'));
    }

    #[Test]
    public function mergingKeepsTheCaseInsensitivePrefixesOfBothSides(): void
    {
        $gate = (new PathPrefixGate([], ['/va/']))->mergedWith(new PathPrefixGate([], ['/api/']));

        self::assertTrue($gate->matches('/VA/count'));
        self::assertTrue($gate->matches('/API/count'));
        self::assertFalse($gate->matches('/some/page'));
    }

    /**
     * strtolower() only folds ASCII A-Z, so a request for the lower-case multibyte "über" would never
     * match a declared "Über" prefix under byte-wise ASCII folding — exactly the gap this gate has to
     * close to agree with CaseInsensitiveRouteCompiler's own "iu"-modifier regex.
     */
    #[Test]
    public function matchesANonAsciiPrefixAcrossUnicodeCase(): void
    {
        $gate = new PathPrefixGate([], ['/api/Über']);

        self::assertTrue($gate->matches('/api/über'));
        self::assertTrue($gate->matches('/api/ÜBER'));
    }
}
