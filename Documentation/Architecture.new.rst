.. _architecture:

============
Architecture
============

L10nGuy follows a pipeline architecture: discover files, collect translation
references, index existing catalogs, diff the two, and report or mutate. An
optional LLM translation layer enriches mutations with context-aware
translations before writing. Each stage is handled by dedicated services that
can be tested and extended independently.


System Overview
===============

L10nGuy solves three related problems:

1. **Missing translations** — ``I18n.translate()`` calls in code that have no
   matching ``<trans-unit>`` in the XLF catalogs.

2. **Unused translations** — Catalog entries that no longer match any
   reference in the codebase (dead strings).

3. **Automated translation** — LLM-powered generation of translations for
   missing entries, with context extraction and quality tracking.

By comparing a *reference index* (what the code asks for) against a *catalog
index* (what XLF files provide), the helper produces actionable reports and
can optionally mutate catalogs to close the gap—either with placeholder
entries or fully translated text.


Data Flow
---------

.. code-block:: text

   ┌─────────────────┐
   │ FileDiscovery   │  Glob patterns from Settings.L10nGuy.filePatterns
   └────────┬────────┘
            │ SplFileInfo[]
            ▼
   ┌─────────────────┐
   │ Collectors      │  PhpReferenceCollector, FusionReferenceCollector,
   │ (strategy)      │  YamlReferenceCollector
   └────────┬────────┘
            │ TranslationReference[]
            ▼
   ┌─────────────────┐
   │ ReferenceIndex  │  package → source → id → reference(s)
   └────────┬────────┘
            │
            ▼
   ┌─────────────────┐
   │ CatalogIndex    │  locale → package → source → id → CatalogEntry
   │ Builder         │  (reads XLF files via CatalogFileParser)
   └────────┬────────┘
            │
            ▼
   ┌─────────────────┐
   │ ScanResult      │  Diff: MissingTranslation[], PlaceholderMismatch[]
   │ Builder         │
   └────────┬────────┘
            │
            ├──► CLI table / JSON report
            │
            ▼
   ┌─────────────────┐
   │ CatalogMutation │  Creates mutation DTOs from scan results
   │ Factory         │
   └────────┬────────┘
            │ mutations[] (source text only)
            │
            ▼
   ┌─────────────────────────────────────────────────────────────────┐
   │                    LLM Translation Layer                        │
   │                      (when --llm enabled)                       │
   │                                                                 │
   │   LlmTranslationService                                         │
   │     ├── LlmProviderFactory (creates symfony/ai Platform)        │
   │     ├── TranslationContextBuilder (gathers context)             │
   │     ├── PromptBuilder (constructs system + user prompts)        │
   │     └── ResponseParser (extracts translations from JSON)        │
   │                                                                 │
   └────────┬────────────────────────────────────────────────────────┘
            │ mutations[] (with target translations + metadata)
            ▼
   ┌─────────────────┐
   │ CatalogWriter   │  Writes deterministic XLF with optional LLM notes
   └─────────────────┘


Core Components
===============

Reference Collection (Strategy Pattern)
---------------------------------------

Collectors implement ``ReferenceCollectorInterface``::

   interface ReferenceCollectorInterface
   {
       public function supports(SplFileInfo $file): bool;

       /** @return list<TranslationReference> */
       public function collect(SplFileInfo $file): array;
   }

Three collectors ship by default:

``PhpReferenceCollector``
   Uses ``nikic/php-parser`` in tolerant mode. Detects ``translateById()``,
   ``I18n::translate()``, and fully-qualified shorthand strings
   (``Package:Source:id``). Extracts placeholder arguments and fallback text.

