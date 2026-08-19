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
 * RoutelessExclusiveController.
 *
 * Declares its exclusive claim but contributes no method route at all — the claim must still be
 * baked, not silently dropped for lack of anything else to carry it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/no-routes', exclusive: true)]
final class RoutelessExclusiveController implements RouteControllerInterface {}
