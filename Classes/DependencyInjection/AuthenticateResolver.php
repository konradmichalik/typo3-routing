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

use KonradMichalik\Typo3Routing\Attribute\Authenticate;
use KonradMichalik\Typo3Routing\Authentication\RouteAuthenticatorInterface;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\{ContainerBuilder, Reference};

use function is_a;
use function sprintf;

/**
 * AuthenticateResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AuthenticateResolver
{
    public function __construct(
        private ClassExistenceChecker $classExistenceChecker = new ClassExistenceChecker(),
    ) {}

    /**
     * Reads the optional class-level #[Authenticate] list, used as the fallback for methods without
     * their own. Multiple are allowed (IS_REPEATABLE) and OR-combined, same as at method level; not
     * validated here — an unused class-level authenticator (every method overrides it) never needs to
     * resolve, same laziness as the class-level #[Cors]/#[DeprecatedRoute] fallback.
     *
     * @param ReflectionClass<object> $reflection
     *
     * @return list<Authenticate>
     */
    public function resolveClass(ReflectionClass $reflection): array
    {
        $result = [];
        foreach ($reflection->getAttributes(Authenticate::class) as $attribute) {
            $result[] = $attribute->newInstance();
        }

        return $result;
    }

    /**
     * Resolves the route's #[Authenticate] attributes (OR-combined) and registers each referenced
     * authenticator class in the locator. Fails the build on an unknown class, a class that does not
     * implement the contract, or one that is not a registered service.
     *
     * The method's own #[Authenticate] attribute(s), if any, win entirely over the class-level
     * fallback — not merged field by field, same rule as #[Cors] and #[DeprecatedRoute].
     *
     * @param list<Authenticate> $classAuth
     *
     * @return list<array{service: string, options: array<string, mixed>}>
     */
    public function resolveMethod(ReflectionMethod $method, string $serviceId, ContainerBuilder $container, CollectedRoutes $collected, array $classAuth): array
    {
        $ownAttributes = $method->getAttributes(Authenticate::class);
        $authenticates = $classAuth;
        if ([] !== $ownAttributes) {
            $authenticates = [];
            foreach ($ownAttributes as $attribute) {
                $authenticates[] = $attribute->newInstance();
            }
        }

        $result = [];
        foreach ($authenticates as $authenticate) {
            $class = $authenticate->authenticator;

            if (!$this->classExistenceChecker->exists($class) || !is_a($class, RouteAuthenticatorInterface::class, true)) {
                throw new LogicException(sprintf('#[Authenticate] on "%s::%s()" references "%s", which does not implement %s.', $serviceId, $method->getName(), $class, RouteAuthenticatorInterface::class), 1750000010);
            }

            if (!$container->hasDefinition($class) && !$container->hasAlias($class)) {
                throw new LogicException(sprintf('#[Authenticate] authenticator "%s" on "%s::%s()" is not a registered service. Register it (autoconfiguration in Services.yaml is enough).', $class, $serviceId, $method->getName()), 1750000011);
            }

            $collected->authenticatorReferences[$class] ??= new Reference($class);
            $result[] = ['service' => $class, 'options' => $authenticate->options];
        }

        return $result;
    }
}
