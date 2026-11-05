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
use Psr\Http\Message\UploadedFileInterface;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function array_key_exists;
use function sprintf;
use function str_contains;

/**
 * UploadArgumentGuard.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UploadArgumentGuard
{
    /**
     * Only the PSR-7 interface itself: a concrete implementation as type-hint would turn a file from
     * another PSR-7 implementation into a TypeError instead of a resolvable argument.
     *
     * @phpstan-assert-if-true ReflectionNamedType $type
     */
    public static function isUploadedFile(?ReflectionNamedType $type): bool
    {
        return UploadedFileInterface::class === $type?->getName();
    }

    public static function assertNotVariadic(?ReflectionNamedType $type, string $parameterName, string $where): void
    {
        if (self::isUploadedFile($type)) {
            throw new LogicException(sprintf('Variadic uploaded file parameter "$%s" of "%s" is not supported by route argument resolution. Declare one UploadedFileInterface parameter per file, or inject the request and read getUploadedFiles().', $parameterName, $where), 1750000041);
        }
    }

    /**
     * Runs once the route's merged requirements are known, so a requirement from #[Route] and one from
     * #[Param] are caught alike. Specs are paired with the parameters by position, as build() makes them.
     *
     * @param list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}> $specs
     * @param array<string, string>                                                                                          $requirements
     */
    public static function assertValid(ReflectionMethod $method, array $specs, array $requirements, string $path, ?string $host, string $serviceId): void
    {
        $where = sprintf('%s::%s()', $serviceId, $method->getName());

        foreach ($method->getParameters() as $position => $parameter) {
            $type = $parameter->getType();
            if (!self::isUploadedFile($type instanceof ReflectionNamedType ? $type : null)) {
                continue;
            }

            self::assertValidUpload($parameter, $specs[$position]['name'], $requirements, $path, $host ?? '', $where);
        }
    }

    /**
     * @param array<string, string> $requirements
     */
    private static function assertValidUpload(ReflectionParameter $parameter, string $key, array $requirements, string $path, string $host, string $where): void
    {
        $name = $parameter->getName();

        foreach ($parameter->getAttributes(Param::class) as $attribute) {
            $source = $attribute->newInstance()->source;
            if (null !== $source) {
                throw new LogicException(sprintf('#[Param(source: \'%s\')] on the uploaded file "$%s" of "%s" is not supported: a file always comes from the multipart request body. Drop the source.', $source, $name, $where), 1750000040);
            }
        }

        if (str_contains($path, '{'.$key.'}') || str_contains($host, '{'.$key.'}')) {
            throw new LogicException(sprintf('Uploaded file parameter "$%s" of "%s" cannot bind to the placeholder "{%s}": a file only arrives in a multipart request body.', $name, $where, $key), 1750000042);
        }

        // Requirements validate query and body values, which never hold a file, so the route could only answer 400.
        if (array_key_exists($key, $requirements)) {
            throw new LogicException(sprintf('A requirement for "%s" on the uploaded file "$%s" of "%s" is not supported, neither on #[Route] nor via #[Param]: requirements validate query and body values, not files. Validate the file in the controller.', $key, $name, $where), 1750000043);
        }
    }
}
