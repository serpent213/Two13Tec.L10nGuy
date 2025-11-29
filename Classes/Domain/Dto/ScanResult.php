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
 * Aggregates the outcome of a scan: missing entries, warnings and diagnostics.
 */
#[Flow\Proxy(false)]
final class ScanResult
{
    /**
     * @param list<MissingTranslation> $missingTranslations
     * @param list<PlaceholderMismatch> $placeholderMismatches
     */
    public function __construct(
        public array $missingTranslations,
        public array $placeholderMismatches,
        public ReferenceIndex $referenceIndex,
        public CatalogIndex $catalogIndex
    ) {
    }

    public function missingCount(): int
    {
        return count($this->missingTranslations);
    }

    public function hasWarnings(): bool
    {
        return $this->placeholderMismatches !== [];
    }
}
