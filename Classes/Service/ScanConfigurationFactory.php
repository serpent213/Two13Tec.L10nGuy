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

    #[Flow\InjectConfiguration(path: 'i18n.helper', package: 'Neos.Flow')]
    protected array $helperSettings = [];

    /**
     * @param array<string, mixed>|null $flowI18nSettings
     * @param array<string, mixed>|null $helperSettings
     */
    public function __construct(?array $flowI18nSettings = null, ?array $helperSettings = null)
    {
        if ($flowI18nSettings !== null) {
            $this->flowI18nSettings = $flowI18nSettings;
        }
        if ($helperSettings !== null) {
            $this->helperSettings = $helperSettings;
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

        $paths = $cliOptions['paths'] ?? ($cliOptions['path'] ?? []);
        $paths = $this->normalizeList($paths);

        return new ScanConfiguration(
            $this->resolveLocales($cliOptions['locales'] ?? null),
            $cliOptions['package'] ?? null,
            $cliOptions['source'] ?? null,
            $paths,
            $this->resolveFormat($cliOptions['format'] ?? null),
            $dryRun,
            $update,
            [
                'cli' => $cliOptions,
            ]
        );
    }

    private function resolveFormat(?string $format): string
    {
        $format = $format ?: ($this->helperSettings['defaultFormat'] ?? 'table');
        return strtolower($format);
    }

    private function resolveLocales(mixed $override): array
    {
        if ($override !== null && $override !== '') {
            return $this->normalizeList($override);
        }

        $locales = [];
        if (!empty($this->flowI18nSettings['defaultLocale'])) {
            $locales[] = (string)$this->flowI18nSettings['defaultLocale'];
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
