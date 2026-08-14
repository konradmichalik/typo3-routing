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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Fixtures;

use DateTimeImmutable;
use KonradMichalik\Typo3Routing\Attribute\Param;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\{Priority, Status, Suit};
use Psr\Http\Message\ServerRequestInterface;

/**
 * ArgumentSpecFixtures.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ArgumentSpecFixtures
{
    public function scalars(int $id, string $q, bool $active = false, ?ServerRequestInterface $request = null): void {}

    public function enumPath(Priority $priority): void {}

    public function enumInput(Status $status): void {}

    public function pure(Suit $suit): void {}

    public function variadicInts(int ...$ids): void {}

    public function variadicEnums(Status ...$states): void {}

    public function entityPath(Item $item): void {}

    public function variadicEntities(Item ...$items): void {}

    public function renamed(#[Param(name: 'foo')] string $bar): void {}

    public function sourced(#[Param(source: 'query')] string $q): void {}

    public function bogusSource(#[Param(source: 'cookie')] string $q): void {}

    public function variadicSourced(#[Param(source: 'query')] int ...$ids): void {}

    public function constrainedPath(#[Param(requirement: '\d+')] int $id): void {}

    public function constrainedInput(#[Param(requirement: '\d+')] int $q): void {}

    public function constrainedRenamed(#[Param(name: 'foo', requirement: '\w+')] string $bar): void {}

    public function optionalTrailingPath(#[Param(requirement: '\d+')] int $page = 1): void {}

    public function optionalInput(#[Param(requirement: '\d+')] int $page = 1): void {}

    public function unattributedDefault(int $page = 1): void {}

    public function optionalLeadingPath(#[Param] int $page = 1, ?string $slug = null): void {}

    public function presenceOnlyWithDefault(#[Param(requirement: '')] string $q = 'x'): void {}

    public function describedParam(#[Param(description: 'Page number, 1-based.')] int $page = 1): void {}

    public function describedRenamed(#[Param(name: 'q', description: 'Free-text search term.')] string $term): void {}

    public function renamedToPlaceholder(#[Param(name: 'page')] int $number): void {}

    public function renamedToPlaceholderWithDefault(#[Param(name: 'page')] int $number = 1): void {}

    public function unionType(int|string $value): void {}

    public function unsupportedObject(DateTimeImmutable $when): void {}

    public function unsupportedScalar(object $thing): void {}
}
