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

use KonradMichalik\Typo3Routing\Routing\RequirementPattern;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * RequirementPatternTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RequirementPattern::class)]
final class RequirementPatternTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function matchesTheWholeValueOnly(string $requirement, string $value, bool $utf8, bool $expected): void
    {
        self::assertSame($expected, RequirementPattern::matches($requirement, $value, $utf8));
    }

    /**
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function cases(): iterable
    {
        yield 'full match' => ['[a-z]+', 'abc', false, true];
        yield 'partial match' => ['[a-z]+', 'abc1', false, false];
        yield 'trailing newline' => ['[a-z]+', "abc\n", false, false];
        yield 'alternation stays anchored' => ['asc|desc', 'ascending', false, false];
        yield 'hash in requirement' => ['#\d+', '#12', false, true];
        yield 'brace quantifier' => ['\d{3}', '123', false, true];
        yield 'dot matches newline' => ['.+', "a\nb", false, true];
        yield 'unicode in utf8 mode' => ['\p{L}+', 'Zoë', true, true];
        yield 'unicode without utf8 mode' => ['\p{L}+', 'Zoë', false, false];
        yield 'invalid utf8 in utf8 mode' => ['.+', "\xC3", true, false];
    }
}
