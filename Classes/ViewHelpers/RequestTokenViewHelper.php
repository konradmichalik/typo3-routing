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

namespace KonradMichalik\Typo3Routing\ViewHelpers;

use RuntimeException;
use TYPO3\CMS\Core\Context\{Context, SecurityAspect};
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * RequestTokenViewHelper.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RequestTokenViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly Context $context,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('scope', 'string', 'Request token scope (must match the route\'s #[RequireRequestToken] scope)', true);
    }

    public function render(): string
    {
        $scope = (string) $this->arguments['scope'];

        $securityAspect = SecurityAspect::provideIn($this->context);
        $signingProvider = $securityAspect->getSigningSecretResolver()->findByType('nonce');
        if (null === $signingProvider) {
            throw new RuntimeException('Cannot find request token signing type "nonce".', 1750000020);
        }

        return RequestToken::create($scope)->toHashSignedJwt($signingProvider->provideSigningSecret());
    }
}
