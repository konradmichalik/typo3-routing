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

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;

/**
 * FakeUser.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class FakeUser extends AbstractUserAuthentication
{
    public function __construct()
    {
        $this->loginType = 'FE';
        $this->userid_column = 'uid';
        parent::__construct();
    }
}
