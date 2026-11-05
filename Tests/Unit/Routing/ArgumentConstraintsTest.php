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

use KonradMichalik\Typo3Routing\Routing\ArgumentConstraints;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

use function mb_internal_encoding;

/**
 * ArgumentConstraintsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ArgumentConstraints::class)]
final class ArgumentConstraintsTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int}, bool}>
     */
    public static function cases(): iterable
    {
        yield 'no constraints' => [5, [], false];
        yield 'at the minimum' => [1, ['min' => 1], false];
        yield 'below the minimum' => [0, ['min' => 1], true];
        yield 'at the maximum' => [100, ['max' => 100], false];
        yield 'above the maximum' => [101, ['min' => 1, 'max' => 100], true];
        yield 'float below a float minimum' => [0.49, ['min' => 0.5], true];
        yield 'float above an int maximum' => [1.01, ['max' => 1], true];
        yield 'at the minimum length' => ['ab', ['minLength' => 2], false];
        yield 'below the minimum length' => ['a', ['minLength' => 2], true];
        yield 'multibyte at the maximum length' => ['äöü', ['maxLength' => 3], false];
        yield 'above the maximum length' => ['abcd', ['minLength' => 1, 'maxLength' => 3], true];
        yield 'empty string with maximum length zero' => ['', ['maxLength' => 0], false];
        yield 'a value that is neither number nor string' => [true, ['min' => 1, 'maxLength' => 0], false];
        yield 'infinity above a minimum' => [\INF, ['min' => 1], true];
        yield 'NAN within a range' => [\NAN, ['min' => 0, 'max' => 1], true];
        yield 'infinity without constraints' => [\INF, [], false];
    }

    /**
     * @param array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int} $constraints
     */
    #[Test]
    #[DataProvider('cases')]
    public function tellsWhetherAValueBreaksItsConstraints(mixed $value, array $constraints, bool $violated): void
    {
        self::assertSame($violated, ArgumentConstraints::violates($value, $constraints));
    }

    #[Test]
    public function countsUtf8CharactersWhateverTheInternalEncoding(): void
    {
        $previous = mb_internal_encoding();
        mb_internal_encoding('8bit');

        try {
            self::assertFalse(ArgumentConstraints::violates('äöü', ['maxLength' => 3]));
        } finally {
            mb_internal_encoding($previous);
        }
    }
}
