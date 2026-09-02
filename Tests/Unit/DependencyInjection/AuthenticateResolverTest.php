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

use KonradMichalik\Typo3Routing\DependencyInjection\{AuthenticateResolver, CollectedRoutes};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\{DenyAuthenticator, PassAuthenticator};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{ClassLevelAuthenticateController, InvalidClassLevelAuthenticatorController};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition};

/**
 * AuthenticateResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(AuthenticateResolver::class)]
final class AuthenticateResolverTest extends TestCase
{
    #[Test]
    public function resolvesTheOrCombinedClassLevelAttributes(): void
    {
        $resolver = new AuthenticateResolver();

        $classAuth = $resolver->resolveClass(new ReflectionClass(ClassLevelAuthenticateController::class));

        self::assertCount(2, $classAuth);
        self::assertSame(PassAuthenticator::class, $classAuth[0]->authenticator);
        self::assertSame(DenyAuthenticator::class, $classAuth[1]->authenticator);
    }

    #[Test]
    public function returnsAnEmptyListWhenTheClassHasNoAttribute(): void
    {
        $resolver = new AuthenticateResolver();

        self::assertSame([], $resolver->resolveClass(new ReflectionClass(self::class)));
    }

    #[Test]
    public function fallsBackToTheClassLevelListWhenTheMethodHasNoneOfItsOwn(): void
    {
        $resolver = new AuthenticateResolver();
        $reflection = new ReflectionClass(ClassLevelAuthenticateController::class);
        $classAuth = $resolver->resolveClass($reflection);
        $container = $this->buildContainer();

        $auth = $resolver->resolveMethod($reflection->getMethod('classLevel'), 'class_auth_controller', $container, new CollectedRoutes(), $classAuth);

        self::assertSame([
            ['service' => PassAuthenticator::class, 'options' => []],
            ['service' => DenyAuthenticator::class, 'options' => ['role' => 'admin']],
        ], $auth);
    }

    #[Test]
    public function ownAttributeWinsEntirelyOverTheClassLevelList(): void
    {
        $resolver = new AuthenticateResolver();
        $reflection = new ReflectionClass(ClassLevelAuthenticateController::class);
        $classAuth = $resolver->resolveClass($reflection);
        $container = $this->buildContainer();

        $auth = $resolver->resolveMethod($reflection->getMethod('methodLevel'), 'class_auth_controller', $container, new CollectedRoutes(), $classAuth);

        self::assertSame([
            ['service' => PassAuthenticator::class, 'options' => []],
        ], $auth);
    }

    #[Test]
    public function returnsAnEmptyListWhenNeitherLevelDeclaresAnAttribute(): void
    {
        $resolver = new AuthenticateResolver();
        $reflection = new ReflectionClass(ClassLevelAuthenticateController::class);

        $auth = $resolver->resolveMethod($reflection->getMethod('classLevel'), 'class_auth_controller', $this->buildContainer(), new CollectedRoutes(), []);

        self::assertSame([], $auth);
    }

    #[Test]
    public function throwsWhenAClassLevelAuthenticatorDoesNotImplementTheContract(): void
    {
        $resolver = new AuthenticateResolver();
        $reflection = new ReflectionClass(InvalidClassLevelAuthenticatorController::class);
        $classAuth = $resolver->resolveClass($reflection);

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000010);

        $resolver->resolveMethod($reflection->getMethod('broken'), 'broken_class', new ContainerBuilder(), new CollectedRoutes(), $classAuth);
    }

    #[Test]
    public function throwsWhenAnAuthenticatorIsNotARegisteredService(): void
    {
        $resolver = new AuthenticateResolver();
        $reflection = new ReflectionClass(ClassLevelAuthenticateController::class);
        $classAuth = $resolver->resolveClass($reflection);

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000011);

        // PassAuthenticator/DenyAuthenticator are valid classes, but not registered as services here.
        $resolver->resolveMethod($reflection->getMethod('classLevel'), 'class_auth_controller', new ContainerBuilder(), new CollectedRoutes(), $classAuth);
    }

    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(PassAuthenticator::class, new Definition(PassAuthenticator::class));
        $container->setDefinition(DenyAuthenticator::class, new Definition(DenyAuthenticator::class));

        return $container;
    }
}
