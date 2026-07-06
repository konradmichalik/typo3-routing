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

namespace KonradMichalik\Typo3Routing\RateLimit;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\NormalizedParams;

use function is_string;

/**
 * ClientKeyResolver.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ClientKeyResolver
{
    public function __construct(
        private Context $context,
    ) {}

    /**
     * The rate-limiter bucket key for a request. `keyBy: 'user'` throttles per logged-in frontend
     * user; anonymous requests (and `keyBy: 'ip'`) fall back to the client IP. The `user:`/`ip:`
     * prefixes keep the two namespaces from colliding (a user id and an IP could look alike).
     *
     * @param array{limit: int, interval: string, policy: string, keyBy: string} $config
     */
    public function resolve(array $config, ServerRequestInterface $request): string
    {
        if ('user' === $config['keyBy']) {
            $aspect = $this->context->getAspect('frontend.user');
            if ($aspect->isLoggedIn()) {
                return 'user:'.$aspect->get('id');
            }
        }

        return 'ip:'.$this->clientIp($request);
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        // normalizedParams is set early in the frontend stack and resolves reverse-proxy headers.
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams->getRemoteAddress();
        }

        $remoteAddress = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($remoteAddress) ? $remoteAddress : '';
    }
}
