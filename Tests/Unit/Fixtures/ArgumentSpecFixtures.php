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

    public function renamed(#[Param(name: 'foo')] string $bar): void {}

    public function sourced(#[Param(source: 'query')] string $q): void {}

    public function bogusSource(#[Param(source: 'cookie')] string $q): void {}

    public function variadicSourced(#[Param(source: 'query')] int ...$ids): void {}

    public function unionType(int|string $value): void {}

    public function unsupportedObject(DateTimeImmutable $when): void {}

    public function unsupportedScalar(object $thing): void {}
}
