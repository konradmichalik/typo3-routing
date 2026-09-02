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

use KonradMichalik\Typo3Routing\Attribute\RateLimit;
use LogicException;
use ReflectionClass;
use ReflectionMethod;

use function in_array;
use function sprintf;

/**
 * RateLimitResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RateLimitResolver
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_POLICIES = ['sliding_window', 'fixed_window'];

    /**
     * @var list<string>
     */
    private const SUPPORTED_KEYS = ['ip', 'user'];

    /**
     * Reads the optional class-level #[RateLimit], used as the fallback for methods without their own.
     * PHP itself rejects a second non-repeatable #[RateLimit] on the same class, so no "at most one"
     * check is needed here. Not validated here — an unused class-level rate limit (every method
     * overrides it) never needs to resolve, same laziness as the class-level #[Cors]/#[DeprecatedRoute]
     * fallback.
     *
     * @param ReflectionClass<object> $reflection
     */
    public function resolveClass(ReflectionClass $reflection): ?RateLimit
    {
        $attributes = $reflection->getAttributes(RateLimit::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }

    /**
     * The method's own #[RateLimit] wins entirely over the class-level one — it is not merged field by
     * field, same rule as #[Cors] and #[DeprecatedRoute].
     */
    public function resolveMethod(ReflectionMethod $method, ?RateLimit $classRateLimit): ?RateLimit
    {
        $attributes = $method->getAttributes(RateLimit::class);

        return [] !== $attributes ? $attributes[0]->newInstance() : $classRateLimit;
    }

    /**
     * Validates and stores the resolved #[RateLimit] into the collected route metadata; a no-op when
     * the route declares none.
     */
    public function apply(?RateLimit $rateLimit, string $name, string $serviceId, string $method, CollectedRoutes $collected): void
    {
        if (null === $rateLimit) {
            return;
        }

        if (!in_array($rateLimit->policy, self::SUPPORTED_POLICIES, true)) {
            throw new LogicException(sprintf('Unsupported #[RateLimit] policy "%s" on "%s::%s()". Supported policies are "%s".', $rateLimit->policy, $serviceId, $method, implode('", "', self::SUPPORTED_POLICIES)), 1750000001);
        }

        if (!in_array($rateLimit->keyBy, self::SUPPORTED_KEYS, true)) {
            throw new LogicException(sprintf('Unsupported #[RateLimit] keyBy "%s" on "%s::%s()". Supported keys are "%s".', $rateLimit->keyBy, $serviceId, $method, implode('", "', self::SUPPORTED_KEYS)), 1750000024);
        }

        $collected->rateLimits[$name] = [
            'limit' => $rateLimit->limit,
            'interval' => $rateLimit->interval,
            'policy' => $rateLimit->policy,
            'keyBy' => $rateLimit->keyBy,
        ];
    }
}