``FusionReferenceCollector``
   Regex-based heuristics (Fusion's parser is ``@internal``). Handles classic
   ``I18n.translate(...)`` and fluent ``Translation.id(...).source(...)``.
   Tracks placeholder arguments and inline fallbacks.

``YamlReferenceCollector``
   Parses NodeTypes YAML with Symfony YAML. Derives the translation source
   from the file path (``NodeTypes/Content/Card.yaml`` becomes
   ``NodeTypes.Content.Card``) and uses property keys for IDs.

Adding a new collector (e.g., Twig, Latte) requires implementing the interface
and tagging the service for auto-discovery. No core changes needed.


Index Structures
----------------

``ReferenceIndex``
   Aggregates ``TranslationReference`` DTOs keyed by package, source, and ID.
   Tracks duplicates (same ID referenced multiple times) for diagnostics.

``CatalogIndex``
   Aggregates ``CatalogEntry`` DTOs keyed by locale, package, source, and ID.
   Built by ``CatalogIndexBuilder`` which walks XLF files via
   ``CatalogFileParser``.

Both indexes are immutable after construction (readonly DTOs, no setters).


Catalog Mutation & Writing
--------------------------

``CatalogMutationFactory``
   Creates ``CatalogMutation`` DTOs describing additions or removals. When LLM
   translation is disabled, mutations contain only source text (fallback or
   identifier). When enabled, the LLM layer enriches mutations with translated
   target text.

``CatalogWriter``
   Groups mutations by locale/package/source, reads the existing catalog,
   applies mutations, and writes deterministic two-space-indented XML. Handles
   plural groups (``<group restype="x-gettext-plurals">``) when present.

Writer guarantees:

* Attribute ordering is consistent (``id``, ``xml:space``, then others).
* Trans-units are sorted naturally by ID when ``orderById`` is enabled.
* Round-trip safe: re-rendering a catalog without mutations produces
  byte-identical output.
* LLM metadata notes are preserved and written correctly.


LLM Translation Layer
=====================

The LLM extension integrates with ``symfony/ai-platform`` to provide
context-aware automatic translation. It hooks into the pipeline between
mutation creation and catalog writing.

.. note::

   ``symfony/ai-platform`` is a suggested dependency. LLM features gracefully
   degrade when the package is not installed::

      if (!interface_exists(\Symfony\AI\Platform\PlatformInterface::class)) {
          throw new LlmUnavailableException(
              'LLM features require symfony/ai-platform. Run: composer require symfony/ai-platform'
          );
      }


LlmProviderFactory
------------------

Creates ``symfony/ai`` Platform instances based on explicit configuration.
Supports four provider configurations:

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
               $this->logger->warning('LLM translation missing placeholders', [...]);
               return false;
           }
           return true;
       }
   }

Placeholder patterns detected: ``{0}``, ``{name}``, ``{user.name}``.


Command Surface
===============

``L10nCommandController`` orchestrates all commands:


l10n:scan
---------

Scan for missing translations and optionally write catalog entries::

   ./flow l10n:scan [options]

==========================  ===================================================
Option                      Description
==========================  ===================================================
``--package``               Package key to scan (default: all packages)
``--source``                Source pattern with glob support (``Presentation.*``)
``--path``                  Search root for references and catalogs
``--locales``               Comma-separated locale list
``--id``                    Translation ID pattern (``hero.*``, ``*.label``)
``--format``                Output format: ``table`` (default) or ``json``
``--update``                Write missing catalog entries to XLF files
``--llm``                   Enable LLM-based translation
``--llm-provider``          Override provider (ollama, openai, anthropic)
``--llm-model``             Override model identifier
``--dry-run``               Estimate tokens without making API calls
``--batch-size``            Translations per LLM call (default: 1)
``--ignore-placeholder``    Suppress placeholder mismatch warnings
``--set-needs-review``      Flag new entries as needs-review (default: true)
``--quiet``                 Suppress table output
``--quieter``               Suppress all stdout (errors on stderr)
==========================  ===================================================

Examples::

   # Basic scan
   ./flow l10n:scan --package=Two13Tec.Senegal

   # Write entries with LLM translation
   ./flow l10n:scan --update --llm

   # With explicit provider
   ./flow l10n:scan --update --llm --llm-provider=anthropic --llm-model=claude-sonnet-4-20250514

   # Dry-run to estimate costs
   ./flow l10n:scan --update --llm --dry-run

   # Filter by ID pattern
   ./flow l10n:scan --update --llm --id="hero.*"


l10n:unused
-----------

