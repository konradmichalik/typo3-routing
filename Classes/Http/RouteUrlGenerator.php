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
use Psr\Http\Message\{ServerRequestInterface, UriInterface};
use Symfony\Component\Routing\Generator\{UrlGenerator, UrlGeneratorInterface};
use Symfony\Component\Routing\RequestContext;
use TYPO3\CMS\Core\Site\Entity\{SiteInterface, SiteLanguage};

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
     * Generates a URL in the context of the given frontend request: its scheme and host, and the base
     * of the site/language it resolved to. With `$absolute`, scheme and host are put in front of that
     * same path — for a URL that has to survive leaving the page, prefer `generateForSite()`, whose
     * scheme and host come from the site configuration rather than from the incoming request.
     *
     * @param array<string, mixed> $parameters
     */
    public function generate(ServerRequestInterface $request, string $route, array $parameters = [], bool $absolute = false): string
    {
        $uri = $request->getUri();

        return $this->generateWithContext(
            $this->createContext($uri, $this->basePathResolver->resolveBasePath($request)),
            $route,
            $parameters,
            $absolute,
        );
    }

    /**
     * Generates a URL without a frontend request — for CLI commands, scheduler tasks, queue workers or
     * mail rendered outside a request. There is no current scheme/host to fall back on in that context,
     * so the site's (or language's) configured base is the sole authority for all of them.
     *
     * @param array<string, mixed> $parameters
     */
    public function generateForSite(SiteInterface $site, string $route, array $parameters = [], bool $absolute = false, ?SiteLanguage $language = null): string
    {
        $base = $this->basePathResolver->resolveBaseUri($site, $language);

        return $this->generateWithContext($this->createContext($base, $base->getPath()), $route, $parameters, $absolute);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function generateWithContext(RequestContext $context, string $route, array $parameters, bool $absolute): string
    {
        $generator = new UrlGenerator($this->registry->getRouteCollection(), $context);
        $url = $generator->generate($route, $parameters, $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH);

        // A route with only a `host` constraint that differs (scheme unchanged) makes Symfony return a
        // protocol-relative "network path" (`//host/path`) — it can express a different host without a
        // scheme. Upgrade it to a full absolute URL using the context scheme so it is unambiguous and
        // consistent with a `schemes` mismatch, which Symfony already escalates to an absolute URL.
        if (str_starts_with($url, '//')) {
            return $context->getScheme().':'.$url;
        }

        return $url;
    }

    /**
     * The site base is handed to Symfony as the context's base URL rather than prepended afterwards:
     * Symfony inserts it between the scheme authority and the path, so it survives both an explicitly
     * requested absolute URL and the absolute form a `schemes`/`host` mismatch forces. Prefixing the
     * finished string could only ever work for the relative case.
     */
    private function createContext(UriInterface $uri, string $basePath): RequestContext
    {
        $context = new RequestContext();
        $context->setHost($uri->getHost());
        $context->setBaseUrl($basePath);

        // A site base may be configured as a bare path ("/"), which carries no scheme at all. Keeping
        // the context default ("http") rather than setting an empty one keeps the URL well-formed —
        // there is nothing to make it absolute from either way, since such a base has no host.
        $scheme = $uri->getScheme();
        if ('' !== $scheme) {
            $context->setScheme($scheme);
        }

        // An explicit non-standard port belongs in the absolute form; Symfony only emits it when it
        // differs from the default it holds for the scheme.
        $port = $uri->getPort();
        if ('https' === $context->getScheme()) {
            $context->setHttpsPort($port ?? 443);
        } else {
            $context->setHttpPort($port ?? 80);
        }

        return $context;
    }
}
