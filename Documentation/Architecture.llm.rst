.. _architecture-llm:

=========================
LLM Translation Extension
=========================

L10nGuy integrates with large language models via ``symfony/ai-platform`` to
provide context-aware automatic translation. This extension hooks into the
existing pipeline between mutation creation and catalog writing, enriching
``CatalogMutation`` objects with LLM-generated translations before persistence.


Overview
========

The LLM extension addresses three translation scenarios:

1. **Scan with translation** — When ``l10n:scan --update --llm`` discovers
   missing entries, the LLM generates translations for all configured locales
   before writing to XLF files.

2. **Bulk translation** — The ``l10n:translate`` command translates entire
   catalogs from one locale to another, useful for adding new language support.

3. **Regional variants** — Copy and adapt translations between regional locales
   (e.g., ``de`` → ``de_CH``) with dialect-aware adjustments.

All generated translations are marked with ``state="needs-review"`` and include
metadata notes for audit trails.


Data Flow
=========

.. code-block:: text

   ┌─────────────────────────────────────────────────────────────────────┐
   │                      Existing L10nGuy Pipeline                      │
   ├─────────────────────────────────────────────────────────────────────┤
   │  FileDiscovery → Collectors → ReferenceIndex → ScanResultBuilder    │
   │                                                       │             │
   │                                                       ▼             │
   │                                           CatalogMutationFactory    │
   │                                                       │             │
   │                                          mutations[] (source only)  │
   └───────────────────────────────────────────────────────┼─────────────┘
                                                           │
                                                           ▼
   ┌─────────────────────────────────────────────────────────────────────┐
   │                        LLM Extension Layer                          │
   ├─────────────────────────────────────────────────────────────────────┤
   │                                                                     │
   │   LlmTranslationService                                             │
   │     │                                                               │
   │     ├── LlmProviderFactory                                          │
   │     │     └── Creates symfony/ai Platform (Ollama, OpenAI, etc.)    │
   │     │                                                               │
   │     ├── TranslationContextBuilder                                   │
   │     │     ├── Source file snippets (surrounding code)               │
   │     │     ├── NodeType context (for YAML references)                │
   │     │     └── Existing translations (terminology consistency)       │
   │     │                                                               │
   │     ├── PromptBuilder                                               │
   │     │     ├── System prompt (from Settings, includes glossary)      │
   │     │     └── User prompt (source text + context + JSON schema)     │
   │     │                                                               │
   │     └── ResponseParser                                              │
   │           └── Extracts translations from JSON response              │
   │                                                                     │
   │                              mutations[] (with target translations) │
   └───────────────────────────────────────────────────────┼─────────────┘
                                                           │
                                                           ▼
   ┌─────────────────────────────────────────────────────────────────────┐
   │                            CatalogWriter                            │
   │                    (writes XLF with LLM metadata)                   │
   └─────────────────────────────────────────────────────────────────────┘


Key Components
==============

LlmProviderFactory
------------------

Creates ``symfony/ai`` Platform instances based on explicit configuration.
Supports four provider types:

