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

use Psr\Http\Message\{ServerRequestInterface, UriInterface};
use TYPO3\CMS\Core\Site\Entity\{SiteInterface, SiteLanguage};

use function strlen;

/**
 * SiteBasePathResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteBasePathResolver
{
    public function stripSiteBase(ServerRequestInterface $request): string
    {
        // Percent-encoded, not decoded — see "Encoded vs. decoded paths" in docs/background/how-it-works.md.
        $path = $request->getUri()->getPath();
        $basePath = $this->resolveBasePath($request);

        if ('' !== $basePath && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        return '/'.ltrim($path, '/');
    }

    public function prependSiteBase(ServerRequestInterface $request, string $path): string
    {
        $normalized = '/'.ltrim($path, '/');
        $basePath = $this->resolveBasePath($request);

        return '' === $basePath ? $normalized : $basePath.$normalized;
    }

    public function resolveBasePath(ServerRequestInterface $request): string
    {
        $language = $request->getAttribute('language');
        if ($language instanceof SiteLanguage) {
            return $this->normalizeBasePath($language->getBase());
        }

        $site = $request->getAttribute('site');
        if ($site instanceof SiteInterface) {
            return $this->normalizeBasePath($site->getBase());
        }

        return '';
    }

    /**
     * The base URI of a site or one of its languages, its path already normalized to the same shape
     * `resolveBasePath()` returns. This is what request-less callers (CLI, scheduler, mail rendering)
     * have instead of a request: scheme, host and port come from the configured base, nothing else.
     */
    public function resolveBaseUri(SiteInterface $site, ?SiteLanguage $language = null): UriInterface
    {
        $base = $language instanceof SiteLanguage ? $language->getBase() : $site->getBase();

        return $base->withPath($this->normalizeBasePath($base));
    }

    private function normalizeBasePath(UriInterface $base): string
    {
        return rtrim($base->getPath(), '/');
    }
}
