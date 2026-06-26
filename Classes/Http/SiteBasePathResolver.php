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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\{SiteInterface, SiteLanguage};

use function strlen;

/**
 * SiteBasePathResolver.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteBasePathResolver
{
    public function stripSiteBase(ServerRequestInterface $request): string
    {
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

    private function resolveBasePath(ServerRequestInterface $request): string
    {
        $language = $request->getAttribute('language');
        if ($language instanceof SiteLanguage) {
            return rtrim($language->getBase()->getPath(), '/');
        }

        $site = $request->getAttribute('site');
        if ($site instanceof SiteInterface) {
            return rtrim($site->getBase()->getPath(), '/');
        }

        return '';
    }
}