Detect and optionally delete unused catalog entries::

   ./flow l10n:unused [options]

==========================  ===================================================
Option                      Description
==========================  ===================================================
``--package``               Package key to inspect
``--source``                Source pattern
``--path``                  Search root
``--locales``               Comma-separated locale list
``--format``                Output format: ``table`` or ``json``
``--delete``                Delete unused catalog entries
``--quiet``                 Suppress table output
``--quieter``               Suppress all stdout
==========================  ===================================================


l10n:translate
--------------

Bulk translate catalog entries to a new locale using LLM::

   ./flow l10n:translate --to=<locale> [options]

==========================  ===================================================
Option                      Description
==========================  ===================================================
``--to``                    Target locale for translation (required)
``--from``                  Source locale (auto-detected if omitted)
``--package``               Package key to translate
``--source``                Source pattern
``--id``                    Translation ID pattern
``--path``                  Search root
``--llm-provider``          Override provider
``--llm-model``             Override model
``--dry-run``               Estimate tokens without API calls
``--batch-size``            Translations per LLM call
``--quiet``                 Suppress output
``--quieter``               Suppress all stdout
==========================  ===================================================

Examples::

   # Translate to Japanese
   ./flow l10n:translate --to=ja --package=Two13Tec.Senegal

   # With explicit source locale
   ./flow l10n:translate --from=en --to=ja --package=Two13Tec.Senegal

   # Regional variant
   ./flow l10n:translate --from=de --to=de_CH --package=Two13Tec.Senegal


l10n:format
-----------

Re-render catalogs with canonical formatting or verify current state::

   ./flow l10n:format [options]

==========================  ===================================================
Option                      Description
==========================  ===================================================
``--package``               Package key to format
``--source``                Source pattern
``--path``                  Search root
``--locales``               Comma-separated locale list
``--check``                 Check-only mode (exits non-zero if dirty)
==========================  ===================================================


Exit Codes
----------

Configurable via ``Two13Tec.L10nGuy.exitCodes``:

====  ===============================
Code  Meaning
====  ===============================
0     Clean (no issues)
5     Missing translations found
6     Unused translations found
7     Runtime failure (XML parse, I/O)
8     Catalogs need formatting
====  ===============================


Configuration
=============

All settings live under ``Two13Tec.L10nGuy``:

.. code-block:: yaml

   Two13Tec:
     L10nGuy:
       # File discovery patterns
       filePatterns:
         includes:
           - name: php
             pattern: 'Classes/**/*.php'
             enabled: true
           - name: fusion
             pattern: 'Resources/**/*.fusion'
             enabled: true
           - name: afx
             pattern: 'Resources/**/*.afx'
             enabled: true
           - name: nodeTypes
             pattern: 'NodeTypes/**/*.yaml'
             enabled: true
           - name: yaml
             pattern: 'Configuration/**/*.yaml'
             enabled: true
           - name: translations
             pattern: 'Resources/Private/Translations/**/*.xlf'
             enabled: true
         excludes:
           - name: node_modules
             pattern: '**/node_modules/**'
             enabled: true
           - name: build
             pattern: 'Build/**'
             enabled: true

       # Output defaults
       defaultFormat: 'table'
       tabWidth: 2
       orderById: false
       setNeedsReview: true

       # Scope defaults
       defaultLocales: []
       defaultPackages: []
       defaultPaths: []

       # LLM configuration
       llm:
         # Provider selection (required when using --llm)
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

         # Batching
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

           Domain context:
           [Describe your business domain here]

           Glossary:
           [Add key terms here]

       # Exit codes
       exitCodes:
         success: 0
         missing: 5
         unused: 6
         failure: 7
         dirty: 8


XLF Output Format
=================

Standard entries (without LLM)::

   <trans-unit id="hero.headline" xml:space="preserve">
     <source>Welcome to our platform</source>
     <target state="needs-review">Welcome to our platform</target>
   </trans-unit>

