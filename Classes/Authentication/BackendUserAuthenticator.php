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

namespace KonradMichalik\Typo3Routing\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;

/**
 * BackendUserAuthenticator.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class BackendUserAuthenticator implements RouteAuthenticatorInterface
{
    public function __construct(
        private Context $context,
    ) {}

    public function authenticate(ServerRequestInterface $request, array $options = []): bool
    {
        // TYPO3 always resolves "backend.user" to a UserAspect, lazily creating a logged-out one when unset.
        return $this->context->getAspect('backend.user')->isLoggedIn();
    }
}
