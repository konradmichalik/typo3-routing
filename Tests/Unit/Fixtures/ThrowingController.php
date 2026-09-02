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

use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\{ImmediateResponseException, JsonResponse, Response};

/**
 * ThrowingController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ThrowingController implements RouteControllerInterface
{
    public function boom(): JsonResponse
    {
        throw new RuntimeException('sensitive internal detail nobody should see in a response', 6977582795);
    }

    public function shortCircuit(): JsonResponse
    {
        // TYPO3 core's own control-flow signal, not an error: must propagate unconverted.
        throw new ImmediateResponseException(new Response(statusCode: 302, headers: ['Location' => '/elsewhere']), 2465530407);
    }
}
