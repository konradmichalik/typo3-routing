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

use Composer\Autoload;
use ShipMonk\ComposerDependencyAnalyser;

$rootPath = dirname(__DIR__, 2);

/** @var Autoload\ClassLoader $loader */
$loader = require $rootPath.'/vendor/autoload.php';
$loader->register();

$configuration = new ComposerDependencyAnalyser\Config\Configuration();
$configuration
    ->addPathToScan($rootPath.'/Configuration', false)
    ->addPathsToExclude([
        $rootPath.'/Tests/CGL',
        $rootPath.'/Tests/Functional/Fixtures',
    ])
    // Runtime-only requirement: the middleware is ordered after typo3/cms-frontend/site without referencing a symbol.
    ->ignoreErrorsOnPackage('typo3/cms-frontend', [ComposerDependencyAnalyser\Config\ErrorType::UNUSED_DEPENDENCY])
;

return $configuration;
