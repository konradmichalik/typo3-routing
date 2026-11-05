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

namespace KonradMichalik\Typo3Routing\Tests\Unit\DependencyInjection;

use Closure;
use KonradMichalik\Typo3Routing\DependencyInjection\{CollectedRoutes, InvalidatesCacheResolver};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{EmptyInvalidatesCacheController, InvalidTagInvalidatesCacheController, InvalidatesCacheController};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

/**
 * InvalidatesCacheResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(InvalidatesCacheResolver::class)]
final class InvalidatesCacheResolverTest extends TestCase
{
    #[Test]
    public function storesTheDeclaredTagsUnderTheRouteName(): void
    {
        $collected = new CollectedRoutes();

        (new InvalidatesCacheResolver())->apply(new ReflectionMethod(InvalidatesCacheController::class, 'create'), ['POST'], 'svc', 'news_create', $collected);

        self::assertSame(['news_create' => ['tx_news_domain_model_news', 'news_list']], $collected->cacheInvalidations);
    }

    #[Test]
    public function storesNothingForAMethodWithoutTheAttribute(): void
    {
        $collected = new CollectedRoutes();

        (new InvalidatesCacheResolver())->apply(new ReflectionMethod(InvalidatesCacheController::class, 'list'), ['GET'], 'svc', 'news_list', $collected);

        self::assertSame([], $collected->cacheInvalidations);
    }

    #[Test]
    public function rejectsAnEmptyTagList(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000051);
        $this->expectExceptionMessageMatches('/#\[InvalidatesCache\] on "svc::create\(\)" \(route "news_create"\) declares no tags/');

        (new InvalidatesCacheResolver())->apply(new ReflectionMethod(EmptyInvalidatesCacheController::class, 'create'), ['POST'], 'svc', 'news_create', new CollectedRoutes());
    }

    #[Test]
    public function rejectsATagTheCacheFrontendWouldRefuse(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000052);
        $this->expectExceptionMessageMatches('/#\[InvalidatesCache\] on "svc::create\(\)" \(route "news_create"\) declares the invalid cache tag "news\.item"/');

        (new InvalidatesCacheResolver())->apply(new ReflectionMethod(InvalidTagInvalidatesCacheController::class, 'create'), ['POST'], 'svc', 'news_create', new CollectedRoutes());
    }

    #[Test]
    public function warnsAboutAnInvalidationTagNoCacheDeclares(): void
    {
        $collected = new CollectedRoutes();
        $collected->cacheConfigs['news_list'] = ['lifetime' => 60, 'tags' => ['news_list'], 'ignoreParams' => []];
        $collected->cacheInvalidations['news_create'] = ['news_list', 'news_lsit', 'teaser'];

        $warnings = $this->captureUndeclaredTagWarnings($collected);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('Route "news_create" invalidates the response cache tag(s) "news_lsit", "teaser"', $warnings[0]);
    }

    #[Test]
    public function staysSilentWhenACachedRouteDeclaresEveryInvalidationTag(): void
    {
        $collected = new CollectedRoutes();
        $collected->cacheConfigs['news_list'] = ['lifetime' => 60, 'tags' => ['news_list'], 'ignoreParams' => []];
        $collected->cacheConfigs['teaser'] = ['lifetime' => 60, 'tags' => ['teaser'], 'ignoreParams' => []];
        $collected->cacheInvalidations['news_create'] = ['news_list', 'teaser'];

        self::assertSame([], $this->captureUndeclaredTagWarnings($collected));
    }

    #[Test]
    public function staysSilentForAnUnsafeMethod(): void
    {
        $warnings = $this->captureWarnings(['POST', 'DELETE']);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function warnsWhenTheRouteAnswersGet(): void
    {
        $warnings = $this->captureWarnings(['GET', 'POST']);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('Route "news_create" (svc::create()) flushes response cache tags on a GET request', $warnings[0]);
    }

    #[Test]
    public function warnsWhenTheRouteAnswersHead(): void
    {
        self::assertCount(1, $this->captureWarnings(['HEAD']));
    }

    #[Test]
    public function warnsWhenTheRouteAnswersAnyMethod(): void
    {
        self::assertCount(1, $this->captureWarnings([]));
    }

    /**
     * @param list<string> $methods
     *
     * @return list<string>
     */
    private function captureWarnings(array $methods): array
    {
        return $this->warningsOf(static fn () => (new InvalidatesCacheResolver())->apply(new ReflectionMethod(InvalidatesCacheController::class, 'create'), $methods, 'svc', 'news_create', new CollectedRoutes()));
    }

    /**
     * @return list<string>
     */
    private function captureUndeclaredTagWarnings(CollectedRoutes $collected): array
    {
        return $this->warningsOf(static fn () => (new InvalidatesCacheResolver())->warnAboutUndeclaredTags($collected));
    }

    /**
     * @return list<string>
     */
    private function warningsOf(Closure $call): array
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        }, E_USER_WARNING);

        try {
            $call();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }
}
