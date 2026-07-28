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
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * InvokerProbeController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InvokerProbeController implements RouteControllerInterface
{
    public function probe(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse([
            'method' => $request->getMethod(),
            'host' => $request->getUri()->getHost(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getQueryParams(),
            'body' => (string) $request->getBody(),
            'idAttribute' => $request->getAttribute('id'),
            'contentType' => $request->getHeaderLine('Content-Type'),
        ]);
    }

    /**
     * A body-sourced argument next to the request, so a test can see both the resolved value and
     * the payload it was resolved from.
     */
    public function bodyProbe(string $note, ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse([
            'note' => $note,
            'body' => (string) $request->getBody(),
            'contentType' => $request->getHeaderLine('Content-Type'),
        ]);
    }

    /**
     * A body-sourced argument with a default: absent input must fall back to the default rather
     * than pick up whatever the calling request happened to carry in its own body.
     */
    public function body(string $title = 'none', int $priority = 0): JsonResponse
    {
        return new JsonResponse(['title' => $title, 'priority' => $priority]);
    }
}
