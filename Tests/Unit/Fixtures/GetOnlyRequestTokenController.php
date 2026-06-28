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

use KonradMichalik\Typo3Routing\Attribute\{RequireRequestToken, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * GetOnlyRequestTokenController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class GetOnlyRequestTokenController implements RouteControllerInterface
{
    #[Route(path: '/api/get-token', name: 'fixture_get_token')]
    #[RequireRequestToken]
    public function getToken(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
