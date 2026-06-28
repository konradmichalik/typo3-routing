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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Attribute;

use KonradMichalik\Typo3Routing\Attribute\RequireRequestToken;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * RequireRequestTokenTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RequireRequestToken::class)]
final class RequireRequestTokenTest extends TestCase
{
    #[Test]
    public function defaultsToNullScope(): void
    {
        self::assertNull((new RequireRequestToken())->scope);
    }

    #[Test]
    public function storesExplicitScope(): void
    {
        self::assertSame('routing/account-update', (new RequireRequestToken(scope: 'routing/account-update'))->scope);
    }
}