LLM-generated entries include metadata notes::

   <trans-unit id="hero.headline" xml:space="preserve">
     <source>Welcome to our platform</source>
     <target state="needs-review">Willkommen auf unserer Plattform</target>
     <note from="l10nguy" priority="1">llm-generated</note>
     <note from="l10nguy">provider:anthropic model:claude-sonnet-4-20250514 generated:2025-01-15T10:30:00Z</note>
   </trans-unit>

This enables:

* Filtering in translation tools (``state="needs-review"``)
* Audit trails (which model, when generated)
* Batch approval workflows


Value Objects & DTOs
====================

All DTOs are ``readonly`` with ``#[Flow\Proxy(false)]`` to prevent Flow from
proxying them. This keeps instantiation fast and ensures immutability.


Core DTOs
---------

``TranslationReference``
   Represents a single ``I18n.translate()`` call site::

      final readonly class TranslationReference
      {
          public function __construct(
              public string $packageKey,
              public string $sourceName,
              public string $identifier,
              public string $context,      // php | fusion | yaml
              public string $filePath,
              public int $lineNumber,
              public ?string $fallback = null,
              public array $placeholders = [],
              public bool $isPlural = false,
          ) {}
      }

``CatalogEntry``
   Represents an existing ``<trans-unit>`` in an XLF file::

      final readonly class CatalogEntry
      {
          public function __construct(
              public string $locale,
              public string $packageKey,
              public string $sourceName,
              public string $identifier,
              public string $filePath,
              public ?string $source = null,
              public ?string $target = null,
              public ?string $state = null,
          ) {}
      }

``MissingTranslation``
   Result DTO pairing a reference with the locale where the catalog entry is
   absent.

``PlaceholderMismatch``
   Result DTO describing drift between reference placeholders and catalog
   source/target placeholders.

``CatalogMutation``
   DTO used to express catalog mutations. Uses PHP 8.4 property hooks for
   normalisation. Extended with LLM metadata::

      final class CatalogMutation
      {
          public function __construct(
              public readonly string $locale,
              public readonly string $packageKey,
              public readonly string $sourceName,
              string $identifier = '',
              string $fallback = '',
              public array $placeholders = [],
          ) {}

          public string $identifier { get; set; }
          public string $fallback { get; set; }
          public string $source { get; set; }
          public string $target { get; set; }

          // LLM metadata
          public bool $isLlmGenerated { get; set; }
          public ?string $llmProvider { get; set; }
          public ?string $llmModel { get; set; }
          public ?\DateTimeImmutable $llmGeneratedAt { get; set; }
      }


LLM DTOs
--------

``LlmConfiguration``
   Captures all LLM-related settings for a translation run::

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
   Aggregated context for a single translation request::

      final readonly class TranslationContext
      {
          public function __construct(
              public ?string $sourceSnippet = null,
              public ?string $nodeTypeContext = null,
              public array $existingTranslations = [],
          ) {}
      }


Testing Strategy
================

Tests are split by scope:

Unit Tests
----------

``Tests/Unit/``
   Fast, isolated tests for collectors, parsers, and DTOs. Mock file contents
   inline or use minimal fixtures.

``Tests/Unit/Llm/``
   Tests for LLM components:

   - ``PromptBuilderTest`` — Prompt construction with various context
   - ``ResponseParserTest`` — JSON extraction from different formats
   - ``PlaceholderValidatorTest`` — Placeholder detection and validation
   - ``LlmProviderFactoryTest`` — Provider instantiation and errors


Functional Tests
----------------

``Tests/Functional/``
   Boot Flow in ``Testing`` context. Exercise commands against
   ``Tests/Fixtures/SenegalBaseline``, a trimmed mirror of ``Two13Tec.Senegal``
   with intentional gaps for regression testing.

``Tests/Functional/Command/LlmTranslationTest``
   Full pipeline tests with mocked LLM responses. Verifies:

   - Mutations are enriched with translations
   - XLF files contain correct metadata
   - Dry-run mode produces accurate estimates
   - ID filtering works correctly


Fixture Conventions
-------------------

* ``SenegalBaseline/Resources/Private/Fusion/`` contains snippets from the real
  site package (Cards component, YouTube alert).
