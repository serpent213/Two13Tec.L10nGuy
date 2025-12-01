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
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Llm\Exception\LlmConfigurationException;

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

    #[Flow\InjectConfiguration(path: 'newState', package: 'Two13Tec.L10nGuy')]
    protected ?string $defaultNewState = null;

    #[Flow\InjectConfiguration(path: 'newStateQualifier', package: 'Two13Tec.L10nGuy')]
    protected ?string $defaultNewStateQualifier = null;

    #[Flow\InjectConfiguration(path: 'llm', package: 'Two13Tec.L10nGuy')]
    protected array $llmSettings = [];

    /**
     * @param array<string, mixed>|null $flowI18nSettings
     * @param string|null $defaultFormat
     * @param list<string>|null $defaultLocales
     * @param list<string>|null $defaultPackages
     * @param list<string>|null $defaultPaths
     * @param string|null $defaultNewState
     * @param string|null $defaultNewStateQualifier
     * @param array<string, mixed>|null $llmSettings
     */
    public function __construct(
        ?array $flowI18nSettings = null,
        ?string $defaultFormat = null,
        ?array $defaultLocales = null,
        ?array $defaultPackages = null,
        ?array $defaultPaths = null,
        ?string $defaultNewState = null,
        ?string $defaultNewStateQualifier = null,
        ?array $llmSettings = null
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
        if ($defaultNewState !== null) {
            $this->defaultNewState = $defaultNewState;
        }
        if ($defaultNewStateQualifier !== null) {
            $this->defaultNewStateQualifier = $defaultNewStateQualifier;
        }
        if ($llmSettings !== null) {
            $this->llmSettings = $llmSettings;
        }
    }

    /**
     * @param array<string, mixed> $cliOptions
     */
    public function createFromCliOptions(array $cliOptions = []): ScanConfiguration
    {
        $update = (bool)($cliOptions['update'] ?? false);
        $ignorePlaceholderWarnings = (bool)($cliOptions['ignorePlaceholderWarnings'] ?? $cliOptions['ignorePlaceholder'] ?? false);
        $newState = $this->normalizeState($this->defaultNewState);
        $newStateQualifier = $this->normalizeState($this->defaultNewStateQualifier);
        $quiet = (bool)($cliOptions['quiet'] ?? false);
        $quieter = (bool)($cliOptions['quieter'] ?? false);

        $paths = $cliOptions['paths'] ?? ($cliOptions['path'] ?? []);
        $paths = $this->normalizeList($paths);
        if ($paths === [] && $this->defaultPaths !== []) {
            $paths = $this->defaultPaths;
        }

        $packageKey = $cliOptions['package'] ?? null;
        if (($packageKey === null || $packageKey === '') && $this->defaultPackages !== []) {
            $packageKey = $this->defaultPackages[0];
        }

        $idPattern = $cliOptions['id'] ?? null;
        if ($idPattern === '') {
            $idPattern = null;
        }

        return new ScanConfiguration(
            $this->resolveLocales($cliOptions['locales'] ?? null),
            $packageKey !== '' ? $packageKey : null,
            $cliOptions['source'] ?? null,
            $idPattern,
            $paths,
            $this->resolveFormat($cliOptions['format'] ?? null),
            $update,
            $newState,
            $newStateQualifier,
            $ignorePlaceholderWarnings,
            [
                'cli' => $cliOptions,
            ],
            $quiet || $quieter,
            $quieter,
            $this->createLlmConfiguration($cliOptions)
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

    private function resolveBooleanOption(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $normalized ?? $default;
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

    private function normalizeState(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $state = trim((string)$value);

        return $state === '' ? null : $state;
    }

    /**
     * @param array<string, mixed> $cliOptions
     */
    private function createLlmConfiguration(array $cliOptions): ?LlmConfiguration
    {
        $enabled = (bool)($cliOptions['llm'] ?? false);
        if (!$enabled) {
            return null;
        }

        $settings = $this->llmSettings;
        $batchSize = (int)($settings['batchSize'] ?? LlmConfiguration::DEFAULT_BATCH_SIZE);
        $maxCrossReferenceLocales = (int)($settings['maxCrossReferenceLocales'] ?? LlmConfiguration::DEFAULT_MAX_CROSS_REFERENCE_LOCALES);
        $contextWindowLines = (int)($settings['contextWindowLines'] ?? LlmConfiguration::DEFAULT_CONTEXT_WINDOW_LINES);
        $rateLimitDelay = (int)($settings['rateLimitDelay'] ?? LlmConfiguration::DEFAULT_RATE_LIMIT_DELAY);

        if ($batchSize < 1) {
            throw new LlmConfigurationException(sprintf('batchSize must be >= 1, got %d.', $batchSize), 1733044800);
        }
        if ($maxCrossReferenceLocales < 0) {
            throw new LlmConfigurationException(sprintf('maxCrossReferenceLocales must be >= 0, got %d.', $maxCrossReferenceLocales), 1733044801);
        }
        if ($contextWindowLines < 0) {
            throw new LlmConfigurationException(sprintf('contextWindowLines must be >= 0, got %d.', $contextWindowLines), 1733044802);
        }
        if ($rateLimitDelay < 0) {
            throw new LlmConfigurationException(sprintf('rateLimitDelay must be >= 0, got %d.', $rateLimitDelay), 1733044803);
        }

        return new LlmConfiguration(
            enabled: true,
            provider: $cliOptions['llmProvider'] ?? $settings['provider'] ?? null,
            model: $cliOptions['llmModel'] ?? $settings['model'] ?? null,
            dryRun: (bool)($cliOptions['dryRun'] ?? false),
            batchSize: $batchSize,
            maxCrossReferenceLocales: $maxCrossReferenceLocales,
            contextWindowLines: $contextWindowLines,
            includeNodeTypeContext: (bool)($settings['includeNodeTypeContext'] ?? true),
            includeExistingTranslations: (bool)($settings['includeExistingTranslations'] ?? true),
            newState: $this->normalizeState($settings['newState'] ?? null),
            newStateQualifier: $this->normalizeState($settings['newStateQualifier'] ?? null),
            noteEnabled: (bool)($settings['noteEnabled'] ?? false),
            maxTokensPerCall: (int)($settings['maxTokensPerCall'] ?? 4096),
            rateLimitDelay: $rateLimitDelay,
            systemPrompt: (string)($settings['systemPrompt'] ?? ''),
            debug: (bool)($settings['debug'] ?? false),
        );
    }
}
