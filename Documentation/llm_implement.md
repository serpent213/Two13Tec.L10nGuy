# L10nGuy LLM Translation — Implementation Plan

## Executive Summary

This plan extends L10nGuy with LLM-based translation capabilities using `symfony/ai` as the abstraction layer. The implementation integrates cleanly into the existing pipeline: after `CatalogMutationFactory` produces mutations, an `LlmTranslationService` enriches them with translated text before `CatalogWriter` persists.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Existing Pipeline                            │
├─────────────────────────────────────────────────────────────────────┤
│  FileDiscovery → Collectors → ReferenceIndex                        │
│                                    ↓                                │
│                            CatalogIndexBuilder                      │
│                                    ↓                                │
│                            ScanResultBuilder                        │
│                                    ↓                                │
│                        CatalogMutationFactory                       │
│                                    ↓                                │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │                    NEW: LLM Integration Layer                   │ │
│ │                                                                 │ │
│ │   LlmTranslationService                                         │ │
│ │     ├── LlmProviderFactory (creates symfony/ai Platform)        │ │
│ │     ├── TranslationContextBuilder (gathers context)             │ │
│ │     ├── PromptBuilder (constructs system + user prompts)        │ │
│ │     └── ResponseParser (extracts translations from JSON)        │ │
│ └─────────────────────────────────────────────────────────────────┘ │
│                                    ↓                                │
│                            CatalogWriter                            │
│                          (with LLM metadata)                        │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Phase 1: Core Infrastructure

### 1.1 Add symfony/ai as Suggested Dependency

**File:** `composer.json`

```json
{
  "suggest": {
    "symfony/ai-platform": "Required for LLM-based translation features (^0.x)"
  }
}
```

**Runtime Detection Pattern:**

```php
if (!interface_exists(\Symfony\AI\Platform\PlatformInterface::class)) {
    throw new LlmUnavailableException(
        'LLM features require symfony/ai-platform. Run: composer require symfony/ai-platform'
    );
}
```

### 1.2 LlmProviderFactory

**File:** `Classes/Llm/LlmProviderFactory.php`

Creates the appropriate symfony/ai Platform based on Settings configuration. No auto-detection—explicit configuration required.

```php
<?php
declare(strict_types=1);
namespace Two13Tec\L10nGuy\Llm;

use Neos\Flow\Annotations as Flow;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Bridge\Ollama\PlatformFactory as OllamaPlatformFactory;
use Symfony\AI\Platform\Bridge\OpenAI\PlatformFactory as OpenAIPlatformFactory;
use Symfony\AI\Platform\Bridge\Anthropic\PlatformFactory as AnthropicPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

#[Flow\Scope("singleton")]
final class LlmProviderFactory
{
    public function __construct(
        #[Flow\InjectConfiguration(path: 'llm', package: 'Two13Tec.L10nGuy')]
        private readonly array $config = [],
    ) {}

    public function create(): PlatformInterface
    {
        $provider = $this->config['provider'] ?? null;
        if ($provider === null) {
            throw new LlmConfigurationException('No LLM provider configured');
        }

        return match ($provider) {
            'ollama' => $this->createOllama(),
            'openai' => $this->createOpenAI(),
            'anthropic' => $this->createAnthropic(),
            default => throw new LlmConfigurationException(
                sprintf('Unknown LLM provider: %s', $provider)
            ),
        };
    }

    public function model(): string
    {
        return $this->config['model'] ?? throw new LlmConfigurationException(
            'No LLM model configured'
        );
    }

    private function createOllama(): PlatformInterface
    {
        $baseUrl = $this->config['base_url'] ?? 'http://localhost:11434';
        return OllamaPlatformFactory::create($baseUrl, HttpClient::create());
    }

    private function createOpenAI(): PlatformInterface
    {
        $apiKey = $this->resolveEnvValue($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            throw new LlmConfigurationException('OpenAI API key not configured');
        }

        $baseUrl = $this->config['base_url'] ?? null;
        return OpenAIPlatformFactory::create(
            apiKey: $apiKey,
            baseUri: $baseUrl,  // null uses default, or custom for OpenRouter
        );
    }

    private function createAnthropic(): PlatformInterface
    {
        $apiKey = $this->resolveEnvValue($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            throw new LlmConfigurationException('Anthropic API key not configured');
        }

        return AnthropicPlatformFactory::create(apiKey: $apiKey);
    }

    private function resolveEnvValue(string $value): string
    {
        if (preg_match('/^%env\(([^)]+)\)%$/', $value, $matches)) {
            return getenv($matches[1]) ?: '';
        }
        return $value;
    }
}
```

### 1.3 LlmConfiguration DTO

**File:** `Classes/Domain/Dto/LlmConfiguration.php`

