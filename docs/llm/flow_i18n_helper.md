# Two13Tec L10nGuy CLI – PRD

## Overview
- Provide Flow CLI tooling that inventories translation usages across PHP, Fusion, AFX, and YAML, compares them to existing XLF files, and optionally updates translation catalogs.
- Base implementation lives in Flow package `Two13Tec.L10nGuy` exposing CLI commands under `flow l10n:*`.
- Reuse Flow’s existing i18n services (`Neos\Flow\I18n\Service`, `Neos\Flow\I18n\Translator`, `Neos\Flow\I18n\Xliff\Service\XliffFileProvider`) to avoid re-implementing parsing, locale resolution, or XLF writing.

## Goals
- Detect every translation reference (Eel helper calls, Fusion directives, PHP translator usage, YAML `i18n` sections, etc.).
- Compare references to XLF sources per package/source file.
- Offer `--update` to add missing `<trans-unit>` entries with sensible defaults (id, source, target identical initially).
- Optionally list unused translations (present in XLF but not referenced anywhere).
- Good UX: clear CLI summary table (counts per file/package), actionable exit codes, dry-run default.

## Non-goals
- No automatic human translation; newly added units just mirror the fallback/source text.
- No IDE plugin or live watch mode (batch CLI only).
- No automatic deletion of unused translations (report only).

## User Stories
1. **Developer** runs `./flow l10n:scan` before committing to ensure every newly added `Translation.translate()` call has a matching XLF entry.
2. **Localization manager** runs `./flow l10n:scan --update` after a feature lands to pre-populate new `<trans-unit>` nodes for translators.
3. **Maintainer** runs `./flow l10n:unused --package Two13Tec.Senegal` to clean up XLF files before a release.

## CLI Commands
| Command | Description | Key Options |
|---------|-------------|-------------|
| `flow l10n:scan` | Parse configured source files, emit report of missing translations, exit non-zero if any missing unless `--silent`. | `--package`, `--source`, `--path`, `--format=table\|json`, `--update`, `--dry-run` (default true), `--locales`. |
| `flow l10n:unused` | Compare existing XLF files against the reference map built by `scan`; reports translations that no longer have references. | `--package`, `--source`, `--format`, `--locales`, `--delete`. |
| `flow l10n:merge` (alias of `scan --update`) | Scan and persist missing translations in-place. | Same as `scan`. |

### UX Notes
- Commands should describe which settings control include/exclude paths (reuse `Neos.Flow.i18n.scan.includePaths` and `excludePatterns`, defaulting to all package `Resources` + `Configuration`).
- When `--update` is set, show per-file diff summary (e.g. `Added 3 trans-unit entries to DistributionPackages/Two13Tec.Senegal/Resources/.../Main.en.xlf`).
- `--format=json` outputs full machine-readable data: list of references, missing units, duplicates, and file updates.
- For tabular console output (default view), use `initphp/cli-table` with a soft color palette (e.g. muted border + header styles) to keep readability high while still providing visual separation; install via `composer require initphp/cli-table`.
- Locale handling: by default commands operate on the project fallback locale (`Neos.Flow.i18n.defaultLocale`, or `en` if not set). Passing `--locales=de,fr` overrides this and processes each locale in the list sequentially (both for reporting and, with `--update`, for inserting placeholder entries into every specified catalog). Without `--locales`, non-default locales are ignored.

## Reference Detection
### File Discovery
- By default the tool scans only the package it lives in (resolved via Flow’s PackageManager) and walks include patterns declared under `Neos.Flow.i18n.helper.filePatterns.includes`. Defaults cover `Classes/**/*.php`, `Resources/**/*.fusion|afx`, and `Configuration/**/*.yaml`.
- Exclusions use the same pattern structure (`filePatterns.excludes`) to skip things like tests or caches.
- Patterns follow a `name => { pattern, enabled, priority }` convention, allowing project packages to add/disable entries without losing upstream defaults (Flow merges by name + priority).
- Use Flow’s `Neos\Utility\Files::readDirectoryRecursively` to gather files matching enabled include patterns minus excludes; CLI flags can add ad-hoc include/exclude globs for one-off runs.