* ``SenegalBaseline/Resources/Private/Translations/`` holds catalogs with
  intentional missing entries (``cards.authorPublishedBy`` removed) and unused
  entries (``cards.moreButton`` present but unreferenced).
* Tests assert CLI exit codes (``0/5/6/7/8``) and table/JSON payloads.


Extending L10nGuy
=================

Adding a New Collector
----------------------

1. Create a class implementing ``ReferenceCollectorInterface``.
2. Return ``true`` from ``supports()`` for file types you handle.
3. Parse the file and return ``TranslationReference`` DTOs.
4. Register the service in ``Configuration/Objects.yaml`` (Flow will
   auto-wire if constructor injection is used).

Example skeleton::

   final readonly class TwigReferenceCollector implements ReferenceCollectorInterface
   {
       public function supports(SplFileInfo $file): bool
       {
           return $file->getExtension() === 'twig';
       }

       public function collect(SplFileInfo $file): array
       {
           // Parse Twig, extract {% trans %} tags, return TranslationReference[]
       }
   }


Customising File Discovery
--------------------------

Add patterns to ``Two13Tec.L10nGuy.filePatterns`` in your site's
``Settings.yaml``::

   Two13Tec:
     L10nGuy:
       filePatterns:
         includes:
           - name: twig
             pattern: 'Resources/Private/Templates/**/*.twig'
             enabled: true

Disable a built-in pattern by setting ``enabled: false``.


Design Decisions
================

Why Regex for Fusion?
---------------------

Neos marks ``Neos\Fusion\Core\Parser`` as ``@internal``. Regex heuristics are
pragmatic and cover the translation helpers' limited syntax. Edge cases
(nested braces, escaped quotes) are handled with a lightweight state machine
in ``FusionReferenceCollector::splitArguments()``.


Why Readonly DTOs?
------------------

Immutable value objects prevent accidental mutations during pipeline
processing. The ``#[Flow\Proxy(false)]`` annotation avoids Flow's proxy
overhead, keeping object construction fast for large codebases.


Why Two-Space XML Indentation?
------------------------------

XLIFF files are hand-edited by translators. Two-space indentation balances
readability and diff size. The writer's deterministic formatting ensures CI
pipelines can detect unintentional changes via ``./flow l10n:format --check``.


Why Optional LLM Dependency?
----------------------------

Not all projects need automatic translation. Making ``symfony/ai-platform`` a
suggested dependency keeps the core package lightweight. The runtime check
provides a clear message when LLM features are requested but unavailable.


Why Batch Size 1 by Default?
----------------------------

Translating one ID at a time (with all target languages) provides:

* Better context per translation
* Easier retry on failure
* Consistent quality

Batch size > 1 is available for cost optimisation but may reduce quality.


Error Handling
==============

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


Known Limitations
=================

* **Plural forms**: Parsing of ``<group restype="x-gettext-plurals">`` is
  supported but reference-to-catalog matching currently treats plural base IDs
  as present if any child form exists. Per-form validation is not implemented.

* **Dynamic IDs**: References like ``I18n.translate('button.' . $action)``
  cannot be statically analysed. The helper will not detect these.

* **Non-standard XLF**: Only XLIFF 1.2 with Flow/Neos conventions is supported.
  Custom namespaces or XLIFF 2.0 files may not parse correctly.

* **Token estimation**: Heuristic-based (~4 characters per token). Actual
  usage may vary by model and content.

* **Streaming responses**: Not supported. Each translation waits for the
  full LLM response.

* **Cost tracking**: Not built-in. Use provider dashboards to monitor API
  usage and costs.


Further Reading
===============

* `Flow I18n documentation
  <https://flowframework.readthedocs.io/en/stable/TheDefinitiveGuide/PartIII/Internationalization.html>`_

* `XLIFF 1.2 specification
  <http://docs.oasis-open.org/xliff/xliff-core/xliff-core.html>`_

* `symfony/ai documentation <https://symfony.com/doc/current/ai/index.html>`_

* `OpenAI API reference <https://platform.openai.com/docs/api-reference>`_

* `Anthropic API reference <https://docs.anthropic.com/en/api>`_

* `Ollama documentation <https://ollama.ai/docs>`_
