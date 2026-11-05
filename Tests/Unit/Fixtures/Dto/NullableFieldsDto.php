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

use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\Status;

/**
 * NullableFieldsDto.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class NullableFieldsDto
{
    public string $name = '';

    public ?string $title = null;

    public ?Status $status = null;

    public ?InstructorDto $instructor = null;

    /** @var array<mixed>|null */
    public ?array $tags = null;

    public mixed $anything = null;

    /** @phpstan-ignore missingType.property */
    public $legacy;
}