### PHP
- Parse PHP via nikic/php-parser (already a Flow dependency) to find:
  - `Neos\Flow\I18n\Translator->translateById/translateByOriginalLabel`.
  - Static helpers: `TranslationHelper::translate`, `$this->translationService->translate`.
  - Strings tagged with `@translate` doc-block? (stretch goal, backlog).
- Extract arguments (id, source, package, fallback) and normalize into `{package, source, id, fallback}`.

### Eel / Fusion / AFX
- Stick to regex-based extraction because the only available parser (`Neos\Fusion\Core\Parser`) is explicitly `@internal` and therefore off-limits; heuristics must stay conservative and capture the entire helper expression for later normalization.
- Recognize:
  - `I18n.translate('Package:Source:Id', ...)`
  - `Translation.translate(...)`
  - Fluent usage: `Translation.id('foo').package('Bar').source('Baz').value('Fallback')`
- For shorthand usage with extra parameters we must still split `Package:Source:Id` manually (mirrors `TranslationHelper::I18N_LABEL_ID_PATTERN`).

### YAML / JSON
- Many Neos YAML configs use `label: 'i18n'` structures (especially NodeTypes). We must reproduce the exact logic Neos already uses:
  - Mirror `Neos\ContentRepositoryRegistry\Configuration\NodeTypeEnrichmentService` – generate the same prefix via `generateNodeTypeLabelIdPrefix()` and append config-specific segments via `getLabelTranslationId()` / `getConfigurationTranslationId()`. This guarantees parity with the runtime-generated ids such as `Two13Tec.Senegal:NodeTypes.Document.Blog.Folder.properties.title`.
  - If a YAML label already contains a shorthand string (e.g. `label: 'Two13Tec.Senegal:NodeTypes:custom'`), treat it as authoritative by parsing it with the service’s `splitIdentifier()` logic (package + optional source + id).
- Context-specific variants (inspector editors, creation dialog fields, inline editor options, etc.) already map to dedicated suffixes in the enrichment service (`creationDialog.*`, `inspector.editorOptions.*`, …); reusing the same generator automatically reflects those contexts without custom heuristics.
- Outside of NodeTypes, derive ids from the file-relative key path (`Vendor.Package:Config.<relative.path>`), but allow overrides via explicit shorthand strings.
- Support `i18n:` objects (e.g. `label: i18n`) by reading explicit `id/source/package` overrides when authors provide them.

## XLF Comparison & Update
- Use `Neos\Flow\I18n\Xliff\Service\XliffFileProvider` to resolve existing XLF files per `{package, source, locale}`.
- Missing entry handling:
  1. Determine locale (default fallback locale from settings or `en`).
  2. Generate `<trans-unit id="foo" resname="foo">` with `<source>` set to fallback text (or id if no fallback) and `<target state="needs-translation">` equal to source.
  3. Preserve formatting (indentation, newline) using Flow’s `XliffFileProvider` writer; avoid manual XML DOM to maintain compatibility.
- `--update` writes changes; without it, show command to run.
- Handle duplicates: report collisions when multiple files declare same id but different fallbacks.

## Unused Translation Detection
- For each `{package, source, locale}` XLF file, list `trans-unit` IDs and subtract reference set.
- Do not filter by `translate`, `state`, or other extended attributes—every `<trans-unit>` counts so reports stay exhaustive as per decision.
- Output grouped by file with counts and optionally `--format=json`.
- Optional `--delete` flag removes unused entries from their XLF files (after confirming the locale/file). Deletions happen per file/locale bundle with a summary of removed IDs; a `--dry-run` guard applies so users must opt-in to actual file writes (either via `--delete` together with `--dry-run=false` or by running `l10n:unused --delete --dry-run=false` explicitly).

## Configuration
- Settings path: `Neos.Flow.i18n.helper`.
  - `filePatterns.includes` / `filePatterns.excludes`: keyed pattern definitions with `pattern`, `enabled`, `priority`. Example:
    ```yaml
    Neos:
      Flow:
        i18n:
          helper:
            filePatterns:
              includes:
                sourceFiles:
                  pattern: 'Classes/**/*.php'
                  enabled: true
                  priority: 100
                configFiles:
                  pattern: 'Configuration/**/*.yaml'
                  priority: 50
              excludes:
                tests:
                  pattern: '**/*Test.php'
                  priority: 100
                caches:
                  pattern: 'Data/Temporary/**'
                  priority: 50
    ```
    `enabled` defaults to `true`; only set it when you need to disable a pattern. Projects can disable or override entries by redefining them (higher priority wins).
  - `yaml.idStrategy` (values: `auto`, `explicit`, custom class implementing `TranslationIdBuilderInterface`).
  - `defaultLocale` (fallback when Flow’s `Neos.Flow.i18n.defaultLocale` is missing).
  - `locales` (array) – default list of locales each command should process if `--locales` isn’t provided.
  - `sources` list to restrict scanning to certain XLF sources (e.g. `['Main', 'NodeTypes', 'Fusion']`).
