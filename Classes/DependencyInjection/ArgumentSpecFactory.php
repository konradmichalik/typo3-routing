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

use KonradMichalik\Typo3Routing\Attribute\Param;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function enum_exists;
use function in_array;
use function is_a;
use function preg_match_all;
use function sprintf;

/**
 * ArgumentSpecFactory.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ArgumentSpecFactory
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_SCALAR_TYPES = ['int', 'float', 'string', 'bool', 'array', 'mixed'];

    /**
     * @var list<string>
     */
    private const OVERRIDABLE_SOURCES = ['path', 'query', 'body', 'input'];

    /**
     * @return list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>
     */
    public function build(ReflectionMethod $method, string $path, string $serviceId): array
    {
        preg_match_all('/\{(\w+)\}/', $path, $matches);
        $placeholders = $matches[1];

        $where = sprintf('%s::%s()', $serviceId, $method->getName());

        $specs = [];
        foreach ($method->getParameters() as $parameter) {
            $specs[] = $this->resolveArgument($parameter, $placeholders, $where);
        }

        return $specs;
    }

    /**
     * @param list<string> $placeholders
     *
     * @return array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}
     */
    private function resolveArgument(ReflectionParameter $parameter, array $placeholders, string $where): array
    {
        $type = $parameter->getType();
        if (null !== $type && !$type instanceof ReflectionNamedType) {
            throw new LogicException(sprintf('Union/intersection type on parameter "$%s" of "%s" is not supported by route argument resolution.', $parameter->getName(), $where), 1750000003);
        }

        $name = $parameter->getName();

        // A variadic collects zero or more values from a single input array — it never injects the request.
        if ($parameter->isVariadic()) {
            $spec = ['name' => $name, 'type' => $this->resolveValueType($type, $name, $where), 'source' => 'variadic', 'nullable' => false, 'hasDefault' => false, 'default' => null];

            return $this->applyParamOverride($spec, $parameter, $where);
        }

        $hasDefault = $parameter->isDefaultValueAvailable();
        $sourceAndType = $this->resolveSourceAndType($type, $name, $placeholders, $where);

        $spec = [
            'name' => $name,
            'type' => $sourceAndType['type'],
            'source' => $sourceAndType['source'],
            'nullable' => $type?->allowsNull() ?? true,
            'hasDefault' => $hasDefault,
            'default' => $hasDefault ? $parameter->getDefaultValue() : null,
        ];

        return $this->applyParamOverride($spec, $parameter, $where);
    }

    /**
     * Decides where a parameter's value comes from and which type to coerce it to: the PSR-7 request
     * for a request interface type-hint, a path placeholder when the name is in the route path,
     * otherwise a query/body input.
     *
     * @param list<string> $placeholders
     *
     * @return array{source: string, type: string|null}
     */
    private function resolveSourceAndType(?ReflectionNamedType $type, string $name, array $placeholders, string $where): array
    {
        if (null !== $type && !$type->isBuiltin() && is_a(ServerRequestInterface::class, $type->getName(), true)) {
            return ['source' => 'request', 'type' => null];
        }

        return ['source' => in_array($name, $placeholders, true) ? 'path' : 'input', 'type' => $this->resolveValueType($type, $name, $where)];
    }

    /**
     * Resolves the coercion target for a value parameter: a scalar keyword, a backed enum's class
     * name, or null (untyped). Rejects the request, pure enums and other objects.
     */
    private function resolveValueType(?ReflectionNamedType $type, string $name, string $where): ?string
    {
        if (null === $type || $type->isBuiltin()) {
            $scalar = $type?->getName();
            if (null !== $scalar && !in_array($scalar, self::SUPPORTED_SCALAR_TYPES, true)) {
                throw new LogicException(sprintf('Unsupported parameter type "%s" on "$%s" of "%s". Supported: "%s".', $scalar, $name, $where, implode('", "', self::SUPPORTED_SCALAR_TYPES)), 1750000005);
            }

            return $scalar;
        }

        $class = $type->getName();
        if (enum_exists($class)) {
            if (!(new ReflectionEnum($class))->isBacked()) {
                throw new LogicException(sprintf('Pure enum "%s" on parameter "$%s" of "%s" cannot be resolved from a request value; use a backed enum.', $class, $name, $where), 1750000006);
            }

            return $class;
        }

        throw new LogicException(sprintf('Unsupported object type "%s" on parameter "$%s" of "%s". Only scalars, backed enums and the PSR-7 request are resolvable.', $class, $name, $where), 1750000004);
    }

    /**
     * Applies an optional #[Param] override (lookup key and/or source) on top of the auto-derived spec.
     *
     * @param array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed} $spec
     *
     * @return array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}
     */
    private function applyParamOverride(array $spec, ReflectionParameter $parameter, string $where): array
    {
        $attributes = $parameter->getAttributes(Param::class);
        if ([] === $attributes) {
            return $spec;
        }

        $param = $attributes[0]->newInstance();

        if (null !== $param->name) {
            $spec['name'] = $param->name;
        }

        if (null !== $param->source) {
            $spec['source'] = $this->overrideSource($spec['source'], $param->source, $parameter->getName(), $where);
        }

        return $spec;
    }

    private function overrideSource(string $current, string $requested, string $parameterName, string $where): string
    {
        if (!in_array($requested, self::OVERRIDABLE_SOURCES, true)) {
            throw new LogicException(sprintf('Unsupported #[Param] source "%s" on "$%s" of "%s". Supported: "%s".', $requested, $parameterName, $where, implode('", "', self::OVERRIDABLE_SOURCES)), 1750000007);
        }

        if ('variadic' === $current) {
            throw new LogicException(sprintf('#[Param] source cannot be set on the variadic parameter "$%s" of "%s".', $parameterName, $where), 1750000008);
        }

        return $requested;
    }
}
