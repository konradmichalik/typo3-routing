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

require __DIR__.'/../../vendor/autoload.php';

// The Tests/Support/Broken classes extend this class on purpose. PHPStan includes them while resolving
// `use` statements, so give them a parent for the analysis only.
spl_autoload_register(static function (string $class): void {
    if ('KonradMichalik\Typo3Routing\Tests\Support\Broken\NonExistentParentClassForTesting' === $class) {
        class_alias(PhpstanBrokenParentStub::class, $class);
    }
}, true, true);

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class PhpstanBrokenParentStub {}