- Commands accept CLI overrides that merge with settings (`--path`, `--include`, `--exclude`, `--locales`).

## Implementation Notes
- Package structure:
  - `Classes/Command/L10nCommandController.php` registering the Flow CLI commands.
  - `Classes/Scanner/*` components for each file type (PHP, Fusion, Yaml, GenericText).
  - `Classes/Model/TranslationReference.php` describing normalized references.
  - `Classes/Service/XliffUpdater.php` performing file modifications.
  - `Classes/Report/Formatter/TableFormatter`, `JsonFormatter`.
- No persistent cache layer; each command scans the filesystem on demand to keep behavior simple and deterministic.
- Adhere strictly to public/stable APIs (`Neos\Flow` and `Neos\ContentRepositoryRegistry` services); whenever a required capability is only exposed via `@internal` classes, fall back to our own parsing (e.g. regex for Fusion/Afx) instead of touching internal code.
- Tests:
  - Unit tests for each scanner using fixtures (Fusion, PHP, YAML).
  - Functional test running the command on `DistributionPackages/Two13Tec.Senegal` fixtures and asserting JSON output.

## Appendix: Data Structures & Algorithm Notes

### Core In-Memory Structures
- `TranslationReference` (immutable DTO):
  - Fields: `packageKey`, `source`, `id`, `fallback`, `path` (file + line), `context` (php|fusion|yaml|…).
  - Stored as associative array or small value object to ease JSON serialization.
- `ReferenceIndex`:
  - Map shape: `packageKey => source => id => TranslationReference`.
  - Allows O(1) lookups for both missing and unused checks.
- `CatalogIndex`:
  - Built per locale by reading XLF via `XliffFileProvider`.
  - Shape mirrors references but value is `TransUnitMetadata` with `id`, `source`, `target`, `filePath`.
- `Diagnostics` list collecting warnings (duplicate ids, malformed helper usage) rendered at the end.

### Missing-Translation Scan
1. Stream files (PHP/Fusion/AFX/YAML) and emit `TranslationReference` instances as soon as a hit is found.
2. Insert into `ReferenceIndex`, tracking duplicates by storing an array of references per id (for later warnings).
3. After scanning, iterate each reference and check existence in `CatalogIndex` for every locale selected (default is the fallback locale only; with `--locales` the loop runs for each entry). Complexity ~O(R) lookups with hash maps.
4. `--update` path groups missing references by `{package, source, locale}` to minimize file writes (one pass per XLF file per locale).

Performance considerations:
- File IO dominates; use generators/streaming to avoid loading huge files wholly when not needed (but YAML parsing may require entire document).
- Regex scanning for Fusion/AFX is linear in file size; compile patterns once per command.
- Avoid repeated locale loading by caching `CatalogIndex` per `{package, source, locale}` in memory during the command run.

### Unused-Translation Detection
1. Build `ReferenceIndex` (reuse from `l10n:scan` if the command is chained within same process; otherwise re-run scanners).
2. Determine locales to process (default fallback locale or `--locales` list) and build `CatalogIndex` per locale.
3. For each catalog entry, check if `ReferenceIndex[package][source][id]` exists:
   - If missing, mark as unused and include metadata (locale, file, state) in the report (and enqueue removal when `--delete` is active and not in dry-run).
4. Sorting: group output by package/source to keep UX readable.

Performance considerations:
- Catalog parsing cost proportional to total trans-units. For large projects, stream XLF XML with `XMLReader` via Flow’s XLIFF service to avoid DOM blow-ups.
- Keep unused detection O(C) by using hash lookups rather than searching reference arrays.
- Since no persistent cache exists, encourage users to scope commands via `--package`/`--path` to limit IO when needed (defaults already limit to the hosting package, but overrides may widen the scope).
