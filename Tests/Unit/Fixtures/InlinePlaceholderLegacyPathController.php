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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Fixtures;

use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;

/**
 * InlinePlaceholderLegacyPathController.
 *
 * The declared path carries no placeholder, so the legacy path's placeholder set compares equal to it
 * and LegacyPathValidator lets the inline form through — only the syntax guard catches it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InlinePlaceholderLegacyPathController implements RouteControllerInterface
{
    #[Route(path: '/api/user', name: 'user_list', legacyPaths: ['/api/old-user/{id<\d+>}'])]
    public function list(): void {}
}
