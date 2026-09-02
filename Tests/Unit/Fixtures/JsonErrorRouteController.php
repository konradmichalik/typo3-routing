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
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\{JsonResponse, Response};

/**
 * JsonErrorRouteController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class JsonErrorRouteController implements RouteControllerInterface
{
    #[Route(path: '/api/json-errors/bare', name: 'json_errors_bare')]
    public function bare(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    // Nullable: the method can legitimately answer with something other than JSON (null), so the
    // return type alone is not a promise every response (or error) is JSON.
    #[Route(path: '/api/json-errors/nullable', name: 'json_errors_nullable')]
    public function nullable(): ?JsonResponse
    {
        return 0 === random_int(0, 1) ? new JsonResponse(['ok' => true]) : null;
    }

    #[Route(path: '/api/json-errors/other', name: 'json_errors_other')]
    public function other(): ResponseInterface
    {
        return 0 === random_int(0, 1) ? new Response() : new JsonResponse(['ok' => true]);
    }

    // A union type: PHP reflects this as a ReflectionUnionType, never a ReflectionNamedType.
    #[Route(path: '/api/json-errors/union', name: 'json_errors_union')]
    public function union(): JsonResponse|ResponseInterface
    {
        return new JsonResponse(['ok' => true]);
    }
}
