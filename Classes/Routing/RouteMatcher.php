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

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function in_array;
use function is_string;

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
        private SiteLanguageScope $scope,
    ) {
        $this->tolerateTrailingSlash = self::readToggle($extensionConfiguration);
    }

    /**
     * The retry only ever runs once the exact path has already failed, so ordinary requests pay nothing
     * for the tolerance. A MethodNotAllowedException is never retried at any tier: it means A path
     * already matched, so its list of allowed methods is the correct answer.
     *
     * Tiers run strictly after the strict attempt, each only once the previous one reported a plain
     * miss: exact path, trailing-slash variant, the same pair against the case-insensitive matcher
     * (#[Route(caseInsensitive: true)]), then the same pair again against the legacy-path matcher
     * (#[Route(legacyPaths: [...])]) — which rewrites `_route` back to the owning route's real name, so
     * every consumer downstream sees the same identity regardless of which path reached it — and
     * finally against the scheme-redirect matcher (#[Route(schemes: [...])]), which is why a scheme
     * mismatch surfaces as a match carrying `_schemeRedirect` rather than as a miss.
     *
     * With a request, a route invisible for it (see SiteLanguageScope) does not exist: it neither matches
     * nor lends its methods to a MethodNotAllowedException, so it answers exactly like a missing path.
     * Without one, as for routing:match, every route takes part.
     *
     * @return array<string, mixed> the matched route attributes
     *
     * @throws ResourceNotFoundException no route matches any tolerated variant of the path
     * @throws MethodNotAllowedException the path matches, the request method does not
     */
    public function match(string $path, RequestContext $context, ?ServerRequestInterface $request = null): array
    {
        if (null === $request) {
            return $this->matchTiers($path, $context, null);
        }

        // The compiled matcher cannot leave routes out, so its answer is trusted wherever hiding routes
        // could not change it: a visible match, or a plain miss. Only otherwise are the tiers repeated
        // without the hidden routes, which costs a plain UrlMatcher, but only on that error path.
        try {
            $match = $this->matchTiers($path, $context, null);
            if ($this->scope->isMatchVisible($match, $request)) {
                return $match;
            }
        } catch (MethodNotAllowedException|RequirementMismatchException $exception) {
            if (!$this->hidesAnyRoute($request)) {
                throw $exception;
            }
        }

        return $this->matchTiers($path, $context, fn (array $defaults): bool => $this->scope->isMatchVisible($defaults, $request));
    }

    /**
     * @param (Closure(array<string, mixed>): bool)|null $keep only the routes it accepts take part, see RouteRegistry::getMatcher()
     *
     * @return array<string, mixed>
     *
     * @throws ResourceNotFoundException
     * @throws MethodNotAllowedException
     */
    private function matchTiers(string $path, RequestContext $context, ?Closure $keep): array
    {
        try {
            return $this->matchTolerantly($this->registry->getMatcher($context, $keep), $path, alreadyVariant: false);
        } catch (ResourceNotFoundException $exception) {
            return $this->matchCaseInsensitively($path, $context, $keep)
                ?? $this->matchLegacyPath($path, $context, $keep)
                ?? $this->matchWrongScheme($path, $context, $keep)
                ?? throw $exception;
        }
    }

    private function hidesAnyRoute(ServerRequestInterface $request): bool
    {
        foreach ($this->registry->getRoutes() as $route) {
            if (!$this->scope->isRouteVisible($route, $request)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Null means: no route opted into #[Route(caseInsensitive: true)], or none of them matched — either
     * way the caller falls through to the next tier. A MethodNotAllowedException or a requirement
     * mismatch is not "no match": both propagate immediately, exactly as they would from the top-level
     * primary attempt.
     *
     * @param (Closure(array<string, mixed>): bool)|null $keep
     *
     * @return array<string, mixed>|null
     */
    private function matchCaseInsensitively(string $path, RequestContext $context, ?Closure $keep): ?array
    {
        $matcher = $this->registry->getCaseInsensitiveMatcher($context, $keep);
        if (null === $matcher) {
            return null;
        }

        try {
            // Reaching this matcher at all means the exact-case path already failed against the
            // standard matcher, so any match here is a tolerated variant regardless of trailing slash.
            $match = $this->matchTolerantly($matcher, $path, alreadyVariant: true);
        } catch (ResourceNotFoundException) {
            return null;
        }

        $this->assertRequirementsHold($match);

        return $match;
    }

    /**
     * Null means: no route declared a legacy path, or none of them matched. A MethodNotAllowedException
     * propagates immediately, matching every other tier.
     *
     * @param (Closure(array<string, mixed>): bool)|null $keep
     *
     * @return array<string, mixed>|null
     */
    private function matchLegacyPath(string $path, RequestContext $context, ?Closure $keep): ?array
    {
        $matcher = $this->registry->getLegacyMatcher($context, $keep);
        if (null === $matcher) {
            return null;
        }

        try {
            $match = $this->matchTolerantly($matcher, $path, alreadyVariant: true);
        } catch (ResourceNotFoundException) {
            return null;
        }

        return self::withOwningRouteName($match);
    }

    /**
     * Null means: no route declared `schemes`, or none of them matched this path. A match here means
     * everything but the scheme held — path, method, host and requirements alike — so it is stamped
     * with `_schemeRedirect` (see RouteRegistry::schemeRedirectRoutes()) and the dispatcher answers a
     * redirect to that scheme instead of a 404.
     *
     * Last of all the tiers deliberately: only once the exact path, its trailing-slash variant, the
     * case-insensitive matcher and the legacy paths have all reported a plain miss is the scheme the
     * only remaining explanation. A MethodNotAllowedException propagates as everywhere else — the
     * method is wrong on the correct scheme too, so redirecting first would only postpone the 405.
     *
     * @param (Closure(array<string, mixed>): bool)|null $keep
     *
     * @return array<string, mixed>|null
     */
    private function matchWrongScheme(string $path, RequestContext $context, ?Closure $keep): ?array
    {
        $matcher = $this->registry->getSchemeRedirectMatcher($context, $keep);
        if (null === $matcher) {
            return null;
        }

        try {
            $match = $this->matchTolerantly($matcher, $path, alreadyVariant: false);
        } catch (ResourceNotFoundException) {
            return null;
        }

        return self::withOwningRouteName($match);
    }

    /**
     * `_legacyOf` rode in as an ordinary route default (see RouteRegistry::legacyRoutes()); where it is
     * present, `_route` is rewritten to it so the dispatcher, rate limiting, caching and deprecation
     * headers all resolve against the route the path belongs to, not the internal synthetic entry name.
     *
     * @param array<string, mixed> $match
     *
     * @return array<string, mixed>
     */
    private static function withOwningRouteName(array $match): array
    {
        $legacyOf = $match['_legacyOf'] ?? null;

        return null === $legacyOf ? $match : [...$match, '_route' => $legacyOf];
    }

    /**
     * Stamps `_canonicalVariant`: whether the returned match came from a tolerated variant of the
     * declared path rather than the exact path itself. `#[Route(canonical: true)]` reads this to decide
     * whether to redirect — kept out of the matcher's own contract, since deciding what to do with the
     * signal is the dispatcher's business, not the matcher's.
     *
     * @return array<string, mixed>
     *
     * @throws ResourceNotFoundException
     * @throws MethodNotAllowedException
     */
    private function matchTolerantly(UrlMatcherInterface $matcher, string $path, bool $alreadyVariant): array
    {
        try {
            $match = $matcher->match($path);
            $match['_canonicalVariant'] = $alreadyVariant;

            return $match;
        } catch (ResourceNotFoundException $exception) {
            $variant = $this->trailingSlashVariant($path);

            if (null === $variant) {
                throw $exception;
            }

            $match = $matcher->match($variant);
            $match['_canonicalVariant'] = true;

            return $match;
        }
    }

    /**
     * CaseInsensitiveRouteCompiler's "i" modifier covers the whole compiled regex, placeholder
     * requirements included, so a match from that matcher may satisfy a requirement only in the wrong
     * case. Re-checking each matched placeholder against its declared pattern restores the exact
     * semantics: the tolerance is meant for the path's literal segments, never for the constraints.
     *
     * The route's own "u" modifier is carried over from {@see RouteRegistry::routeNeedsUtf8()}: without
     * it, a Unicode-only construct like `\p{L}+` or `\X` is checked against raw bytes instead of code
     * points and rejects every valid UTF-8 value.
     *
     * @param array<string, mixed> $match
     *
     * @throws RequirementMismatchException a ResourceNotFoundException naming what it rejected
     */
    private function assertRequirementsHold(array $match): void
    {
        /** @var array<string, string> $requirements */
        $requirements = $match['_requirements'] ?? [];
        $routeName = (string) ($match['_route'] ?? '');
        $utf8 = $this->registry->routeNeedsUtf8($routeName);

        foreach ($requirements as $name => $requirement) {
            $value = $match[$name] ?? null;

            // Anything that is not a matched string (absent, or a non-string default) was never subject to
            // the path regex. An empty requirement cannot occur: Symfony's Route rejects it.
            if (!is_string($value)) {
                continue;
            }

            if (!RequirementPattern::matches($requirement, $value, $utf8)) {
                throw new RequirementMismatchException($routeName, $name, $value, $requirement);
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
