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

namespace KonradMichalik\Typo3Routing\Attribute;

use Attribute;

/**
 * Returns.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Returns
{
    /**
     * @param class-string|null $schema      The DTO class whose public properties describe the response
     *                                       body, mapped through `JsonSchemaMapper`; null for a response
     *                                       with no body (e.g. 204, or a 404 that carries only the
     *                                       standard problem-details schema).
     * @param int               $status      the HTTP status code this response describes
     * @param bool              $collection  when true, the response body is a JSON array of `$schema`
     *                                       instead of a single instance
     * @param string|null       $description response description; a sensible default is used per
     *                                       status code when omitted
     */
    public function __construct(
        public ?string $schema = null,
        public int $status = 200,
        public bool $collection = false,
        public ?string $description = null,
    ) {}
}
