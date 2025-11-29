<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Service;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;

/**
 * Builds catalog mutations from scan results.
 */
#[Flow\Scope("singleton")]
final class CatalogMutationFactory
{
    /**
     * @return list<CatalogMutation>
     */
    public function fromScanResult(ScanResult $scanResult): array
    {
        $mutations = [];
        foreach ($scanResult->missingTranslations as $missing) {
            $identifier = $missing->key->identifier;
            $fallback = $missing->reference->fallback;
            if ($fallback === null || $fallback === '') {
                $fallback = $this->fallbackWithPlaceholderHints($identifier, $missing->reference->placeholders);
            }

            $mutations[] = new CatalogMutation(
                locale: $missing->locale,
                packageKey: $missing->key->packageKey,
                sourceName: $missing->key->sourceName,
                identifier: $identifier,
                fallback: $fallback,
                placeholders: $missing->reference->placeholders
            );
        }

        return $mutations;
    }

    /**
     * @param array<string, string> $placeholderMap
     */
    private function fallbackWithPlaceholderHints(string $identifier, array $placeholderMap): string
    {
        if ($placeholderMap === []) {
            return $identifier;
        }

        $placeholders = array_map(
            static fn (string $name): string => sprintf('{%s}', $name),
            array_keys($placeholderMap)
        );

        return trim($identifier . ' ' . implode(' ', $placeholders));
    }
}
