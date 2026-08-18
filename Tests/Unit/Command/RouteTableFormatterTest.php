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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Command;

use KonradMichalik\Typo3Routing\Command\RouteTableFormatter;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * RouteTableFormatterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteTableFormatter::class)]
final class RouteTableFormatterTest extends TestCase
{
    #[Test]
    public function formatsRequirementsAsParameterColonPatternPairs(): void
    {
        self::assertSame('id: \d+, q: ', RouteTableFormatter::requirements(['id' => '\d+', 'q' => '']));
        self::assertSame('-', RouteTableFormatter::requirements([]));
    }

    #[Test]
    public function formatsArgumentsWithTypeNullabilityAndSource(): void
    {
        $arguments = [
            ['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null],
            ['name' => 'q', 'type' => null, 'source' => 'query', 'nullable' => true, 'hasDefault' => true, 'default' => null],
        ];

        $formatted = RouteTableFormatter::arguments($arguments);

        self::assertStringContainsString('$id (int, from path)', $formatted);
        self::assertStringContainsString('$q (?mixed, from query)', $formatted);
        self::assertSame('-', RouteTableFormatter::arguments([]));
    }

    #[Test]
    public function formatsADeprecationWithSinceSunsetAndSuccessor(): void
    {
        $formatted = RouteTableFormatter::deprecation(['since' => 1735689600, 'sunset' => 1767225599, 'successor' => 'v2', 'documentation' => null]);

        self::assertSame('since: 2025-01-01, sunset: 2025-12-31, successor: v2', $formatted);
    }

    #[Test]
    public function formatsASinceOnlyDeprecationWithoutSunsetOrSuccessor(): void
    {
        self::assertSame('since: 2025-01-01', RouteTableFormatter::deprecation(['since' => 1735689600, 'sunset' => null, 'successor' => null, 'documentation' => null]));
    }

    #[Test]
    public function fallsBackToADashForARouteWithoutADeprecation(): void
    {
        self::assertSame('-', RouteTableFormatter::deprecation(null));
    }

    #[Test]
    public function leavesShortDescriptionsUntouched(): void
    {
        self::assertSame('Fetch a single course.', RouteTableFormatter::truncatedDescription('Fetch a single course.'));
        self::assertSame('-', RouteTableFormatter::truncatedDescription(null));
        self::assertSame('-', RouteTableFormatter::truncatedDescription(''));
    }

    #[Test]
    public function truncatesDescriptionsLongerThanSixtyCharactersWithAnEllipsis(): void
    {
        $description = 'Charges a payment for the current basket, only reachable over HTTPS.';

        $truncated = RouteTableFormatter::truncatedDescription($description);

        self::assertSame(60, mb_strlen($truncated));
        self::assertStringEndsWith('…', $truncated);
        self::assertStringStartsWith('Charges a payment for the current basket', $truncated);
    }

    #[Test]
    public function formatsAnyOrListAsAnyWhenEmpty(): void
    {
        self::assertSame('ANY', RouteTableFormatter::anyOrList([]));
    }

    #[Test]
    public function formatsAnyOrListAsACommaJoinedListWhenNotEmpty(): void
    {
        self::assertSame('main, intranet', RouteTableFormatter::anyOrList(['main', 'intranet']));
        self::assertSame('0, 1', RouteTableFormatter::anyOrList([0, 1]));
    }

    #[Test]
    public function formatsLegacyPathsAsACommaJoinedListOrADash(): void
    {
        self::assertSame('-', RouteTableFormatter::legacyPaths([]));
        self::assertSame('/api/old, /api/older', RouteTableFormatter::legacyPaths(['/api/old', '/api/older']));
    }

    #[Test]
    public function buildsATableRowInColumnOrder(): void
    {
        $row = [
            'name' => 'example',
            'path' => '/api/example',
            'methods' => ['GET', 'POST'],
            'controller' => 'ctrl::action',
            'env' => 'Development',
            'requirements' => ['id' => '\d+'],
            'schemes' => [],
            'host' => null,
            'description' => 'Short description.',
            'auth' => ['Acme\\Authenticator'],
            'csrf' => 'routing/example',
            'cache' => null,
            'rateLimit' => null,
            'arguments' => [],
        ];

        self::assertSame(
            ['example', '/api/example', 'GET, POST', 'ctrl::action', 'Development', 'id: \d+', 'Acme\\Authenticator', 'routing/example', 'Short description.'],
            RouteTableFormatter::tableRow($row),
        );
    }

    #[Test]
    public function fallsBackToDashesForMissingEnvAuthAndCsrfInTableRow(): void
    {
        $row = [
            'name' => 'example',
            'path' => '/api/example',
            'methods' => [],
            'controller' => 'ctrl::action',
            'env' => null,
            'requirements' => [],
            'schemes' => [],
            'host' => null,
            'description' => null,
            'auth' => [],
            'csrf' => null,
            'cache' => null,
            'rateLimit' => null,
            'arguments' => [],
        ];

        self::assertSame(
            ['example', '/api/example', '', 'ctrl::action', '-', '-', '-', '-', '-'],
            RouteTableFormatter::tableRow($row),
        );
    }
}
