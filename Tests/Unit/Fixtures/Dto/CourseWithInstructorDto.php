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
 * CourseWithInstructorDto.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CourseWithInstructorDto
{
    public function __construct(
        public int $id,
        public InstructorDto $instructor,
        public Status $status,
    ) {}
}
