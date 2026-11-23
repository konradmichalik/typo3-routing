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

namespace KonradMichalik\Typo3Routing\Cache;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;

/**
 * CacheBypassGuard.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CacheBypassGuard
{
    public function __construct(
        private Context $context,
    ) {}

    /**
     * A backend user previewing draft/unpublished content must never be served a stored response, since the
     * cache key does not vary by preview state. A client "Cache-Control" header is deliberately ignored, so no
     * client can skip the cache and force a controller run.
     */
    public function skipsRead(ServerRequestInterface $request): bool
    {
        return $this->isBackendUser();
    }

    /**
     * A backend user's response must never be stored either, since it may carry preview/draft content that a
     * public visitor must not receive on the next cache miss.
     */
    public function skipsWrite(ServerRequestInterface $request): bool
    {
        return $this->isBackendUser();
    }

    private function isBackendUser(): bool
    {
        // TYPO3 always resolves "backend.user" to a UserAspect, lazily creating a logged-out one when unset.
        return $this->context->getAspect('backend.user')->isLoggedIn();
    }
}
