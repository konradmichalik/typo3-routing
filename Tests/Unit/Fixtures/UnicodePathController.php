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
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * UnicodePathController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UnicodePathController implements RouteControllerInterface
{
    #[Route(path: '/api/über-uns', name: 'unicode_umlaut')]
    public function umlaut(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/api/tags/{name}', name: 'unicode_tag', requirements: ['name' => '\p{L}+'])]
    public function tag(string $name): JsonResponse
    {
        return new JsonResponse(['name' => $name]);
    }
}
