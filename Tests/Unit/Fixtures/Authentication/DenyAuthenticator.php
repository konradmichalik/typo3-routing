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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication;

use KonradMichalik\Typo3Routing\Authentication\RouteAuthenticatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DenyAuthenticator.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DenyAuthenticator implements RouteAuthenticatorInterface
{
    public function authenticate(ServerRequestInterface $request, array $options = []): bool
    {
        return false;
    }
}