```php
<?php
declare(strict_types=1);
namespace Two13Tec\L10nGuy\Domain\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class LlmConfiguration
{
    public function __construct(
        public bool $enabled = false,
        public ?string $provider = null,
        public ?string $model = null,
        public bool $dryRun = false,
        public int $batchSize = 1,
        public int $contextWindowLines = 5,
        public bool $includeNodeTypeContext = true,
        public bool $includeExistingTranslations = true,
        public bool $markAsGenerated = true,
        public string $defaultState = 'needs-review',
        public int $maxTokensPerCall = 4096,
        public int $rateLimitDelay = 100,
        public string $systemPrompt = '',
        public ?string $idPattern = null,
    ) {}
}
```

### 1.4 Extend ScanConfiguration

**File:** `Classes/Domain/Dto/ScanConfiguration.php` (modify)

Add LLM-related properties:

```php
#[Flow\Proxy(false)]
final readonly class ScanConfiguration
{
    public function __construct(
        // ... existing properties ...
        public ?LlmConfiguration $llm = null,
        public ?string $idPattern = null,  // glob pattern for --id filter
    ) {}
}
```

### 1.5 Extend ScanConfigurationFactory

**File:** `Classes/Service/ScanConfigurationFactory.php` (modify)

Add parsing for new CLI options:

```php
public function createFromCliOptions(array $options): ScanConfiguration
{
    // ... existing code ...

    $llmEnabled = (bool)($options['llm'] ?? false);
    $llmConfig = null;

    if ($llmEnabled) {
        $llmConfig = new LlmConfiguration(
            enabled: true,
            provider: $options['llmProvider'] ?? $this->llmSettings['provider'] ?? null,
            model: $options['llmModel'] ?? $this->llmSettings['model'] ?? null,
            dryRun: (bool)($options['dryRun'] ?? false),
            batchSize: (int)($options['batchSize'] ?? $this->llmSettings['batchSize'] ?? 1),
            // ... map remaining settings ...
        );
    }

    return new ScanConfiguration(
        // ... existing params ...
        llm: $llmConfig,
        idPattern: $options['id'] ?? null,
    );
}
```

---

## Phase 2: Context Extraction

### 2.1 TranslationContextBuilder

**File:** `Classes/Llm/TranslationContextBuilder.php`

Aggregates all context types for a translation request.

```php
<?php
declare(strict_types=1);
namespace Two13Tec\L10nGuy\Llm;

use Neos\Flow\Annotations as Flow;
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\TranslationContext;

#[Flow\Scope("singleton")]
final class TranslationContextBuilder
{
    public function build(
        MissingTranslation $missing,
        CatalogIndex $catalogIndex,
        LlmConfiguration $config,
    ): TranslationContext {
        $reference = $missing->reference;

        $sourceSnippet = $config->contextWindowLines > 0
            ? $this->extractSourceSnippet($reference->filePath, $reference->lineNumber, $config->contextWindowLines)
            : null;

        $nodeTypeContext = null;
        if ($config->includeNodeTypeContext && $reference->context === 'yaml') {
            $nodeTypeContext = $this->extractNodeTypeContext($reference->filePath);
        }

        $existingTranslations = [];
        if ($config->includeExistingTranslations) {
            $existingTranslations = $this->gatherExistingTranslations(
                $catalogIndex,
                $missing->key->packageKey,
                $missing->key->sourceName
            );
        }

        return new TranslationContext(
            sourceSnippet: $sourceSnippet,
            nodeTypeContext: $nodeTypeContext,
            existingTranslations: $existingTranslations,
        );
    }

    private function extractSourceSnippet(string $filePath, int $lineNumber, int $windowLines): ?string
    {
        if (!is_file($filePath)) {
            return null;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $start = max(0, $lineNumber - $windowLines - 1);
        $end = min(count($lines), $lineNumber + $windowLines);

        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    private function extractNodeTypeContext(string $filePath): ?string
    {
        if (!is_file($filePath)) {
            return null;
        }

        return file_get_contents($filePath) ?: null;
    }

    /**
     * @return list<array{id: string, source: ?string, translations: array<string, string>}>
     */
    private function gatherExistingTranslations(
        CatalogIndex $catalogIndex,
        string $packageKey,
        string $sourceName,
    ): array {
        $result = [];
        $seenIds = [];

        foreach ($catalogIndex->entries() as $locale => $packages) {
            foreach ($packages[$packageKey][$sourceName] ?? [] as $identifier => $entry) {
                if (!isset($seenIds[$identifier])) {
                    $seenIds[$identifier] = [
                        'id' => $identifier,
                        'source' => $entry->source,
                        'translations' => [],
                    ];
                }
                if ($entry->target !== null && $entry->target !== '') {
                    $seenIds[$identifier]['translations'][$locale] = $entry->target;
                }
            }
        }

        return array_values($seenIds);
    }
}
```

