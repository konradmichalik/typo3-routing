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

namespace KonradMichalik\Typo3Routing\DependencyInjection;

use Error;

use function class_exists;

/**
 * ClassExistenceChecker.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ClassExistenceChecker
{
    /**
     * Autoloading a class whose parent/interface no longer exists throws \Error rather than
     * returning false; that must not abort compilation over an otherwise unrelated service.
     */
    public function exists(string $class): bool
    {
        try {
            return class_exists($class);
        } catch (Error) {
            return false;
        }
    }
}
