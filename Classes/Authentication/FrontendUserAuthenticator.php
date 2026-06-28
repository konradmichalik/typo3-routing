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

use function array_intersect;
use function array_map;
use function is_array;

/**
 * FrontendUserAuthenticator.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class FrontendUserAuthenticator implements RouteAuthenticatorInterface
{
    public function __construct(
        private Context $context,
    ) {}

    public function authenticate(ServerRequestInterface $request, array $options = []): bool
    {
        // TYPO3 always resolves "frontend.user" to a UserAspect, lazily creating a logged-out one when unset.
        $aspect = $this->context->getAspect('frontend.user');
        if (!$aspect->isLoggedIn()) {
            return false;
        }

        $requiredGroups = $options['groups'] ?? null;
        if (is_array($requiredGroups) && [] !== $requiredGroups) {
            $required = array_map(static fn (mixed $id): int => (int) $id, $requiredGroups);

            return [] !== array_intersect($required, $aspect->getGroupIds());
        }

        return true;
    }
}
