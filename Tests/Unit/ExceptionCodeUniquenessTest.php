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

namespace KonradMichalik\Typo3Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\{CoversNothing, Test};
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_filter;
use function count;
use function file_get_contents;
use function preg_match_all;

/**
 * ExceptionCodeUniquenessTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversNothing]
final class ExceptionCodeUniquenessTest extends TestCase
{
    #[Test]
    public function everyExceptionCodeInClassesIsUsedOnce(): void
    {
        $sites = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../Classes', RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            preg_match_all('/\b1750\d{6}\b/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $code) {
                $sites[$code][] = $file->getFilename();
            }
        }

        self::assertNotEmpty($sites);
        self::assertSame([], array_filter($sites, static fn (array $files): bool => count($files) > 1));
    }
}