``ollama``
   Local LLM server. Requires ``base_url`` (default: ``http://localhost:11434``).
   No API key needed.

``openai``
   OpenAI API or compatible endpoints. Requires ``api_key``. Optional
   ``base_url`` for OpenRouter or Azure OpenAI.

``anthropic``
   Anthropic Claude API. Requires ``api_key``.

``openrouter``
   Configured as ``provider: openai`` with custom ``base_url`` pointing to
   ``https://openrouter.ai/api/v1``.

The factory fails fast with clear error messages when configuration is
incomplete::

   final class LlmProviderFactory
   {
       public function create(): PlatformInterface
       {
           return match ($this->config['provider']) {
               'ollama' => $this->createOllama(),
               'openai' => $this->createOpenAI(),
               'anthropic' => $this->createAnthropic(),
               default => throw new LlmConfigurationException(...),
           };
       }

       public function model(): string
       {
           return $this->config['model']
               ?? throw new LlmConfigurationException('No LLM model configured');
       }
   }


TranslationContextBuilder
-------------------------

Aggregates contextual information to help the LLM produce accurate translations:

**Source file snippet**
   Extracts lines surrounding the ``I18n.translate()`` call site. Configurable
   via ``contextWindowLines`` (default: 5 lines before and after).

**NodeType context**
   When the reference originates from ``NodeTypes/**/*.yaml``, includes the
   full NodeType definition. This provides semantic understanding—the LLM knows
   "this is a UI label for a Hero content component".

**Existing translations**
   Gathers all translations from the same source file across all locales.
   Ensures terminology consistency with established patterns::

      {
        "existingTranslations": [
          {"id": "card.title", "source": "Card Title", "de": "Kartentitel"},
          {"id": "card.description", "source": "Description", "de": "Beschreibung"}
        ]
      }


PromptBuilder
-------------

Constructs prompts for the LLM with two components:

**System prompt**
   Loaded from ``Settings.L10nGuy.llm.systemPrompt``. Should include:

   - Translation guidelines (preserve placeholders, match tone)
   - Domain context (business description, target audience)
   - Glossary terms (enforced translations for key terminology)

**User prompt**
   Generated per translation request::

      Translate the following text to de, fr.

      Translation ID: "hero.headline"
      Source text: "Welcome to our platform"

      Context (surrounding code):
      ```
      <h1>{I18n.translate('hero.headline', 'Welcome to our platform')}</h1>
      ```

      Existing translations in this file:
      ```json
      [{"id": "hero.cta", "source": "Get Started", "de": "Jetzt starten"}]
      ```

      Respond ONLY with valid JSON in this exact format:
      ```json
      {
        "translations": {
          "de": "your translation here",
          "fr": "your translation here"
        }
      }
      ```


ResponseParser
--------------

Extracts translations from LLM responses with fallback strategies:

1. Look for JSON in fenced code blocks (``\`\`\`json ... \`\`\```)
2. Extract raw JSON object from response text
3. Parse and validate structure

Handles both nested (``{"translations": {...}}``) and flat (``{"de": "..."}}``)
response formats::

   final class ResponseParser
   {
       /** @return array<string, string> */
       public function parse(string $response): array
       {
           $json = $this->extractJson($response);
           $data = json_decode($json, true);
           return $data['translations'] ?? $data;
       }
   }


LlmTranslationService
---------------------

Orchestrates the translation process:

1. Groups mutations by identifier (translate all locales for one ID together)
2. Builds context for each translation request
3. Calls the LLM via ``symfony/ai`` agent
4. Parses response and enriches mutations with translated text
5. Validates placeholder preservation
6. Adds LLM metadata to mutations

The service respects rate limiting via ``rateLimitDelay`` between API calls::

   final class LlmTranslationService
   {
       /** @param list<CatalogMutation> $mutations */
       public function translate(
           array $mutations,
           ScanResult $scanResult,
           LlmConfiguration $config,
       ): array {
           $platform = $this->providerFactory->create();
           $agent = $platform->createAgent($this->providerFactory->model());

           foreach ($this->groupByIdentifier($mutations) as $group) {
               $this->translateGroup($group, $agent, $config);
               usleep($config->rateLimitDelay * 1000);
           }

           return $mutations;
       }
   }


PlaceholderValidator
--------------------

Post-translation validation ensures placeholders are preserved::

   final class PlaceholderValidator
   {
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
                   'missing' => $missing,
               ]);
               return false;
           }
           return true;
       }
   }

Placeholder patterns detected: ``{0}``, ``{name}``, ``{user.name}``.


Configuration
=============

All LLM settings live under ``Two13Tec.L10nGuy.llm``:

.. code-block:: yaml

   Two13Tec:
     L10nGuy:
       llm:
         # Provider selection (required)
         provider: ollama
         model: llama3.2:latest
         base_url: 'http://localhost:11434'

         # Or OpenAI / OpenRouter
         # provider: openai
         # model: gpt-4o-mini
         # api_key: '%env(OPENAI_API_KEY)%'
         # base_url: 'https://openrouter.ai/api/v1'  # for OpenRouter

         # Or Anthropic
         # provider: anthropic
         # model: claude-sonnet-4-5
         # api_key: '%env(ANTHROPIC_API_KEY)%'

         # Context extraction
         contextWindowLines: 5
         includeNodeTypeContext: true
         includeExistingTranslations: true

         # Batching (1 = one ID per API call, all languages)
         batchSize: 1
         maxBatchSize: 10

         # Quality markers
         markAsGenerated: true
         defaultState: 'needs-review'

         # Rate limiting
         maxTokensPerCall: 4096
         rateLimitDelay: 100  # milliseconds

         # System prompt (customise for your domain)
         systemPrompt: |
           You are a professional translator for a web application.

           Guidelines:
           - Preserve all placeholders exactly: {0}, {name}, %s
           - Match tone with existing translations
           - For UI labels: be concise
           - Never translate brand names

           Domain: Medieval artwork e-commerce platform.
           Audience: Art collectors and historians.

           Glossary:
           - "Artwork" → DE: "Kunstwerk", FR: "Œuvre d'art"
           - "Gallery" → DE: "Galerie", FR: "Galerie"


Command Line Interface
======================

Scan with LLM Translation
-------------------------

Extends the existing ``l10n:scan`` command::

   ./flow l10n:scan --update --llm

With explicit provider/model override::

   ./flow l10n:scan --update --llm --llm-provider=anthropic --llm-model=claude-sonnet-4-20250514

Dry-run mode estimates token usage without making API calls::

   ./flow l10n:scan --update --llm --dry-run

   → Analysing translation workload...

     Translations to generate:    47 entries
     Unique translation IDs:      16
     Estimated input tokens:      ~12,400
     Estimated output tokens:     ~4,200
     Batch configuration:         1 ID per call = 16 API calls


Filter by Translation ID
------------------------

Use glob patterns to target specific translations::

   ./flow l10n:scan --update --llm --id="hero.*"
   ./flow l10n:scan --update --llm --id="*.label"
   ./flow l10n:scan --update --llm --id="content.hero.headline"


Bulk Translation
----------------

Translate entire catalogs to a new language::

   ./flow l10n:translate --to=ja --package=Two13Tec.Senegal

With explicit source locale::

   ./flow l10n:translate --from=en --to=ja --package=Two13Tec.Senegal

Regional variant adaptation::

   ./flow l10n:translate --from=de --to=de_CH --package=Two13Tec.Senegal

Skips entries that already have translations in the target locale.


CLI Options Reference
---------------------

============================  ================================================
Option                        Description
============================  ================================================
``--llm``                     Enable LLM-based translation
``--llm-provider``            Override provider (ollama, openai, anthropic)
``--llm-model``               Override model identifier
``--dry-run``                 Estimate tokens without API calls
``--batch-size``              Translations per API call (default: 1)
``--id``                      Translation ID pattern (glob: ``hero.*``)
``--from``                    Source locale for bulk translation
``--to``                      Target locale for bulk translation
============================  ================================================


XLF Output Format
=================

LLM-generated translations include metadata for review workflows:

.. code-block:: xml

   <trans-unit id="hero.headline" xml:space="preserve">
     <source>Welcome to our platform</source>
     <target state="needs-review">Willkommen auf unserer Plattform</target>
     <note from="l10nguy" priority="1">llm-generated</note>
     <note from="l10nguy">provider:anthropic model:claude-sonnet-4-20250514 generated:2025-01-15T10:30:00Z</note>
   </trans-unit>

This enables:

- Filtering in translation tools (``state="needs-review"``)
- Audit trails (which model, when generated)
- Batch approval workflows


Value Objects & DTOs
====================

``LlmConfiguration``
--------------------

Captures all LLM-related settings for a translation run::

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


``TranslationContext``
----------------------

Aggregated context for a single translation request::

   #[Flow\Proxy(false)]
   final readonly class TranslationContext
   {
       /** @param list<array{id: string, source: ?string, translations: array<string, string>}> $existingTranslations */
       public function __construct(
           public ?string $sourceSnippet = null,
           public ?string $nodeTypeContext = null,
           public array $existingTranslations = [],
       ) {}
   }


Extended ``CatalogMutation``
----------------------------

The existing DTO gains LLM metadata properties::

   final class CatalogMutation
   {
       // ... existing properties ...

       public bool $isLlmGenerated { get; set; }
       public ?string $llmProvider { get; set; }
       public ?string $llmModel { get; set; }
       public ?\DateTimeImmutable $llmGeneratedAt { get; set; }
   }


Error Handling
==============

The LLM extension follows a fail-fast approach with graceful degradation:

==============================  ================================================
Error                           Handling
==============================  ================================================
Missing provider config         Exit with error, show configuration guidance
API authentication failure      Exit with error, check credentials message
API rate limit                  Exponential backoff, respect ``Retry-After``
API timeout                     Retry up to 3 times, then skip entry
Invalid JSON response           Log warning, skip entry, continue
Missing translation in response Log warning, keep original mutation, continue
Placeholder mismatch            Log warning, optionally reject translation
==============================  ================================================

Failed translations are logged to ``Data/Logs/L10nGuy_LLM.log``::

   [2025-01-15 10:30:45] WARNING: Translation failed for hero.headline (de): API timeout
   [2025-01-15 10:30:46] INFO: Skipping hero.headline, continuing with next entry


Optional Dependency
===================

``symfony/ai-platform`` is a suggested dependency, not required::

   {
     "suggest": {
       "symfony/ai-platform": "Required for LLM-based translation features"
     }
   }

Runtime detection prevents crashes when the package is not installed::

   if (!interface_exists(\Symfony\AI\Platform\PlatformInterface::class)) {
       throw new LlmUnavailableException(
           'LLM features require symfony/ai-platform. Run: composer require symfony/ai-platform'
       );
   }


Testing Strategy
================

Unit Tests
----------

Located in ``Tests/Unit/Llm/``:

``PromptBuilderTest``
   Verifies prompt construction with various context combinations.

``ResponseParserTest``
   Tests JSON extraction from different response formats (code blocks, raw JSON,
   malformed responses).

``PlaceholderValidatorTest``
   Validates placeholder detection and mismatch reporting.

``LlmProviderFactoryTest``
   Tests provider instantiation and configuration error handling.


Functional Tests
----------------

Located in ``Tests/Functional/Command/``:

``LlmTranslationTest``
   Exercises the full pipeline with mocked LLM responses. Verifies:

   - Mutations are enriched with translations
   - XLF files contain correct metadata
   - Dry-run mode produces accurate estimates
   - ``--id`` filtering works correctly


Integration Tests
-----------------

Manual testing against real providers (not automated):

- Ollama with ``llama3.2:latest``
- OpenAI with ``gpt-4o-mini``
- Anthropic with ``claude-sonnet-4-5``
- OpenRouter with free tier models


Known Limitations
=================

* **Token estimation** is heuristic-based (~4 characters per token). Actual
  usage may vary by model and content.

* **Batch size > 1** increases efficiency but may reduce translation quality
  for complex entries. Recommended to keep at 1 for production use.

* **Dynamic IDs** (e.g., ``I18n.translate('button.' . $action)``) cannot be
  translated as the ID is not statically known.

* **Streaming responses** are not supported. Each translation waits for the
  full LLM response.

* **Cost tracking** is not built-in. Use provider dashboards to monitor API
  usage and costs.


Further Reading
===============

* `symfony/ai documentation <https://symfony.com/doc/current/ai/index.html>`_

* `OpenAI API reference <https://platform.openai.com/docs/api-reference>`_

* `Anthropic API reference <https://docs.anthropic.com/en/api>`_

* `Ollama documentation <https://ollama.ai/docs>`_

* :ref:`architecture` for core L10nGuy pipeline documentation
