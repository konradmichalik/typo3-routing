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
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function in_array;
use function is_string;
use function preg_match;
use function sprintf;

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
     * Both tolerances are ordered strictly after the strict attempt: exact path, trailing-slash variant,
     * then the same pair again against the case-insensitive matcher — which only exists at all once a
     * route opted in via #[Route(caseInsensitive: true)].
     *
     * @return array<string, mixed> the matched route attributes
     *
     * @throws ResourceNotFoundException no route matches any tolerated variant of the path
     * @throws MethodNotAllowedException the path matches, the request method does not
     */
    public function match(string $path, RequestContext $context): array
    {
        try {
            return $this->matchTolerantly($this->registry->getMatcher($context), $path);
        } catch (ResourceNotFoundException $exception) {
            $caseInsensitiveMatcher = $this->registry->getCaseInsensitiveMatcher($context);

            if (null === $caseInsensitiveMatcher) {
                throw $exception;
            }

            $match = $this->matchTolerantly($caseInsensitiveMatcher, $path);
            $this->assertRequirementsHold($match);

            return $match;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ResourceNotFoundException
     * @throws MethodNotAllowedException
     */
    private function matchTolerantly(UrlMatcherInterface $matcher, string $path): array
    {
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
     * CaseInsensitiveRouteCompiler's "i" modifier covers the whole compiled regex, placeholder
     * requirements included, so a match from that matcher may satisfy a requirement only in the wrong
     * case. Re-checking each matched placeholder against its declared pattern restores the exact
     * semantics: the tolerance is meant for the path's literal segments, never for the constraints.
     *
     * @param array<string, mixed> $match
     *
     * @throws ResourceNotFoundException
     */
    private function assertRequirementsHold(array $match): void
    {
        /** @var array<string, string> $requirements */
        $requirements = $match['_requirements'] ?? [];

        foreach ($requirements as $name => $requirement) {
            $value = $match[$name] ?? null;

            // A requirement of '' means "presence only" and constrains nothing; anything that is not a
            // matched string (absent, or a non-string default) was never subject to the path regex.
            if ('' === $requirement || !is_string($value)) {
                continue;
            }

            if (1 !== preg_match('{^(?:'.$requirement.')$}sD', $value)) {
                throw new ResourceNotFoundException(sprintf('Value "%s" does not match the requirement for parameter "%s".', $value, $name), 1750000031);
            }
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
