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

use function str_starts_with;

/**
 * RouteUrlGenerator.
 *
 * @api
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

        // A route with a `schemes` constraint that differs from the current request forces the generator
        // to return a full absolute URL regardless of the requested reference type — Symfony cannot target
        // a different scheme with a relative path. Prepending the site base to an already-absolute URL
        // would corrupt it, so it is returned unchanged in that case.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // A route with only a `host` constraint that differs (scheme unchanged) makes Symfony return a
        // protocol-relative "network path" (`//host/path`) instead — it can express a different host
        // without a scheme. Upgrade it to a full absolute URL using the current scheme so it is unambiguous
        // and consistent with the scheme-mismatch case above; a relative site-base prefix would corrupt it.
        if (str_starts_with($path, '//')) {
            return $context->getScheme().':'.$path;
        }

        return $this->basePathResolver->prependSiteBase($request, $path);
    }
}
