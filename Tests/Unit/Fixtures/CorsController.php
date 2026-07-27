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

use KonradMichalik\Typo3Routing\Attribute\{Cors, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * CorsController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Cors(allowedOrigins: ['https://class.example.com'])]
final class CorsController implements RouteControllerInterface
{
    // No own #[Cors]: falls back to the class-level override.
    #[Route(path: '/api/cors/class-level', name: 'cors_class_level')]
    public function classLevel(): JsonResponse
    {
        return new JsonResponse([]);
    }

    // Own #[Cors] wins entirely over the class-level one.
    #[Route(path: '/api/cors/method-level', name: 'cors_method_level')]
    #[Cors(allowedOrigins: ['https://method.example.com'], allowCredentials: true, maxAge: 600)]
    public function methodLevel(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
