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
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;

/**
 * Takes Flow settings and CLI values and produces a normalized scan configuration.
 *
 * @Flow\Scope("singleton")
 */
final class ScanConfigurationFactory
{
    #[Flow\InjectConfiguration(path: 'i18n', package: 'Neos.Flow')]
    protected array $flowI18nSettings = [];

    #[Flow\InjectConfiguration(path: 'defaultFormat', package: 'Two13Tec.L10nGuy')]
    protected string $defaultFormat = 'table';

    #[Flow\InjectConfiguration(path: 'defaultLocales', package: 'Two13Tec.L10nGuy')]
    protected array $defaultLocales = [];

    #[Flow\InjectConfiguration(path: 'defaultPackages', package: 'Two13Tec.L10nGuy')]
    protected array $defaultPackages = [];

    #[Flow\InjectConfiguration(path: 'defaultPaths', package: 'Two13Tec.L10nGuy')]
    protected array $defaultPaths = [];

    /**
     * @param array<string, mixed>|null $flowI18nSettings
     * @param string|null $defaultFormat
     * @param list<string>|null $defaultLocales
     * @param list<string>|null $defaultPackages
     * @param list<string>|null $defaultPaths
     */
    public function __construct(
        ?array $flowI18nSettings = null,
        ?string $defaultFormat = null,
        ?array $defaultLocales = null,
        ?array $defaultPackages = null,
        ?array $defaultPaths = null
    ) {
        if ($flowI18nSettings !== null) {
            $this->flowI18nSettings = $flowI18nSettings;
        }
        if ($defaultFormat !== null) {
            $this->defaultFormat = $defaultFormat;
        }
        if ($defaultLocales !== null) {
            $this->defaultLocales = $this->normalizeList($defaultLocales);
        } else {
            $this->defaultLocales = $this->normalizeList($this->defaultLocales);
        }
        if ($defaultPackages !== null) {
            $this->defaultPackages = $this->normalizeList($defaultPackages);
        } else {
            $this->defaultPackages = $this->normalizeList($this->defaultPackages);
        }
        if ($defaultPaths !== null) {
            $this->defaultPaths = $this->normalizeList($defaultPaths);
        } else {
            $this->defaultPaths = $this->normalizeList($this->defaultPaths);
        }
    }

    /**
     * @param array<string, mixed> $cliOptions
     */
    public function createFromCliOptions(array $cliOptions = []): ScanConfiguration
    {
        $update = (bool)($cliOptions['update'] ?? false);
        $dryRun = $cliOptions['dryRun'] ?? null;
        $dryRun = $dryRun === null ? !$update : (bool)$dryRun;
        $ignorePlaceholderWarnings = (bool)($cliOptions['ignorePlaceholderWarnings'] ?? $cliOptions['ignorePlaceholder'] ?? false);

        $paths = $cliOptions['paths'] ?? ($cliOptions['path'] ?? []);
        $paths = $this->normalizeList($paths);
        if ($paths === [] && $this->defaultPaths !== []) {
            $paths = $this->defaultPaths;
        }

        $packageKey = $cliOptions['package'] ?? null;
        if (($packageKey === null || $packageKey === '') && $this->defaultPackages !== []) {
            $packageKey = $this->defaultPackages[0];
        }

        return new ScanConfiguration(
            $this->resolveLocales($cliOptions['locales'] ?? null),
            $packageKey !== '' ? $packageKey : null,
            $cliOptions['source'] ?? null,
            $paths,
            $this->resolveFormat($cliOptions['format'] ?? null),
            $dryRun,
            $update,
            $ignorePlaceholderWarnings,
            [
                'cli' => $cliOptions,
            ]
        );
    }

    private function resolveFormat(?string $format): string
    {
        $format = $format ?: $this->defaultFormat;
        return strtolower($format);
    }

    private function resolveLocales(mixed $override): array
    {
        if ($override !== null && $override !== '') {
            return $this->normalizeList($override);
        }

        if ($this->defaultLocales !== []) {
            return $this->defaultLocales;
        }

        $locales = [];
        $defaultLocale = $this->flowI18nSettings['defaultLocale'] ?? null;
        if ($defaultLocale !== null && $defaultLocale !== '') {
            $locales[] = (string)$defaultLocale;
        }

        $fallbackOrder = $this->flowI18nSettings['fallbackRule']['order'] ?? [];
        foreach ((array)$fallbackOrder as $fallback) {
            $locales[] = (string)$fallback;
        }

        return array_values(array_unique(array_filter($locales)));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('#[\s,]+#', trim($value)) ?: [];
        }

        if (!is_array($value)) {
            return [(string)$value];
        }

        $filtered = array_filter(array_map(static fn ($item) => trim((string)$item), $value));

        return array_values(array_unique($filtered));
    }
}
