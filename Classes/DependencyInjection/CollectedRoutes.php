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

namespace KonradMichalik\Typo3Routing\DependencyInjection;

use Symfony\Component\DependencyInjection\Reference;

/**
 * CollectedRoutes.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CollectedRoutes
{
    /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null, canonical?: bool, sites?: list<string>, languages?: list<int>, deprecation?: array{since: int, sunset: int|null, successor: string|null, documentation: string|null}}> */
    public array $routes = [];

    /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> */
    public array $cacheConfigs = [];

    /** @var array<string, array{limit: int, interval: string, policy: string, keyBy: string}> */
    public array $rateLimits = [];

    /** @var array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>> */
    public array $arguments = [];

    /** @var array<string, array<string, string>> Route name → wire name → #[Param] description */
    public array $paramDescriptions = [];

    /** @var array<string, list<string>> Route name → input keys whose #[Param] requirement is optional */
    public array $optionalInputs = [];

    /** @var array<string, list<array{service: string, options: array<string, mixed>}>> */
    public array $authenticators = [];

    /** @var array<string, string> */
    public array $requestTokenScopes = [];

    /** @var array<string, Reference> */
    public array $authenticatorReferences = [];

    /** @var array<string, array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}> */
    public array $corsConfigs = [];

    /**
     * Every opted-in class's own exclusive prefix, recorded as soon as it is resolved — independent
     * of whether the class ends up contributing any route at all. Deriving this from $routes instead
     * would silently drop a class that declares #[Route(exclusive: true)] but has no method routes.
     *
     * @var list<string>
     */
    public array $classExclusivePrefixes = [];

    /**
     * Kept here rather than inlined at the call site: a compiler pass with dozens of small collection
     * steps stays within its own cognitive-complexity budget only by pushing each step's own branching
     * onto the object that owns the data, not by accumulating every "if resolved, record it" check.
     */
    public function recordClassExclusivePrefix(?string $prefix): void
    {
        if (null === $prefix) {
            return;
        }

        $this->classExclusivePrefixes[] = $prefix;
    }

    /** @var array<string, array{since: int, sunset: int|null, successor: string|null, documentation: string|null}> */
    public array $deprecations = [];
}
