.. _architecture:

============
Architecture
============

L10nGuy follows a pipeline architecture: discover files, collect translation
references, index existing catalogs, diff the two, and report or mutate. Each
stage is handled by dedicated services that can be tested and extended
independently.


System Overview
===============

The helper solves two related problems:

1. **Missing translations** -- ``I18n.translate()`` calls in code that have no
   matching ``<trans-unit>`` in the XLF catalogs.

2. **Unused translations** -- Catalog entries that no longer match any
   reference in the codebase (dead strings).

By comparing a *reference index* (what the code asks for) against a *catalog
index* (what XLF files provide), the helper produces actionable reports and
can optionally mutate catalogs to close the gap.


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
            └──► CatalogWriter (if --update or --delete)


Key Components
==============

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


Catalog Mutation
----------------

``CatalogMutationFactory``
   Creates ``CatalogMutation`` DTOs describing additions or removals.

``CatalogWriter``
   Groups mutations by locale/package/source, reads the existing catalog,
   applies mutations, and writes deterministic two-space-indented XML. Handles
   plural groups (``<group restype="x-gettext-plurals">``) when present.

Writer guarantees:

* Attribute ordering is consistent (``id``, ``xml:space``, then others).
* Trans-units are sorted naturally by ID when ``orderById`` is enabled.
* Round-trip safe: re-rendering a catalog without mutations produces
  byte-identical output.


Command Surface
---------------

``L10nCommandController`` orchestrates the pipeline::

   ./flow l10n:scan   [--package ...] [--locales ...] [--update] [--format table|json]
   ./flow l10n:unused [--package ...] [--locales ...] [--delete] [--format table|json]
   ./flow l10n:format [--check]

Exit codes are configurable via ``Two13Tec.L10nGuy.exitCodes``:

====  ===============================
Code  Meaning
====  ===============================
0     Clean (no issues)
5     Missing translations found
6     Unused translations found
7     Runtime failure (XML parse, I/O)
8     Catalogs need formatting
====  ===============================


Value Objects & DTOs
====================

All DTOs are ``readonly`` with ``#[Flow\Proxy(false)]`` to prevent Flow from
proxying them. This keeps instantiation fast and ensures immutability.

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
              public ?string $state = null,  // new | translated | needs-review
          ) {}
      }

``MissingTranslation``
   Result DTO pairing a reference with the locale where the catalog entry is
   absent.

``PlaceholderMismatch``
   Result DTO describing drift between reference placeholders and catalog
   source/target placeholders.


Testing Strategy
================

Tests are split by scope:

``Tests/Unit/``
   Fast, isolated tests for collectors, parsers, and DTOs. Mock file contents
   inline or use minimal fixtures.

``Tests/Functional/``
   Boot Flow in ``Testing`` context. Exercise commands against
   ``Tests/Fixtures/SenegalBaseline``, a trimmed mirror of ``Two13Tec.Senegal``
   with intentional gaps for regression testing.

Fixture conventions:

* ``SenegalBaseline/Resources/Private/Fusion/`` contains snippets from the real
  site package (Cards component, YouTube alert).
* ``SenegalBaseline/Resources/Private/Translations/`` holds catalogs with
  intentional missing entries (``cards.authorPublishedBy`` removed) and unused
  entries (``cards.moreButton`` present but unreferenced).
* Tests assert CLI exit codes (``0/5/6/7``) and table/JSON payloads.


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
         twig:
           pattern: 'Resources/Private/Templates/**/*.twig'
           enabled: true
           priority: 100

Disable a built-in pattern by setting ``enabled: false``; priority controls
processing order when multiple patterns match the same file.


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


Known Limitations
=================

* **Plural forms**: Parsing of ``<group restype="x-gettext-plurals">`` is
  supported but reference-to-catalog matching currently treats plural base IDs
  as present if any child form exists. Per-form validation is not implemented.

* **Dynamic IDs**: References like ``I18n.translate('button.' . $action)``
  cannot be statically analysed. The helper will not detect these.

* **Non-standard XLF**: Only XLIFF 1.2 with Flow/Neos conventions is supported.
  Custom namespaces or XLIFF 2.0 files may not parse correctly.


Further Reading
===============

* `Flow I18n documentation
  <https://flowframework.readthedocs.io/en/stable/TheDefinitiveGuide/PartIII/Internationalization.html>`_

* `XLIFF 1.2 specification
  <http://docs.oasis-open.org/xliff/xliff-core/xliff-core.html>`_

* `README.md <https://github.com/serpent213/Two13Tec.L10nGuy#readme>`_ for
  CLI usage and configuration reference.
