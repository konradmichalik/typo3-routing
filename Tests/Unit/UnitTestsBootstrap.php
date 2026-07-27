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

/*
 * Lightweight unit test bootstrap: unlike typo3/testing-framework's
 * UnitTestsBootstrap.php, this does not initialize the full package manager,
 * TYPO3_CONF_VARS or class loading dump - only TYPO3\CMS\Core\Core\Environment,
 * so that TYPO3\CMS\Core\Http\NormalizedParams::createFromRequest() (used by
 * ttt's Requests/RequestBuilder kit) works without every call site needing
 * ->withoutNormalizedParams().
 */

require __DIR__.'/../../vendor/autoload.php';

$projectPath = sys_get_temp_dir().'/typo3-routing-unit-tests';

foreach (['', '/public', '/var', '/config'] as $directory) {
    if (!is_dir($projectPath.$directory)) {
        mkdir($projectPath.$directory, 0o700, true);
    }
}

TYPO3\CMS\Core\Core\Environment::initialize(
    new TYPO3\CMS\Core\Core\ApplicationContext('Testing'),
    true,
    true,
    $projectPath,
    $projectPath.'/public',
    $projectPath.'/var',
    $projectPath.'/config',
    $projectPath.'/public/index.php',
    'Windows' === \PHP_OS_FAMILY ? 'WINDOWS' : 'UNIX',
);
