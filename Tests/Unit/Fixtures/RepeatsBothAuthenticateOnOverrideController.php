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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Fixtures;

use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Route};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\{DenyAuthenticator, PassAuthenticator};

/**
 * RepeatsBothAuthenticateOnOverrideController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RepeatsBothAuthenticateOnOverrideController extends OrCombinedAuthenticateBaseController
{
    #[Route(path: '/{uid}', name: 'detail', requirements: ['uid' => '\d+'])]
    #[Authenticate(DenyAuthenticator::class)]
    #[Authenticate(PassAuthenticator::class)]
    public function detail(int $uid): void {}
}
