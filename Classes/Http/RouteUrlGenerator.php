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

namespace KonradMichalik\Typo3Routing\Http;

use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Generator\{UrlGenerator, UrlGeneratorInterface};
use Symfony\Component\Routing\RequestContext;

/**
 * RouteUrlGenerator.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteUrlGenerator
{
    public function __construct(
        private RouteRegistry $registry,
        private SiteBasePathResolver $basePathResolver,
    ) {}

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(ServerRequestInterface $request, string $route, array $parameters = []): string
    {
        $context = new RequestContext();
        $context->setHost($request->getUri()->getHost());
        $context->setScheme($request->getUri()->getScheme());

        $generator = new UrlGenerator($this->registry->getRouteCollection(), $context);
        $path = $generator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);

        return $this->basePathResolver->prependSiteBase($request, $path);
    }
}