### 2.2 TranslationContext DTO

**File:** `Classes/Domain/Dto/TranslationContext.php`

```php
<?php
declare(strict_types=1);
namespace Two13Tec\L10nGuy\Domain\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class TranslationContext
{
    /**
     * @param list<array{id: string, source: ?string, translations: array<string, string>}> $existingTranslations
     */
    public function __construct(
        public ?string $sourceSnippet = null,
        public ?string $nodeTypeContext = null,
        public array $existingTranslations = [],
    ) {}
}
```

---

## Phase 3: Translation Engine

### 3.1 PromptBuilder

**File:** `Classes/Llm/PromptBuilder.php`

```php
<?php
declare(strict_types=1);
namespace Two13Tec\L10nGuy\Llm;

use Neos\Flow\Annotations as Flow;
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\TranslationContext;

#[Flow\Scope("singleton")]
final class PromptBuilder
{
    private const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
You are a professional translator for a web application built with Neos CMS.

Guidelines:
- Preserve all placeholders exactly: {0}, {name}, %s, etc.
- Maintain consistent terminology with existing translations provided
- Match tone and formality level of the application
- For UI labels: be concise
- For help text: be clear and helpful
- Never translate brand names or technical identifiers
PROMPT;

    public function buildSystemPrompt(LlmConfiguration $config): string
    {
        $prompt = $config->systemPrompt;
        if ($prompt === '') {
            $prompt = self::DEFAULT_SYSTEM_PROMPT;
        }
        return $prompt;
    }

    /**
     * @param list<string> $targetLanguages
     */
    public function buildUserPrompt(
        MissingTranslation $missing,
        TranslationContext $context,
        array $targetLanguages,
    ): string {
        $sourceText = $missing->reference->fallback ?? $missing->key->identifier;

        $parts = [];
        $parts[] = sprintf(
            'Translate the following text to %s.',
            implode(', ', $targetLanguages)
        );
        $parts[] = '';
        $parts[] = sprintf('Translation ID: "%s"', $missing->key->identifier);
        $parts[] = sprintf('Source text: "%s"', $sourceText);

        if ($context->sourceSnippet !== null) {
            $parts[] = '';
            $parts[] = 'Context (surrounding code):';
            $parts[] = '```';
            $parts[] = $context->sourceSnippet;
            $parts[] = '```';
        }

        if ($context->nodeTypeContext !== null) {
            $parts[] = '';
            $parts[] = 'NodeType definition:';
            $parts[] = '```yaml';
            $parts[] = $context->nodeTypeContext;
            $parts[] = '```';
        }

        if ($context->existingTranslations !== []) {
            $parts[] = '';
            $parts[] = 'Existing translations in this file (for terminology consistency):';
            $parts[] = '```json';
            $parts[] = json_encode($context->existingTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $parts[] = '```';
        }

        $parts[] = '';
        $parts[] = 'Respond ONLY with valid JSON in this exact format:';
        $parts[] = '```json';
        $parts[] = '{';
        $parts[] = '  "translations": {';
        foreach ($targetLanguages as $i => $lang) {
            $comma = $i < count($targetLanguages) - 1 ? ',' : '';
            $parts[] = sprintf('    "%s": "your translation here"%s', $lang, $comma);
        }
        $parts[] = '  }';
        $parts[] = '}';
        $parts[] = '```';

        return implode("\n", $parts);
    }
}
```

### 3.2 ResponseParser

**File:** `Classes/Llm/ResponseParser.php`

```php
<?php
declare(strict_types=1);
namespace Two13Tec\L10nGuy\Llm;

use Neos\Flow\Annotations as Flow;

#[Flow\Scope("singleton")]
final class ResponseParser
{
    /**
     * @return array<string, string> locale => translation
     */
    public function parse(string $response): array
    {
        $json = $this->extractJson($response);
        if ($json === null) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $translations = $data['translations'] ?? $data;
        if (!is_array($translations)) {
            return [];
        }

        $result = [];
        foreach ($translations as $locale => $text) {
            if (is_string($text)) {
                $result[(string)$locale] = $text;
            }
        }

        return $result;
    }

    private function extractJson(string $response): ?string
    {
        // Try to find JSON in code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $response, $matches)) {
            return $matches[1];
        }

