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
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * ReroutingOverrideController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/rerouting', name: 'rerouting_')]
final class ReroutingOverrideController extends OverridableRouteController
{
    // Repeats the #[Route] but not the parent's #[Authenticate]: the endpoint is public again.
    #[Route(path: '/{uid}', name: 'detail', requirements: ['uid' => '\d+'])]
    public function detail(int $uid): JsonResponse
    {
        return new JsonResponse(['uid' => $uid, 'overridden' => true]);
    }
}
