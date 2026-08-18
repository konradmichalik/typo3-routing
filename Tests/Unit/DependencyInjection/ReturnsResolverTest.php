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

use KonradMichalik\Typo3Routing\DependencyInjection\{CollectedRoutes, ReturnsResolver};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Dto\CourseDto;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{DuplicateReturnsStatusController, ReturnsController};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ReturnsResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ReturnsResolver::class)]
final class ReturnsResolverTest extends TestCase
{
    #[Test]
    public function collectsEveryDeclaredReturnsAttribute(): void
    {
        $resolver = new ReturnsResolver();
        $method = (new ReflectionClass(ReturnsController::class))->getMethod('course');
        $collected = new CollectedRoutes();

        $resolver->apply($method, 'ctrl', 'returns_course', $collected);

        self::assertSame(
            [
                ['status' => 200, 'schema' => CourseDto::class, 'collection' => false, 'description' => null],
                ['status' => 404, 'schema' => null, 'collection' => false, 'description' => 'Course not found'],
            ],
            $collected->returns['returns_course'],
        );
    }

    #[Test]
    public function collectsACollectionReturnsAttribute(): void
    {
        $resolver = new ReturnsResolver();
        $method = (new ReflectionClass(ReturnsController::class))->getMethod('courses');
        $collected = new CollectedRoutes();

        $resolver->apply($method, 'ctrl', 'returns_courses', $collected);

        self::assertTrue($collected->returns['returns_courses'][0]['collection']);
    }

    #[Test]
    public function doesNotStoreAnEntryWhenNoReturnsWasDeclared(): void
    {
        $resolver = new ReturnsResolver();
        $method = (new ReflectionClass(ReturnsController::class))->getMethod('plain');
        $collected = new CollectedRoutes();

        $resolver->apply($method, 'ctrl', 'returns_plain', $collected);

        self::assertArrayNotHasKey('returns_plain', $collected->returns);
    }

    #[Test]
    public function aNullSchemaDescribesAResponseWithNoBody(): void
    {
        $resolver = new ReturnsResolver();
        $method = (new ReflectionClass(ReturnsController::class))->getMethod('noBody');
        $collected = new CollectedRoutes();

        $resolver->apply($method, 'ctrl', 'returns_no_body', $collected);

        self::assertNull($collected->returns['returns_no_body'][0]['schema']);
        self::assertSame(204, $collected->returns['returns_no_body'][0]['status']);
    }

    #[Test]
    public function rejectsTwoReturnsDeclarationsForTheSameStatus(): void
    {
        $resolver = new ReturnsResolver();
        $method = (new ReflectionClass(DuplicateReturnsStatusController::class))->getMethod('action');

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000037);
        $this->expectExceptionMessageMatches('/status 200 more than once/');

        $resolver->apply($method, 'ctrl', 'duplicate_returns', new CollectedRoutes());
    }
}
