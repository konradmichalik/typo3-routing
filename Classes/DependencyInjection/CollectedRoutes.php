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
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CollectedRoutes
{
    /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>}> */
    public array $routes = [];

    /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> */
    public array $cacheConfigs = [];

    /** @var array<string, array{limit: int, interval: string, policy: string}> */
    public array $rateLimits = [];

    /** @var array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>> */
    public array $arguments = [];

    /** @var array<string, list<array{service: string, options: array<string, mixed>}>> */
    public array $authenticators = [];

    /** @var array<string, string> */
    public array $requestTokenScopes = [];

    /** @var array<string, Reference> */
    public array $authenticatorReferences = [];
}
