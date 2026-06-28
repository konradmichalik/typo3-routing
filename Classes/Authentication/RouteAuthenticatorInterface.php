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

/**
 * RouteAuthenticatorInterface.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
interface RouteAuthenticatorInterface
{
    /**
     * @param array<string, mixed> $options options passed verbatim from the #[Authenticate] attribute
     *
     * @return bool true = authenticated, false = not
     */
    public function authenticate(ServerRequestInterface $request, array $options = []): bool;
}
