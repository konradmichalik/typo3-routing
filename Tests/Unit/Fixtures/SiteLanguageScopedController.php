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
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * SiteLanguageScopedController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/scoped', name: 'scoped_', sites: ['main'], languages: [0])]
final class SiteLanguageScopedController implements RouteControllerInterface
{
    // Inherits the class-level sites/languages restriction.
    #[Route(path: '/inherited', name: 'inherited')]
    public function inherited(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['inherited' => true]);
    }

    // Widens back to every site/language despite the class-level restriction.
    #[Route(path: '/unrestricted', name: 'unrestricted', sites: [], languages: [])]
    public function unrestricted(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['unrestricted' => true]);
    }

    // Overrides with its own, different site/language restriction.
    #[Route(path: '/override', name: 'override', sites: ['intranet'], languages: [1])]
    public function override(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['override' => true]);
    }
}
