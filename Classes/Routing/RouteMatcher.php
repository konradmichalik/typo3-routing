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

namespace KonradMichalik\Typo3Routing\Routing;

use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function in_array;

/**
 * RouteMatcher.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteMatcher
{
    private bool $tolerateTrailingSlash;

    public function __construct(
        private RouteRegistry $registry,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $this->tolerateTrailingSlash = self::readToggle($extensionConfiguration);
    }

    /**
     * The retry only ever runs once the exact path has already failed, so ordinary requests pay nothing
     * for the tolerance. A MethodNotAllowedException is never retried: it means the path itself did
     * match, so its list of allowed methods is the correct answer.
     *
     * @return array<string, mixed> the matched route attributes
     *
     * @throws ResourceNotFoundException no route matches either variant of the path
     * @throws MethodNotAllowedException the path matches, the request method does not
     */
    public function match(string $path, RequestContext $context): array
    {
        $matcher = $this->registry->getMatcher($context);

        try {
            return $matcher->match($path);
        } catch (ResourceNotFoundException $exception) {
            $variant = $this->trailingSlashVariant($path);

            if (null === $variant) {
                throw $exception;
            }

            return $matcher->match($variant);
        }
    }

    /**
     * The same path with its trailing slash added or removed, or null when there is nothing to try:
     * the tolerance is switched off, or stripping would leave the empty path (`/`, which no route can
     * be declared as anyway — its slash is the path, not a suffix).
     */
    private function trailingSlashVariant(string $path): ?string
    {
        if (!$this->tolerateTrailingSlash) {
            return null;
        }

        if (!str_ends_with($path, '/')) {
            return $path.'/';
        }

        return '/' === $path ? null : substr($path, 0, -1);
    }

    /**
     * Defaults to enabled: an extension that was never configured has no stored value, and the
     * shipped default in ext_conf_template.txt is on.
     */
    private static function readToggle(ExtensionConfiguration $extensionConfiguration): bool
    {
        try {
            $value = $extensionConfiguration->get('typo3_routing', 'trailingSlash');
        } catch (Throwable) {
            return true;
        }

        return !in_array($value, ['0', 0, false], true);
    }
}
