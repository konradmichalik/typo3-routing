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
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function getenv;
use function hash_equals;
use function is_string;
use function str_starts_with;
use function substr;

/**
 * BearerTokenAuthenticator.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class BearerTokenAuthenticator implements RouteAuthenticatorInterface
{
    private const DEFAULT_ENV_NAME = 'ROUTING_BEARER_TOKEN';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function authenticate(ServerRequestInterface $request, array $options = []): bool
    {
        $expected = $this->readEnv($this->resolveEnvName($options));
        if ('' === $expected) {
            // FAIL CLOSED — variable not set ⇒ never wave anything through.
            return false;
        }

        $header = $request->getHeaderLine('Authorization');
        $provided = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        return '' !== $provided && hash_equals($expected, $provided);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveEnvName(array $options): string
    {
        $fromOptions = $options['envName'] ?? null;
        if (is_string($fromOptions) && '' !== $fromOptions) {
            return $fromOptions;
        }

        try {
            $configured = $this->extensionConfiguration->get('typo3_routing', 'bearerTokenEnvName');
            if (is_string($configured) && '' !== $configured) {
                return $configured;
            }
        } catch (Throwable) {
            // Extension not configured yet — fall back to the default name.
        }

        return self::DEFAULT_ENV_NAME;
    }

    private function readEnv(string $name): string
    {
        // getenv() reads the real process environment, which is what survives php-fpm's clear_env and is
        // the reliable source in production (see the deployment notes in the README). $_ENV is the fallback
        // for SAPIs whose variables_order omits "E".
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : '';
    }
}
