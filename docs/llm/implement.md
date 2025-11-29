# Flow i18n Helper – Multi-phase Implementation Plan

## Phase 1 – Domain scaffolding & Senegal fixtures
- Scaffold Flow CLI command classes under `Two13Tec\L10nGuy\Command\` (`LocalizationScanCommand`, `LocalizationUnusedCommand`) plus shared services (`ScanConfigurationFactory`, `FileDiscoveryService`, DTOs described in the brief).
- Register configuration defaults under `Two13Tec.L10nGuy.*` (file patterns, default format, exit codes) and expose Flow settings injection.
- Create trimmed `Tests/Fixtures/SenegalBaseline` tree mirroring the PRD sources (Fusion snippets, NodeTypes YAML, locales `de/en` catalogs, `Settings.Flow.yaml`).
- Wire a base functional test case that boots Flow with the fixture package mounted as `Two13Tec.Senegal` to keep later tests lean.

**Tests**
- Unit-test `ScanConfigurationFactory` to prove it merges CLI options with `Neos.Flow.i18n.*` (e.g., `--locales` overrides `defaultLocale + fallbackRule`).
- Functional smoke test asserting the fixture package registers and Flow sees the `Two13Tec.Senegal` package (guards against missing fixture wiring).

## Phase 2 – Reference scanners
- Implement `PhpReferenceCollector` using `nikic/php-parser` tolerant mode. Detect `I18n::translate`, `$translator->translateById`, helper facades, and fully-qualified ids with package/source embedded. Normalize to `TranslationReference` DTOs capturing placeholders, fallback text, and file/line metadata.
- Implement `FusionReferenceCollector` using regex heuristics to catch `{I18n.translate(...)}`, fluent `Translation.id('…')`, and inline props structures. Support placeholder argument extraction and context flag = `fusion`.
- Implement `YamlReferenceCollector` built atop Symfony YAML parser. Traverse NodeTypes metadata, deriving sources from the path (e.g., `NodeTypes/Content/ContactForm` → `NodeTypes.Content.ContactForm`) and use property/group keys for IDs.
- Build `ReferenceIndexBuilder` that runs file discovery + collectors, deduplicates IDs per package/source/id, and surfaces duplicates for diagnostics.

**Tests**
- Unit tests for each collector using fixture snippets (cards fusion, YouTube fusion, PHP helper stub) to assert we capture ids, package/source resolution, placeholders, and fallback text. Include cases for fully-qualified IDs (`Package:Source:id`) and ensure duplicates increment the diagnostic counter.
- YAML collector tests verifying NodeTypes paths map to correct source + IDs and that `label: i18n` entries produce references.
- Reference index tests to ensure deduplication retains all call sites for reporting.

## Phase 3 – Catalog indexing & writers
- Integrate `Neos\Flow\I18n\Xliff\Service\XliffFileProvider` to load catalogs into `CatalogEntry` DTOs keyed by locale/package/source/id.
- Implement `CatalogIndexBuilder` that respects locales produced in Phase 1, handles nested directories (`Presentation/Cards.xlf`, `NodeTypes/Document/Page.xlf`), and tracks file paths + XML metadata (product-name, target-language).
- Implement `CatalogMutation` DTO with PHP 8.4 property hooks to normalize ids before writes and to copy fallback strings into new `source/target` nodes.
- Implement `CatalogWriter` that groups mutations by locale/package/source and writes deterministic two-space-indented XML.

**Tests**
- Unit tests for `CatalogIndexBuilder` verifying locales load from both default + fallback and that catalogs missing files raise diagnostics instead of crashing.
- Writer tests using temporary copies of fixture catalogs to ensure new `<trans-unit>` skeletons match PRD expectations (fallback duplicated into source/target, placeholders untouched).
- Regression-style test where `cards.moreButton` remains in catalogs but lacks references so that `CatalogIndex` faithfully reports it for the `unused` command.

## Phase 4 – `l10n:scan` command & reporting
- Build the scan command flow: discover references → build catalog index → diff → emit `MissingTranslation` DTOs plus placeholder mismatch warnings → render via CLI table or JSON (shared renderer service).
- Implement exit codes (`0` clean, `5` missing, `7` runtime failure) and propagate diagnostics (duplicates, placeholder mismatches) in human-friendly text.
- Add support for `--package/--source/--path/--locales/--format` options per spec and ensure `--update` is routed to catalog writer.
- Connect warnings/errors to Flow logging and ensure JSON output matches schema in PRD.

**Tests**
- Functional tests running `./flow l10n:scan` against fixtures:
  - Missing entry detection for `cards.authorPublishedBy` across `de/en` with expected exit code `5`.
  - `--locales=en` restricts output to English only.
  - JSON output contains locale/package/source/id/issue plus placeholder arrays.
- Tests verifying placeholder mismatch warning triggers when catalog placeholders do not match reference placeholders (craft fixture variant).
- Test `--format=table` path to confirm columns align with sample (assert string contains header row).

## Phase 5 – Update & unused flows + diagnostics
- Extend `l10n:scan --update` to call `CatalogWriter` with grouped mutations and print touched files as it writes.
- Implement `LocalizationUnusedCommand` that reuses the reference index and catalog index to list entries absent from references, supports `--delete` (mutating catalogs) and `--format=table|json`, and exits with `6` when unused entries exist.
- Surface diagnostics for XML parse errors, duplicate IDs, and placeholder mismatches through both commands.
- Document CLI usage in `README.md`/docs referencing this plan.

**Tests**
- Functional test: `./flow l10n:scan --update --locales=de,en --package Two13Tec.Senegal` writes new entries and logs touched files; re-running `scan` should exit `0`.
- Functional test: `./flow l10n:unused --format=json` returns `cards.moreButton` and exit code `6`; rerun with `--delete` removes the entry and subsequent invocation exits `0`.
- Error-path tests (unit or integration) for malformed catalog XML and duplicate reference IDs to ensure exit code `7` and actionable messages.

This phased approach keeps the helper grounded in real Senegal data, ensures every PRD use case is backed by automated tests, and sequences the work so lower-level scanners/indexes mature before CLI UX and catalog mutations.
