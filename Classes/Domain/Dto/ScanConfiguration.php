<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Domain\Dto;

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
        public bool $dryRun,
        public bool $update,
        public array $meta = [],
    ) {
    }

    public function withDryRun(bool $dryRun): self
    {
        return new self(
            locales: $this->locales,
            packageKey: $this->packageKey,
            sourceName: $this->sourceName,
            paths: $this->paths,
            format: $this->format,
            dryRun: $dryRun,
            update: $this->update,
            meta: $this->meta,
        );
    }
}
