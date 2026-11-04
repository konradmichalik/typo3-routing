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

use KonradMichalik\Typo3Routing\Attribute\InvalidatesCache;
use LogicException;
use ReflectionMethod;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

use function array_column;
use function array_diff;
use function array_intersect;
use function array_merge;
use function implode;
use function preg_match;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/**
 * InvalidatesCacheResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class InvalidatesCacheResolver
{
    /**
     * Stores the method's #[InvalidatesCache] tags on $collected, if it declares the attribute.
     *
     * @param list<string> $methods the route's HTTP methods, empty meaning any
     */
    public function apply(ReflectionMethod $method, array $methods, string $serviceId, string $name, CollectedRoutes $collected): void
    {
        $attributes = $method->getAttributes(InvalidatesCache::class);
        if ([] === $attributes) {
            return;
        }

        $tags = $attributes[0]->newInstance()->tags;
        if ([] === $tags) {
            throw new LogicException(sprintf('#[InvalidatesCache] on "%s::%s()" (route "%s") declares no tags, so it would never flush anything. List the #[Cache] tags the route invalidates.', $serviceId, $method->getName(), $name), 1750000051);
        }

        foreach ($tags as $tag) {
            // The cache frontend rejects such a tag only at flush time, after the controller already wrote.
            if (1 !== preg_match(FrontendInterface::PATTERN_TAG, $tag)) {
                throw new LogicException(sprintf('#[InvalidatesCache] on "%s::%s()" (route "%s") declares the invalid cache tag "%s": tags may only contain letters, digits, "_", "%%", "-" and "&", at most 250 characters.', $serviceId, $method->getName(), $name, $tag), 1750000052);
            }
        }

        $collected->cacheInvalidations[$name] = $tags;
        $this->warnIfSafeMethod($methods, $name, $serviceId, $method->getName());
    }

    /**
     * The response cache only holds tags some #[Cache] sets, so any other invalidation tag (usually a
     * typo) can never match an entry. Runs once every route is collected.
     */
    public function warnAboutUndeclaredTags(CollectedRoutes $collected): void
    {
        $cachedTags = array_merge([], ...array_column($collected->cacheConfigs, 'tags'));

        foreach ($collected->cacheInvalidations as $name => $tags) {
            $undeclared = array_diff($tags, $cachedTags);
            if ([] === $undeclared) {
                continue;
            }

            trigger_error(sprintf('Route "%s" invalidates the response cache tag(s) "%s", but no #[Cache] route declares them, so the flush can never remove anything. Check the tag names for typos.', $name, implode('", "', $undeclared)), E_USER_WARNING);
        }
    }

    /**
     * A GET or HEAD request should not change state: crawlers and prefetching browsers send them freely,
     * so a route flushing on them empties the response cache at the whim of every visitor.
     *
     * @param list<string> $methods
     */
    private function warnIfSafeMethod(array $methods, string $name, string $serviceId, string $methodName): void
    {
        if ([] !== $methods && [] === array_intersect(['GET', 'HEAD'], $methods)) {
            return;
        }

        trigger_error(sprintf('Route "%s" (%s::%s()) flushes response cache tags on a GET request: #[InvalidatesCache] applies to every method the route answers, and GET/HEAD requests are sent freely by crawlers and browsers. Restrict the #[Route] "methods" to the writing ones (e.g. POST, PUT, PATCH, DELETE).', $name, $serviceId, $methodName), E_USER_WARNING);
    }
}
