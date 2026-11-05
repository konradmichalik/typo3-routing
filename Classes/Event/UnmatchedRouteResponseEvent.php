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

namespace KonradMichalik\Typo3Routing\Event;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

/**
 * UnmatchedRouteResponseEvent.
 *
 * Dispatched when the dispatcher answers a 404 of its own: a path in an exclusive prefix that no route
 * matched after every tolerance (trailing slash, case, legacy paths, schemes), or a route hidden by its
 * `env`, `sites` or `languages`. A listener may set its own response, otherwise the default problem
 * response is sent. The event carries no route data, so a hidden route stays indistinguishable from a
 * missing one.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UnmatchedRouteResponseEvent
{
    private ?ResponseInterface $response = null;

    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly string $path,
    ) {}

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * The request path relative to the site base, as the matcher saw it (e.g. `/api/missing`).
     */
    public function getPath(): string
    {
        return $this->path;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }
}
