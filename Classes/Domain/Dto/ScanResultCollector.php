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
 * Collects missing translations and placeholder warnings during a scan.
 */
#[Flow\Proxy(false)]
final class ScanResultCollector
{
    /**
     * @var list<MissingTranslation>
     */
    private array $missing = [];

    /**
     * @var list<PlaceholderMismatch>
     */
    private array $placeholderMismatches = [];

    public function addMissing(MissingTranslation $missing): void
    {
        $this->missing[] = $missing;
    }

    public function addPlaceholderMismatch(PlaceholderMismatch $mismatch): void
    {
        $this->placeholderMismatches[] = $mismatch;
    }

    public function build(ReferenceIndex $referenceIndex, CatalogIndex $catalogIndex): ScanResult
    {
        return new ScanResult(
            missingTranslations: $this->missing,
            placeholderMismatches: $this->placeholderMismatches,
            referenceIndex: $referenceIndex,
            catalogIndex: $catalogIndex
        );
    }
}
