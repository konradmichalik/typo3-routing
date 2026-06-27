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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum;

/**
 * Priority.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum Priority: int
{
    case Low = 1;
    case High = 5;
}