        // Try raw JSON
        if (preg_match('/(\{[\s\S]*\})/', $response, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
```

### 3.3 LlmTranslationService

**File:** `Classes/Llm/LlmTranslationService.php`

The core service that orchestrates LLM translation.

```php
<?php
declare(strict_types=1);
namespace Two13Tec\L10nGuy\Llm;

use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Domain\Dto\TranslationRequest;
use Two13Tec\L10nGuy\Domain\Dto\TranslationResult;

#[Flow\Scope("singleton")]
final class LlmTranslationService
{
    #[Flow\Inject]
    protected LlmProviderFactory $providerFactory;

    #[Flow\Inject]
    protected TranslationContextBuilder $contextBuilder;

    #[Flow\Inject]
    protected PromptBuilder $promptBuilder;

    #[Flow\Inject]
    protected ResponseParser $responseParser;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    /**
     * Enrich mutations with LLM-generated translations.
     *
     * @param list<CatalogMutation> $mutations
     * @return list<CatalogMutation>
     */
    public function translate(
        array $mutations,
        ScanResult $scanResult,
        LlmConfiguration $config,
    ): array {
        if ($mutations === [] || !$config->enabled) {
            return $mutations;
        }

        if ($config->dryRun) {
            $this->reportDryRun($mutations, $config);
            return $mutations;
        }

        $platform = $this->providerFactory->create();
        $agent = $platform->createAgent($this->providerFactory->model());
        $systemPrompt = $this->promptBuilder->buildSystemPrompt($config);

        // Group mutations by identifier to translate all locales at once
        $grouped = $this->groupByIdentifier($mutations, $scanResult);

        $enriched = [];
        foreach ($grouped as $item) {
            $translatedMutations = $this->translateGroup(
                $item['mutations'],
                $item['missing'],
                $scanResult->catalogIndex,
                $config,
                $agent,
                $systemPrompt,
            );
            array_push($enriched, ...$translatedMutations);

            if ($config->rateLimitDelay > 0) {
                usleep($config->rateLimitDelay * 1000);
            }
        }

        return $enriched;
    }

    /**
     * @param list<CatalogMutation> $mutations
     * @return list<array{mutations: list<CatalogMutation>, missing: MissingTranslation}>
     */
    private function groupByIdentifier(array $mutations, ScanResult $scanResult): array
    {
        $byKey = [];
        $missingByKey = [];

        foreach ($scanResult->missingTranslations as $missing) {
            $key = sprintf(
                '%s|%s|%s',
                $missing->key->packageKey,
                $missing->key->sourceName,
                $missing->key->identifier
            );
            $missingByKey[$key] = $missing;
        }

        foreach ($mutations as $mutation) {
            $key = sprintf(
                '%s|%s|%s',
                $mutation->packageKey,
                $mutation->sourceName,
                $mutation->identifier
            );
            $byKey[$key] ??= [];
            $byKey[$key][] = $mutation;
        }

        $result = [];
        foreach ($byKey as $key => $muts) {
            if (!isset($missingByKey[$key])) {
                continue;
            }
            $result[] = [
                'mutations' => $muts,
                'missing' => $missingByKey[$key],
            ];
        }

        return $result;
    }

    /**
     * @param list<CatalogMutation> $mutations
     * @return list<CatalogMutation>
     */
    private function translateGroup(
        array $mutations,
        MissingTranslation $missing,
        CatalogIndex $catalogIndex,
        LlmConfiguration $config,
        $agent,
        string $systemPrompt,
    ): array {
        $targetLanguages = array_map(fn($m) => $m->locale, $mutations);
        $context = $this->contextBuilder->build($missing, $catalogIndex, $config);
        $userPrompt = $this->promptBuilder->buildUserPrompt($missing, $context, $targetLanguages);

        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($userPrompt),
        );

        try {
            $result = $agent->call($messages);
            $translations = $this->responseParser->parse($result->getContent());
        } catch (\Throwable $e) {
            $this->logger->warning('LLM translation failed', [
                'identifier' => $missing->key->identifier,
                'error' => $e->getMessage(),
            ]);
            return $mutations;
        }

        foreach ($mutations as $mutation) {
            if (isset($translations[$mutation->locale])) {
                $mutation->target = $translations[$mutation->locale];
            }
        }

        return $mutations;
    }

    /**
     * @param list<CatalogMutation> $mutations
     */
    private function reportDryRun(array $mutations, LlmConfiguration $config): void
    {
        $byIdentifier = [];
        foreach ($mutations as $m) {
            $key = $m->identifier;
            $byIdentifier[$key] ??= [];
            $byIdentifier[$key][] = $m->locale;
        }

        $totalTranslations = count($mutations);
        $uniqueIds = count($byIdentifier);
        $estimatedInputTokens = $this->estimateInputTokens($mutations, $config);
        $estimatedOutputTokens = $totalTranslations * 30;  // ~30 tokens per translation

        echo "\n→ Analysing translation workload...\n\n";
        echo sprintf("  Translations to generate:    %d entries\n", $totalTranslations);
        echo sprintf("  Unique translation IDs:      %d\n", $uniqueIds);
        echo sprintf("  Estimated input tokens:      ~%s\n", number_format($estimatedInputTokens));
        echo sprintf("  Estimated output tokens:     ~%s\n", number_format($estimatedOutputTokens));
        echo sprintf("  Batch configuration:         %d ID per call = %d API calls\n",
            $config->batchSize,
            (int)ceil($uniqueIds / $config->batchSize)
        );
        echo "\n";
    }

    /**
     * @param list<CatalogMutation> $mutations
     */
    private function estimateInputTokens(array $mutations, LlmConfiguration $config): int
    {
        $systemPromptTokens = (int)(strlen($config->systemPrompt) / 4);
        $perRequestOverhead = 200;  // prompt template, JSON structure
        $contextTokens = $config->contextWindowLines * 80 / 4;  // ~80 chars per line

        $uniqueIds = count(array_unique(array_map(fn($m) => $m->identifier, $mutations)));

        return $systemPromptTokens + ($uniqueIds * ($perRequestOverhead + $contextTokens));
    }
}
```

---

## Phase 4: Quality & Metadata

### 4.1 Extend CatalogMutation

**File:** `Classes/Domain/Dto/CatalogMutation.php` (modify)

Add metadata for LLM-generated entries:

```php
#[Flow\Proxy(false)]
final class CatalogMutation
{
    // ... existing properties ...

    private bool $llmGenerated = false;
    private ?string $llmProvider = null;
    private ?string $llmModel = null;
    private ?\DateTimeImmutable $llmGeneratedAt = null;

    public bool $isLlmGenerated {
        get => $this->llmGenerated;
        set => $this->llmGenerated = $value;
    }

    public ?string $llmProvider {
        get => $this->llmProvider;
        set => $this->llmProvider = $value;
    }

    public ?string $llmModel {
        get => $this->llmModel;
        set => $this->llmModel = $value;
    }

    public ?\DateTimeImmutable $llmGeneratedAt {
        get => $this->llmGeneratedAt;
        set => $this->llmGeneratedAt = $value;
    }
}
```

### 4.2 Extend CatalogWriter for LLM Metadata

**File:** `Classes/Service/CatalogWriter.php` (modify)

Update `buildUnitFromMutation()` to include LLM notes:

```php
private function buildUnitFromMutation(
    CatalogMutation $mutation,
    bool $writeTarget,
    bool $setNeedsReview,
    string $identifier
): array {
    $unit = [
        'id' => $identifier,
        'source' => $mutation->source,
        'target' => $writeTarget ? $mutation->target : null,
        'state' => $setNeedsReview && $writeTarget ? CatalogEntry::STATE_NEEDS_REVIEW : null,
        // ... existing fields ...
    ];

    // Add LLM metadata as notes
    if ($mutation->isLlmGenerated) {
        $unit['notes'] ??= [];
        $unit['notes'][] = [
            'from' => 'l10nguy',
            'priority' => 1,
            'content' => 'llm-generated',
        ];

        $metaParts = [];
        if ($mutation->llmProvider !== null) {
            $metaParts[] = sprintf('provider:%s', $mutation->llmProvider);
        }
        if ($mutation->llmModel !== null) {
            $metaParts[] = sprintf('model:%s', $mutation->llmModel);
        }
        if ($mutation->llmGeneratedAt !== null) {
            $metaParts[] = sprintf('generated:%s', $mutation->llmGeneratedAt->format('c'));
        }
        if ($metaParts !== []) {
            $unit['notes'][] = [
                'from' => 'l10nguy',
                'content' => implode(' ', $metaParts),
            ];
        }
    }

    return $unit;
}
```

Update `renderTransUnit()` to write `<note>` elements:

```php
private function renderTransUnit(string $identifier, array $unit, int $indentLevel = 3): array
{
    // ... existing source/target rendering ...

    // Render notes
    foreach ($unit['notes'] ?? [] as $note) {
        $noteAttrs = [];
        if (isset($note['from'])) {
            $noteAttrs['from'] = $note['from'];
        }
        if (isset($note['priority'])) {
            $noteAttrs['priority'] = (string)$note['priority'];
        }
        $lines[] = sprintf(
            '%s<note%s>%s</note>',
            $this->indent($indentLevel + 1),
            $this->formatOptionalAttributes($noteAttrs),
            $this->escape($note['content'] ?? '')
        );
    }

    $lines[] = $this->indent($indentLevel) . '</trans-unit>';
    return $lines;
}
```

### 4.3 Placeholder Validation

**File:** `Classes/Llm/PlaceholderValidator.php`

```php
<?php
declare(strict_types=1);
namespace Two13Tec\L10nGuy\Llm;

use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;

#[Flow\Scope("singleton")]
final class PlaceholderValidator
{
    #[Flow\Inject]
    protected LoggerInterface $logger;

    /**
     * Validate that translation preserves all placeholders from source.
     *
     * @param list<string> $expectedPlaceholders
     */
    public function validate(
        string $identifier,
        string $locale,
        string $translation,
        array $expectedPlaceholders,
    ): bool {
        $found = $this->extractPlaceholders($translation);
        $missing = array_diff($expectedPlaceholders, $found);

        if ($missing !== []) {
            $this->logger->warning('LLM translation missing placeholders', [
                'identifier' => $identifier,
                'locale' => $locale,
                'missing' => array_values($missing),
                'expected' => $expectedPlaceholders,
                'found' => $found,
            ]);
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(string $text): array
    {
        preg_match_all('/\{([A-Za-z0-9_.:-]+)\}/', $text, $matches);
        return $matches[1] ?? [];
    }
}
```

---

## Phase 5: CLI Integration

### 5.1 Extend L10nCommandController::scanCommand()

**File:** `Classes/Command/L10nCommandController.php` (modify)

```php
/**
 * Run the localization scan and optionally write missing catalog entries.
 *
 * @param string|null $package Package key to scan
 * @param string|null $source Optional source restriction
 * @param string|null $path Optional search root
 * @param string|null $locales Comma-separated locale list
 * @param string|null $id Translation ID pattern (glob: hero.*, *.label)
 * @param string|null $format Output format: table or json
 * @param bool|null $update Write missing catalog entries
 * @param bool|null $llm Enable LLM-based translation
 * @param string|null $llmProvider LLM provider override
 * @param string|null $llmModel LLM model override
 * @param bool|null $dryRun Estimate tokens without API calls
 * @param int|null $batchSize Translations per LLM call
 * @param bool|null $ignorePlaceholder Suppress placeholder warnings
 * @param bool|null $setNeedsReview Flag new entries as needs-review
 * @param bool|null $quiet Suppress table output
 * @param bool|null $quieter Suppress all stdout
 */
public function scanCommand(
    ?string $package = null,
    ?string $source = null,
    ?string $path = null,
    ?string $locales = null,
    ?string $id = null,
    ?string $format = null,
    ?bool $update = null,
    ?bool $llm = null,
    ?string $llmProvider = null,
    ?string $llmModel = null,
    ?bool $dryRun = null,
    ?int $batchSize = null,
    ?bool $ignorePlaceholder = null,
    ?bool $setNeedsReview = null,
    ?bool $quiet = null,
    ?bool $quieter = null
): void {
    $configuration = $this->scanConfigurationFactory->createFromCliOptions([
        'package' => $package,
        'source' => $source,
        'paths' => $path ? [$path] : [],
        'locales' => $locales,
        'id' => $id,
        'format' => $format,
        'update' => $update,
        'llm' => $llm,
        'llmProvider' => $llmProvider,
        'llmModel' => $llmModel,
        'dryRun' => $dryRun,
        'batchSize' => $batchSize,
        'ignorePlaceholder' => $ignorePlaceholder,
        'setNeedsReview' => $setNeedsReview,
        'quiet' => $quiet,
        'quieter' => $quieter,
    ]);

    // ... existing scanning logic ...

    if ($configuration->update) {
        $mutations = $this->catalogMutationFactory->fromScanResult($scanResult);

        // LLM translation enrichment
        if ($configuration->llm !== null && $configuration->llm->enabled && $mutations !== []) {
            try {
                $mutations = $this->llmTranslationService->translate(
                    $mutations,
                    $scanResult,
                    $configuration->llm,
                );
            } catch (LlmUnavailableException $e) {
                $this->outputLine('! %s', [$e->getMessage()]);
                $this->quit($this->exitCode(self::EXIT_KEY_FAILURE, 7));
            }
        }

        // ... existing write logic ...
    }
}
```

### 5.2 Add --id Filter to ScanResultBuilder

**File:** `Classes/Service/ScanResultBuilder.php` (modify)

Update `iterateFilteredReferences()` to support glob patterns:

```php
private function iterateFilteredReferences(
    ReferenceIndex $referenceIndex,
    ScanConfiguration $configuration
): iterable {
    foreach ($referenceIndex->references() as $packageKey => $sources) {
        if ($configuration->packageKey !== null && $configuration->packageKey !== $packageKey) {
            continue;
        }

        foreach ($sources as $sourceName => $identifiers) {
            if ($configuration->sourceName !== null && !$this->matchesPattern($sourceName, $configuration->sourceName)) {
                continue;
            }

            foreach ($identifiers as $identifier => $reference) {
                if ($configuration->idPattern !== null && !$this->matchesPattern($identifier, $configuration->idPattern)) {
                    continue;
                }

                yield [new TranslationKey($packageKey, $sourceName, $identifier), $reference];
            }
        }
    }
}

private function matchesPattern(string $value, string $pattern): bool
{
    if (!str_contains($pattern, '*')) {
        return $value === $pattern;
    }

    $regex = '/^' . str_replace(['*', '.'], ['.*', '\\.'], $pattern) . '$/';
    return preg_match($regex, $value) === 1;
}
```

---

## Phase 6: Bulk Translation Command

### 6.1 New translateCommand

**File:** `Classes/Command/L10nCommandController.php` (add method)

```php
/**
 * Bulk translate catalog entries to a new locale using LLM.
 *
 * @param string $to Target locale for translation
 * @param string|null $from Source locale (uses first available if omitted)
 * @param string|null $package Package key to translate
 * @param string|null $source Source pattern
 * @param string|null $id Translation ID pattern
 * @param string|null $path Search root
 * @param string|null $llmProvider LLM provider override
 * @param string|null $llmModel LLM model override
 * @param bool|null $dryRun Estimate tokens without API calls
 * @param int|null $batchSize Translations per LLM call
 * @param bool|null $quiet Suppress table output
 * @param bool|null $quieter Suppress all stdout
 */
public function translateCommand(
    string $to,
    ?string $from = null,
    ?string $package = null,
    ?string $source = null,
    ?string $id = null,
    ?string $path = null,
    ?string $llmProvider = null,
    ?string $llmModel = null,
    ?bool $dryRun = null,
    ?int $batchSize = null,
    ?bool $quiet = null,
    ?bool $quieter = null
): void {
    if (!interface_exists(\Symfony\AI\Platform\PlatformInterface::class)) {
        $this->outputLine('! LLM features require symfony/ai-platform. Run: composer require symfony/ai-platform');
        $this->quit($this->exitCode(self::EXIT_KEY_FAILURE, 7));
    }

    $configuration = $this->scanConfigurationFactory->createFromCliOptions([
        'package' => $package,
        'source' => $source,
        'id' => $id,
        'paths' => $path ? [$path] : [],
        'locales' => $from ? [$from, $to] : [$to],
        'llm' => true,
        'llmProvider' => $llmProvider,
        'llmModel' => $llmModel,
        'dryRun' => $dryRun,
        'batchSize' => $batchSize,
        'quiet' => $quiet,
        'quieter' => $quieter,
    ]);

    $this->fileDiscoveryService->seedFromConfiguration($configuration);
    $catalogIndex = $this->catalogIndexBuilder->build($configuration);

    $sourceLocale = $from ?? $this->detectSourceLocale($catalogIndex, $to);
    if ($sourceLocale === null) {
        $this->outputLine('! Unable to determine source locale. Use --from to specify.');
        $this->quit($this->exitCode(self::EXIT_KEY_FAILURE, 7));
    }

    $entriesToTranslate = $this->findMissingInTargetLocale(
        $catalogIndex,
        $sourceLocale,
        $to,
        $configuration
    );

    if ($entriesToTranslate === []) {
        $this->outputLine('No entries need translation from %s to %s.', [$sourceLocale, $to]);
        return;
    }

    $this->outputLine('Found %d entries to translate from %s to %s.', [
        count($entriesToTranslate),
        $sourceLocale,
        $to,
    ]);

    $mutations = $this->buildBulkMutations($entriesToTranslate, $to);

    // Use LlmBulkTranslationService for catalog-to-catalog translation
    $mutations = $this->llmBulkTranslationService->translate(
        $mutations,
        $entriesToTranslate,
        $catalogIndex,
        $configuration->llm,
    );

    if (!$configuration->llm->dryRun) {
        $touched = $this->catalogWriter->write($mutations, $catalogIndex, $configuration);
        foreach ($touched as $file) {
            $this->outputLine('Touched catalog: %s', [$this->relativePath($file)]);
        }
    }
}
```

### 6.2 LlmBulkTranslationService

**File:** `Classes/Llm/LlmBulkTranslationService.php`

Similar to `LlmTranslationService` but optimized for catalog-to-catalog translation:

```php
<?php
declare(strict_types=1);
namespace Two13Tec\L10nGuy\Llm;

use Neos\Flow\Annotations as Flow;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;

#[Flow\Scope("singleton")]
final class LlmBulkTranslationService
{
    // Similar structure to LlmTranslationService
    // but uses existing catalog entries as source instead of MissingTranslation

    /**
     * @param list<CatalogMutation> $mutations
     * @param list<CatalogEntry> $sourceEntries
     * @return list<CatalogMutation>
     */
    public function translate(
        array $mutations,
        array $sourceEntries,
        CatalogIndex $catalogIndex,
        LlmConfiguration $config,
    ): array {
        // Build context from all existing translations
        // Translate in batches
        // Return enriched mutations
    }
}
```

---

## File Structure Summary

```
Classes/
├── Command/
│   └── L10nCommandController.php          # Modified: add LLM options, translateCommand
├── Domain/
│   └── Dto/
│       ├── CatalogMutation.php            # Modified: add LLM metadata properties
│       ├── LlmConfiguration.php           # NEW
│       ├── ScanConfiguration.php          # Modified: add llm, idPattern
│       └── TranslationContext.php         # NEW
├── Llm/
│   ├── Exception/
│   │   ├── LlmConfigurationException.php  # NEW
│   │   └── LlmUnavailableException.php    # NEW
│   ├── LlmBulkTranslationService.php      # NEW
│   ├── LlmProviderFactory.php             # NEW
│   ├── LlmTranslationService.php          # NEW
│   ├── PlaceholderValidator.php           # NEW
│   ├── PromptBuilder.php                  # NEW
│   ├── ResponseParser.php                 # NEW
│   └── TranslationContextBuilder.php      # NEW
├── Service/
│   ├── CatalogWriter.php                  # Modified: LLM notes in XLF
│   ├── ScanConfigurationFactory.php       # Modified: parse LLM options
│   └── ScanResultBuilder.php              # Modified: --id glob filter

Configuration/
└── Settings.L10nGuy.yaml                  # Already has llm section

Tests/
├── Unit/
│   └── Llm/
│       ├── PromptBuilderTest.php          # NEW
│       ├── ResponseParserTest.php         # NEW
│       └── PlaceholderValidatorTest.php   # NEW
└── Functional/
    └── Command/
        └── LlmTranslationTest.php         # NEW
```

---

## Implementation Order

1. **Week 1: Infrastructure**
   - [ ] Add symfony/ai-platform to composer.json (suggest)
   - [ ] Create exception classes
   - [ ] Implement `LlmProviderFactory`
   - [ ] Create `LlmConfiguration` DTO
   - [ ] Extend `ScanConfiguration` and factory
   - [ ] Unit tests for provider factory

2. **Week 2: Context & Prompting**
   - [ ] Implement `TranslationContext` DTO
   - [ ] Implement `TranslationContextBuilder`
   - [ ] Implement `PromptBuilder`
   - [ ] Implement `ResponseParser`
   - [ ] Unit tests for context builder, prompt builder, response parser

3. **Week 3: Core Translation**
   - [ ] Implement `LlmTranslationService`
   - [ ] Implement `PlaceholderValidator`
   - [ ] Wire into `L10nCommandController::scanCommand()`
   - [ ] Add `--llm`, `--llm-provider`, `--llm-model`, `--dry-run` options
   - [ ] Functional tests with mocked LLM

4. **Week 4: Quality & Metadata**
   - [ ] Extend `CatalogMutation` with LLM metadata
   - [ ] Update `CatalogWriter` to emit `<note>` elements
   - [ ] Implement `--id` filter with glob support
   - [ ] Update existing tests

5. **Week 5: Bulk Translation**
   - [ ] Implement `LlmBulkTranslationService`
   - [ ] Add `translateCommand` to controller
   - [ ] Implement `--from`, `--to` locale handling
   - [ ] Functional tests for bulk translation

6. **Week 6: Polish & Documentation**
   - [ ] Rate limiting implementation
   - [ ] Token estimation refinement
   - [ ] Error handling improvements
   - [ ] Update Architecture.rst
   - [ ] Update README with LLM usage examples

---

## Risk Mitigation

| Risk | Impact | Mitigation |
|------|--------|------------|
| symfony/ai API instability | High | Pin to specific version, wrap in adapter layer |
| LLM response format variations | Medium | Robust JSON extraction, fallback strategies |
| Rate limiting by providers | Medium | Configurable delay, exponential backoff |
| Context window overflow | Medium | Truncate context intelligently, warn user |
| Cost overruns (token usage) | Medium | Dry-run mode mandatory for large catalogs |
| Placeholder corruption | High | Post-translation validation, reject invalid |

---

## Success Criteria

1. `./flow l10n:scan --update --llm` generates translations for all configured locales
2. `--dry-run` accurately estimates token usage before API calls
3. Generated XLF entries include `state="needs-review"` and LLM metadata notes
4. `./flow l10n:translate --to=fr` bulk-translates entire catalogs
5. Placeholder integrity is validated and violations logged
6. All existing tests continue to pass
7. New unit tests cover ≥80% of LLM-related code
