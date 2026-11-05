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

use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Typo3Routing\Http\HttpProblemException;
use KonradMichalik\Typo3Routing\Routing\{ArgumentResolutionException, ControllerArgumentResolver, EntityNotFoundException};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\{Priority, Status};
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Http\{ServerRequest, Stream, UploadedFile};
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

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
    private PersistenceManagerInterface&MockObject $persistenceManager;

    protected function setUp(): void
    {
        $this->persistenceManager = $this->createMock(PersistenceManagerInterface::class);
        $this->resolver = new ControllerArgumentResolver($this->persistenceManager);
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
    public function readsAHostPlaceholderFromTheMatchNotFromTheQuery(): void
    {
        $specs = [$this->spec('subdomain', 'host', 'string')];
        $request = $this->request()->withQueryParams(['subdomain' => 'from-query']);

        self::assertSame(['acme'], $this->resolver->resolve($specs, ['subdomain' => 'acme'], $request));
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
    public function resolvesExtbaseDomainObjectByIdentifier(): void
    {
        $item = new Item();
        $this->persistenceManager->method('getObjectByIdentifier')->with('42', Item::class)->willReturn($item);

        $specs = [$this->spec('item', 'path', Item::class)];

        self::assertSame([$item], $this->resolver->resolve($specs, ['item' => '42'], $this->request()));
    }

    #[Test]
    public function throwsEntityNotFoundWhenNoRecordMatchesTheIdentifier(): void
    {
        $this->persistenceManager->method('getObjectByIdentifier')->willReturn(null);

        $specs = [$this->spec('item', 'path', Item::class)];

        $this->expectException(EntityNotFoundException::class);

        $this->resolver->resolve($specs, ['item' => '42'], $this->request());
    }

    #[Test]
    public function rejectsMalformedEntityIdentifier(): void
    {
        $specs = [$this->spec('item', 'path', Item::class)];

        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Invalid value for parameter: item');

        $this->resolver->resolve($specs, ['item' => 'abc'], $this->request());
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

    #[Test]
    public function rejectsAnInputOutsideItsRangeNamingTheWireParameter(): void
    {
        $specs = [[...$this->spec('limit', 'input', 'int'), 'min' => 1, 'max' => 100]];
        $request = $this->request()->withQueryParams(['limit' => '101']);

        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Invalid value for parameter: limit');

        $this->resolver->resolve($specs, [], $request);
    }

    #[Test]
    public function rejectsAnInputThatOverflowsToInfinityPastItsMinimum(): void
    {
        $specs = [[...$this->spec('ratio', 'input', 'float'), 'min' => 0]];
        $request = $this->request()->withQueryParams(['ratio' => '1e999']);

        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Invalid value for parameter: ratio');

        $this->resolver->resolve($specs, [], $request);
    }

    #[Test]
    public function rejectsAVariadicElementOutsideItsRange(): void
    {
        $specs = [[...$this->spec('ids', 'variadic', 'int'), 'min' => 1]];
        $request = $this->request()->withQueryParams(['ids' => ['3', '0']]);

        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Invalid value for parameter: ids');

        $this->resolver->resolve($specs, [], $request);
    }

    #[Test]
    public function doesNotCheckAFallbackDefaultAgainstTheConstraints(): void
    {
        $specs = [[...$this->spec('limit', 'input', 'int', hasDefault: true, default: 0), 'min' => 1]];

        self::assertSame([0], $this->resolver->resolve($specs, [], $this->request()));
    }

    #[Test]
    public function resolvesAnUploadedFileByItsWireName(): void
    {
        $file = $this->uploadedFile();
        $request = $this->request('POST')->withUploadedFiles(['file' => $file]);

        self::assertSame([$file], $this->resolver->resolve([$this->uploadSpec('file')], [], $request));
    }

    #[Test]
    public function yieldsNullForAnAbsentOptionalUpload(): void
    {
        self::assertSame([null], $this->resolver->resolve([$this->uploadSpec('file', optional: true)], [], $this->request('POST')));
    }

    #[Test]
    public function answers413WhenPhpDiscardedABodyOverPostMaxSize(): void
    {
        // PHP drops files and fields alike, so the optional upload would otherwise just be null.
        $request = $this->request('POST')->withHeader('Content-Length', (string) \PHP_INT_MAX);

        $this->expectException(HttpProblemException::class);
        $this->expectExceptionMessage('Request body exceeds the server\'s post_max_size');

        $this->resolver->resolve([$this->uploadSpec('file', optional: true)], [], $request);
    }

    #[Test]
    public function throwsWhenARequiredUploadIsAbsent(): void
    {
        // A same-named body field is not a file, so it must not satisfy the parameter.
        $request = $this->request('POST')->withParsedBody(['file' => 'not-a-file']);

        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Missing required parameter: file');

        $this->resolver->resolve([$this->uploadSpec('file')], [], $request);
    }

    /**
     * @return array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}
     */
    private function spec(string $name, string $source, ?string $type, bool $nullable = false, bool $hasDefault = false, mixed $default = null): array
    {
        return ['name' => $name, 'type' => $type, 'source' => $source, 'nullable' => $nullable, 'hasDefault' => $hasDefault, 'default' => $default];
    }

    /**
     * @return array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}
     */
    private function uploadSpec(string $name, bool $optional = false): array
    {
        return $this->spec($name, 'upload', UploadedFileInterface::class, nullable: $optional, hasDefault: $optional);
    }

    private function uploadedFile(): UploadedFile
    {
        return new UploadedFile(new Stream('php://temp', 'wb+'), 0, \UPLOAD_ERR_OK, 'a.txt', 'text/plain');
    }

    private function request(string $method = 'GET'): ServerRequest
    {
        return (new RequestBuilder($method, 'https://example.com/'))->build();
    }

    private function jsonRequest(string $method, string $body): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return (new RequestBuilder($method, 'https://example.com/'))
            ->build()
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }
}
