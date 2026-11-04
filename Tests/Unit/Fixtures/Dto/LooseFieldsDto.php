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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Dto;

use Countable;
use JsonSerializable;
use Traversable;

/**
 * LooseFieldsDto.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LooseFieldsDto
{
    public int|string|null $ref = null;

    public int|string $code = 0;

    public string|false $label = false;

    public int|true $flag = true;

    public object $meta;

    public ?object $extra = null;

    /** @var iterable<mixed> */
    public iterable $items = [];

    /** @var Countable&Traversable<mixed> */
    public Countable&Traversable $both;

    /** @var (Countable&Traversable<mixed>)|null */
    public (Countable&Traversable)|null $either = null;

    public JsonSerializable $payload;

    public ?Countable $tags = null;

    /** @phpstan-ignore missingType.property */
    public $legacy;
}
