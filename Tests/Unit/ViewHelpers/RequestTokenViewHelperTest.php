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

namespace KonradMichalik\Typo3Routing\Tests\Unit\ViewHelpers;

use KonradMichalik\Typo3Routing\ViewHelpers\RequestTokenViewHelper;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Context\{Context, SecurityAspect};
use TYPO3\CMS\Core\Security\SigningSecretResolver;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function substr_count;

/**
 * RequestTokenViewHelperTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RequestTokenViewHelper::class)]
final class RequestTokenViewHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function registersTheScopeArgument(): void
    {
        self::assertArrayHasKey('scope', (new RequestTokenViewHelper(new Context()))->prepareArguments());
    }

    #[Test]
    public function rendersASignedJwtForTheScope(): void
    {
        $viewHelper = new RequestTokenViewHelper(new Context());
        $viewHelper->setArguments(['scope' => 'routing/account-update']);

        $token = $viewHelper->render();

        // A hash-signed JWT has three dot-separated segments.
        self::assertNotSame('', $token);
        self::assertSame(2, substr_count($token, '.'));
    }

    #[Test]
    public function throwsWhenNoNonceSigningProviderIsAvailable(): void
    {
        $context = new Context();
        $context->setAspect('security', new class extends SecurityAspect {
            public function getSigningSecretResolver(): SigningSecretResolver
            {
                return new SigningSecretResolver([]);
            }
        });

        $viewHelper = new RequestTokenViewHelper($context);
        $viewHelper->setArguments(['scope' => 'routing/account-update']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1750000020);

        $viewHelper->render();
    }
}
