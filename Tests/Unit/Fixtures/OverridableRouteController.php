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

use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\PassAuthenticator;
use TYPO3\CMS\Core\Http\JsonResponse;

// Deliberately non-final: the two subclasses demonstrate what overriding this method costs.
/**
 * OverridableRouteController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
abstract class OverridableRouteController implements RouteControllerInterface
{
    #[Route(path: '/{uid}', name: 'detail', requirements: ['uid' => '\d+'])]
    #[Authenticate(PassAuthenticator::class)]
    public function detail(int $uid): JsonResponse
    {
        return new JsonResponse(['uid' => $uid]);
    }
}
