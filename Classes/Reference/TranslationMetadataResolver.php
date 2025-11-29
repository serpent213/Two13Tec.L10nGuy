<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Reference;

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
 * Normalizes translation coordinates (package, source, identifier).
 */
final class TranslationMetadataResolver
{
    /**
     * @return array{packageKey: string, sourceName: string, identifier: string}|null
     */
    public static function resolve(string $identifier, ?string $packageKey, ?string $sourceName): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (str_contains($identifier, ':')) {
            $parts = explode(':', $identifier, 3);
            if (count($parts) === 3) {
                if ($packageKey === null || $packageKey === '') {
                    $packageKey = $parts[0];
                }
                if ($sourceName === null || $sourceName === '') {
                    $sourceName = $parts[1];
                }
                $identifier = $parts[2];
            }
        }

        $packageKey = self::normalizePackageKey($packageKey);
        if ($packageKey === null) {
            return null;
        }

        $sourceName = self::normalizeSourceName($sourceName);

        return [
            'packageKey' => $packageKey,
            'sourceName' => $sourceName,
            'identifier' => $identifier,
        ];
    }

    private static function normalizePackageKey(?string $packageKey): ?string
    {
        $packageKey = $packageKey === null ? '' : trim($packageKey);
        return $packageKey === '' ? null : $packageKey;
    }

    private static function normalizeSourceName(?string $sourceName): string
    {
        if ($sourceName === null || $sourceName === '') {
            return 'Main';
        }

        $sourceName = explode(':', $sourceName, 2)[0];
        $sourceName = str_replace(['\\', '/'], '.', $sourceName);
        $sourceName = trim($sourceName, '.');

        return $sourceName === '' ? 'Main' : $sourceName;
    }
}
