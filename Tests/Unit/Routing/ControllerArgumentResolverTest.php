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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Routing;

use KonradMichalik\Typo3Routing\Routing\{ArgumentResolutionException, ControllerArgumentResolver};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\{Priority, Status};
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{ServerRequest, Stream};

/**
 * ControllerArgumentResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(ControllerArgumentResolver::class)]
final class ControllerArgumentResolverTest extends TestCase
{
    private ControllerArgumentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ControllerArgumentResolver();
    }

    #[Test]
    public function injectsTheRequestForARequestSourcedSpec(): void
    {
        $request = $this->request();
        $specs = [$this->spec('request', 'request', null)];

        $arguments = $this->resolver->resolve($specs, ['_route' => 'x'], $request);

        self::assertSame([$request], $arguments);
    }

    #[Test]
    public function castsPathPlaceholderToDeclaredType(): void
    {
        $specs = [$this->spec('id', 'path', 'int')];

        $arguments = $this->resolver->resolve($specs, ['id' => '42'], $this->request());

        self::assertSame([42], $arguments);
    }

    #[Test]
    public function pullsAndCastsInputFromQuery(): void
    {
        $specs = [$this->spec('q', 'input', 'int')];
        $request = $this->request()->withQueryParams(['q' => '7']);

        self::assertSame([7], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function prefersParsedBodyAlongsideQuery(): void
    {
        $specs = [$this->spec('n', 'input', 'int')];
        $request = $this->request('POST')->withParsedBody(['n' => '9']);

        self::assertSame([9], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function resolvesBodyParamFromJsonPayload(): void
    {
        $specs = [$this->spec('n', 'body', 'int')];
        $request = $this->jsonRequest('PUT', '{"n":9}');

        self::assertSame([9], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function appliesDefaultWhenInputIsAbsent(): void
    {
        $specs = [$this->spec('to', 'input', 'int', hasDefault: true, default: 10)];

        self::assertSame([10], $this->resolver->resolve($specs, [], $this->request()));
    }

    #[Test]
    public function yieldsNullWhenNullableInputIsAbsent(): void
    {
        $specs = [$this->spec('label', 'input', 'string', nullable: true)];

        self::assertSame([null], $this->resolver->resolve($specs, [], $this->request()));
    }

    #[Test]
    public function throwsWhenRequiredInputIsAbsent(): void
    {
        $specs = [$this->spec('from', 'input', 'int')];

        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Missing required parameter: from');

        $this->resolver->resolve($specs, [], $this->request());
    }

    #[Test]
    #[DataProvider('coercibleValues')]
    public function coercesScalarValues(string $type, string $raw, mixed $expected): void
    {
        $specs = [$this->spec('v', 'input', $type)];
        $request = $this->request()->withQueryParams(['v' => $raw]);

        self::assertSame([$expected], $this->resolver->resolve($specs, [], $request));
    }

    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function coercibleValues(): iterable
    {
        yield 'int' => ['int', '-12', -12];
        yield 'float' => ['float', '1.5', 1.5];
        yield 'bool true' => ['bool', 'true', true];
        yield 'bool on' => ['bool', 'on', true];
        yield 'bool zero' => ['bool', '0', false];
        yield 'string' => ['string', 'hello', 'hello'];
    }

    #[Test]
    #[DataProvider('uncoercibleValues')]
    public function rejectsValuesThatDoNotMatchTheType(string $type, string $raw): void
    {
        $specs = [$this->spec('v', 'input', $type)];
        $request = $this->request()->withQueryParams(['v' => $raw]);

        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Invalid value for parameter: v');

        $this->resolver->resolve($specs, [], $request);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uncoercibleValues(): iterable
    {
        yield 'int from word' => ['int', 'abc'];
        yield 'int from float' => ['int', '1.5'];
        yield 'float from word' => ['float', 'abc'];
        yield 'bool from word' => ['bool', 'maybe'];
    }

    #[Test]
    public function resolvesIntBackedEnumByBackingValue(): void
    {
        $specs = [$this->spec('priority', 'input', Priority::class)];
        $request = $this->request()->withQueryParams(['priority' => '5']);

        self::assertSame([Priority::High], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function resolvesStringBackedEnumFromPath(): void
    {
        $specs = [$this->spec('status', 'path', Status::class)];

        self::assertSame([Status::Active], $this->resolver->resolve($specs, ['status' => 'active'], $this->request()));
    }

    #[Test]
    public function rejectsUnknownEnumValue(): void
    {
        $specs = [$this->spec('priority', 'input', Priority::class)];
        $request = $this->request()->withQueryParams(['priority' => '99']);

        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Invalid value for parameter: priority');

        $this->resolver->resolve($specs, [], $request);
    }

    #[Test]
    public function rejectsNonScalarValueForEnum(): void
    {
        $specs = [$this->spec('priority', 'input', Priority::class)];
        $request = $this->request()->withQueryParams(['priority' => ['nested']]);

        $this->expectException(ArgumentResolutionException::class);

        $this->resolver->resolve($specs, [], $request);
    }

    #[Test]
    public function spreadsVariadicInputArrayWithCoercion(): void
    {
        $specs = [$this->spec('ids', 'variadic', 'int')];
        $request = $this->request()->withQueryParams(['ids' => ['1', '2', '3']]);

        self::assertSame([1, 2, 3], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function wrapsSingleVariadicScalarValue(): void
    {
        $specs = [$this->spec('ids', 'variadic', 'int')];
        $request = $this->request()->withQueryParams(['ids' => '7']);

        self::assertSame([7], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function yieldsNoArgumentsForAbsentVariadic(): void
    {
        $specs = [$this->spec('ids', 'variadic', 'int')];

        self::assertSame([], $this->resolver->resolve($specs, [], $this->request()));
    }

    #[Test]
    public function readsFromQuerySourceIgnoringBody(): void
    {
        $specs = [$this->spec('v', 'query', 'int')];
        $request = $this->request('POST')->withQueryParams(['v' => '1'])->withParsedBody(['v' => '2']);

        self::assertSame([1], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function readsFromBodySourceIgnoringQuery(): void
    {
        $specs = [$this->spec('v', 'body', 'int')];
        $request = $this->request('POST')->withQueryParams(['v' => '1'])->withParsedBody(['v' => '2']);

        self::assertSame([2], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function appliesDefaultWhenPathPlaceholderIsAbsent(): void
    {
        $specs = [$this->spec('id', 'path', 'int', hasDefault: true, default: 99)];

        self::assertSame([99], $this->resolver->resolve($specs, [], $this->request()));
    }

    #[Test]
    public function yieldsNullWhenNullablePathPlaceholderIsAbsent(): void
    {
        $specs = [$this->spec('id', 'path', 'int', nullable: true)];

        self::assertSame([null], $this->resolver->resolve($specs, [], $this->request()));
    }

    #[Test]
    public function throwsWhenRequiredPathPlaceholderIsAbsent(): void
    {
        $specs = [$this->spec('id', 'path', 'int')];

        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Missing required parameter: id');

        $this->resolver->resolve($specs, [], $this->request());
    }

    #[Test]
    public function keepsAlreadyTypedScalarValuesFromTheBody(): void
    {
        // POST bodies can carry real int/bool/float values, exercising the no-cast fast paths.
        $specs = [
            $this->spec('i', 'input', 'int'),
            $this->spec('b', 'input', 'bool'),
            $this->spec('f', 'input', 'float'),
            $this->spec('s', 'input', 'string'),
        ];
        $request = $this->request('POST')->withParsedBody(['i' => 7, 'b' => true, 'f' => 1.5, 's' => 9]);

        self::assertSame([7, true, 1.5, '9'], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function passesArrayValuesThroughForArrayType(): void
    {
        $specs = [$this->spec('tags', 'input', 'array')];
        $request = $this->request()->withQueryParams(['tags' => ['a', 'b']]);

        self::assertSame([['a', 'b']], $this->resolver->resolve($specs, [], $request));
    }

    #[Test]
    public function rejectsNonArrayValueForArrayType(): void
    {
        $specs = [$this->spec('tags', 'input', 'array')];
        $request = $this->request()->withQueryParams(['tags' => 'nope']);

        $this->expectException(ArgumentResolutionException::class);

        $this->resolver->resolve($specs, [], $request);
    }

    #[Test]
    public function rejectsNonScalarValueForStringType(): void
    {
        $specs = [$this->spec('s', 'input', 'string')];
        $request = $this->request()->withQueryParams(['s' => ['array']]);

        $this->expectException(ArgumentResolutionException::class);

        $this->resolver->resolve($specs, [], $request);
    }

    #[Test]
    public function passesUntypedValueThroughUnchanged(): void
    {
        $specs = [$this->spec('raw', 'input', null)];
        $request = $this->request()->withQueryParams(['raw' => 'as-is']);

        self::assertSame(['as-is'], $this->resolver->resolve($specs, [], $request));
    }

    /**
     * @return array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}
     */
    private function spec(string $name, string $source, ?string $type, bool $nullable = false, bool $hasDefault = false, mixed $default = null): array
    {
        return ['name' => $name, 'type' => $type, 'source' => $source, 'nullable' => $nullable, 'hasDefault' => $hasDefault, 'default' => $default];
    }

    private function request(string $method = 'GET'): ServerRequest
    {
        return new ServerRequest('https://example.com/', $method);
    }

    private function jsonRequest(string $method, string $body): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return (new ServerRequest('https://example.com/', $method))
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }
}
