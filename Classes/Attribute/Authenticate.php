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
 * Authenticate.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Authenticate
{
    /**
     * @param class-string         $authenticator FQCN of a registered RouteAuthenticatorInterface service
     *                                            (its contract is verified at compile time by RouteCompilerPass)
     * @param array<string, mixed> $options       passed verbatim to authenticate()
     */
    public function __construct(
        public string $authenticator,
        public array $options = [],
    ) {}
}
