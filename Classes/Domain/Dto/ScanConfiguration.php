<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Domain\Dto;

use Neos\Flow\Annotations as Flow;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

/**
 * Value object describing the normalized scan configuration derived from settings and CLI options.
 */
#[Flow\Proxy(false)]
final readonly class ScanConfiguration
{
    /**
     * @param list<string> $locales
     * @param list<string> $paths
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public array $locales,
        public ?string $packageKey,
        public ?string $sourceName,
        public array $paths,
        public string $format,
        public bool $update,
        public bool $ignorePlaceholderWarnings = false,
        public array $meta = [],
    ) {
    }
}
