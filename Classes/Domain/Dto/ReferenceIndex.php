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
 * Holds the deduplicated reference map plus duplicate diagnostics.
 */
#[Flow\Proxy(false)]
final class ReferenceIndex
{
    /**
     * @var array<string, array<string, array<string, TranslationReference>>>
     */
    private array $references = [];

    /**
     * @var array<string, array<string, array<string, list<TranslationReference>>>>
     */
    private array $duplicates = [];

    private int $total = 0;

    public function add(TranslationReference $reference): void
    {
        $this->total++;
        $packageKey = $reference->packageKey;
        $sourceName = $reference->sourceName;
        $identifier = $reference->identifier;

        if (!isset($this->references[$packageKey][$sourceName][$identifier])) {
            $this->references[$packageKey][$sourceName][$identifier] = $reference;
            return;
        }

        $this->duplicates[$packageKey][$sourceName][$identifier][] = $reference;
    }

    /**
     * @return array<string, array<string, array<string, TranslationReference>>>
     */
    public function references(): array
    {
        return $this->references;
    }

    /**
     * @return array<string, array<string, array<string, list<TranslationReference>>>>
     */
    public function duplicates(): array
    {
        return $this->duplicates;
    }

    /**
     * @return list<TranslationReference>
     */
    public function allFor(string $packageKey, string $sourceName, string $identifier): array
    {
        $list = [];
        if (isset($this->references[$packageKey][$sourceName][$identifier])) {
            $list[] = $this->references[$packageKey][$sourceName][$identifier];
        }
        if (isset($this->duplicates[$packageKey][$sourceName][$identifier])) {
            array_push($list, ...$this->duplicates[$packageKey][$sourceName][$identifier]);
        }
        return $list;
    }

    public function uniqueCount(): int
    {
        $count = 0;
        foreach ($this->references as $sources) {
            foreach ($sources as $identifiers) {
                $count += count($identifiers);
            }
        }
        return $count;
    }

    public function duplicateCount(): int
    {
        $count = 0;
        foreach ($this->duplicates as $sources) {
            foreach ($sources as $identifiers) {
                foreach ($identifiers as $list) {
                    $count += count($list);
                }
            }
        }

        return $count;
    }

    public function totalCount(): int
    {
        return $this->total;
    }
}
