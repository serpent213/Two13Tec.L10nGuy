# L10nGuy for Neos CMS

Flow CLI localisation companion that keeps Neos translation catalogs in sync with the actual usage inside Fusion, PHP and YAML sources.

## Features

- `./flow l10n:scan` – builds reference & catalog indexes, reports missing translations, warns about placeholder drift, and optionally writes new `<trans-unit>` entries grouped per locale/package/source. Optionally uses an LLM for heuristic translation.
- `./flow l10n:unused` – lists catalog entries that no longer have a matching reference; can delete unused nodes in place to keep XLFs tidy.
- `./flow l10n:format` – re-renders existing catalogs using the helper’s writer so indentation, attribute ordering, and trailing newlines stay consistent (supports `--check` for CI).
- `./flow l10n:translate` – bulk-translates missing entries to a target locale via LLM with dry-run estimation.
- Shared diagnostics: table or JSON output, deterministic exit codes for CI pipelines, detailed logging of XML parse errors, duplicates, and missing catalogs.
- Comprehensive fixtures + functional tests mirror real-life components

## Usage

Run the commands from the project root (defaults to `Development` context). Common options work for both commands:

```bash
./flow l10n:scan \
  --package Acme.Senegal \
  --locales de,en
```

```bash
./flow l10n:unused \
  --package Acme.Senegal
```

(Locales will be auto-detected by default, see [Configuration](#configuration) for details.)

- `./flow l10n:format --check` shares the same filters (`--package`, `--source`, `--path`, `--locales`) and exits non-zero when a catalog would be rewritten (without touching the files). Drop `--check` to re-render the catalogs in place.
- `--update` for `l10n:scan` creates missing entries via the catalog writer. Without `--update` the command is read-only. `--llm` translates missing entries as they are written; add `--dry-run` to estimate token usage and suppress catalog writes even when `--update` is set.
- `--delete` for `l10n:unused` removes unused `<trans-unit>` nodes. Without `--delete` the command only reports unused entries.
- `--quiet` suppresses the table output for `l10n:scan` / `l10n:unused`; `--quieter` hides all stdout output while still emitting warnings/errors on stderr.
- Exit codes: `0` clean, `5` missing translations, `6` unused translations (unless deleted), `8` catalogs need formatting (from `--check`), `7` fatal failure.

See `Documentation/Architecture.rst` for the full architecture guide, data model notes, and extension points.

Run `just docs` to generate an HTML version in `Documentation/_build/html`.

### LLM usage

```bash
# Scan, create missing entries, and translate them via the configured LLM
./flow l10n:scan --update --llm

# Preview the same run without touching catalogs; reports token estimates
./flow l10n:scan --update --llm --dry-run --package Acme.Senegal --locales fr

# Bulk-translate existing catalogs into a new locale
./flow l10n:translate fr --package Acme.Senegal --source Presentation.Cards
```

## Supported reference patterns

### PHP

```php
// Neos I18n helper
$this->translator->translateById(
    'button.submit',
    [],
    null,
    null,
    'Forms',
    'Acme.Senegal'
);

// Static shorthand
I18n::translate('Acme.Senegal:Forms:button.submit');
```

### Fusion / AFX

```fusion
// Classic syntax
label = ${I18n.translate('button.submit', 'Submit', {}, 'Forms', 'Acme.Senegal')}

// Fluent syntax
label = ${Translation.id('button.submit').package('Acme.Senegal').source('Forms').value('Submit')}
```

### YAML (NodeTypes)

```yaml
'Acme.Senegal:Content.Card':
  ui:
    label: 'i18n'                # Detected as package:NodeTypes.Content.Card:label
    inspector:
      groups:
        card:
          label: 'i18n'          # Detected as package:NodeTypes.Content.Card:groups.card
```

## Configuration

The helper ships sane defaults in [`Configuration/Settings.L10nGuy.yaml`](Configuration/Settings.L10nGuy.yaml). Adjust them to fit your project:
- Update `Two13Tec.L10nGuy.filePatterns` to add/remove include/exclude globs (e.g., `Resources/Private/Templates/**/*.html`). Disable an existing preset by setting `enabled: false` so future upgrades merge cleanly.
- `Two13Tec.L10nGuy.defaultLocales` lets you pin the locale fallback order without editing Flow’s global `Neos.Flow.i18n` settings. The resolver priority is `--locales` CLI argument → helper `defaultLocales` → `Neos.Flow.i18n.defaultLocale` plus its `fallbackRule.order`, so you can keep Flow’s defaults untouched while giving the helper an opinionated scope.
- `Two13Tec.L10nGuy.defaultPackages` and `defaultPaths` provide helper-specific fallbacks when the CLI doesn’t pass `--package`/`--path`. For example:

  ```yaml
  Two13Tec:
    L10nGuy:
      defaultPackages:
        - Acme.Senegal
        - Partner.Site
      # Alternatively:
      # defaultPaths:
      #   - "DistributionPackages/Acme.Senegal"
  ```

  With that config `./flow l10n:scan` defaults to the Senegal package but you can still override it via CLI flags.
- `Two13Tec.L10nGuy.tabWidth` controls how many spaces the catalog writer uses per indentation level (default `2`). Bump it if your team prefers wider XML indentation.
- `Two13Tec.L10nGuy.orderById` (default `false`) makes the writer sort `<group>` and `<trans-unit>` elements by translation id throughout the document. By default it preserves the original catalog order when writing or reformatting.
- `Two13Tec.L10nGuy.newState` / `Two13Tec.L10nGuy.newStateQualifier` define the `state` / `state-qualifier` attributes written on newly created entries (applies to source elements when writing the source locale, otherwise targets). Set them to `null` to skip the attributes entirely.

Neos/Flow configuration conventions apply, so you can override these keys per context (`Settings.Development.yaml`, etc.) without touching the distributed defaults.

### LLM configuration

```yaml
Two13Tec:
  L10nGuy:
    llm:
      provider: openai
      model: gpt-4.1-mini
      api_key: '%env(OPENAI_API_KEY)%'

      systemPrompt: |
        You are translating a Neos CMS website for a commercial mushroom
        cultivation supplies company.

        ## Domain Glossary
        These terms have specialist meanings—translate consistently and never
        confuse them with their everyday or computing homonyms:
        - "substrate" – the nutrient-rich growing medium (NOT a computing term)
        - "spawn" – mycelium starter material (NOT game/computing spawn)
        - "flush" – a single harvest cycle (NOT cleaning or cache flush)
        - "pins" – tiny mushroom primordia (NOT metal pins or map markers)
        - "fruiting" – the phase when mushrooms emerge
        - "casing" – a moisture-retaining top layer (NOT a phone case)
        - "FAE" – Fresh Air Exchange; keep the acronym, explain on first use
        - "canopy" – the dense top surface of mature mushrooms in a flush
        - "colonisation" – mycelium spreading through substrate

        ## Brand Names (never translate)
        - "MycoMax Pro" (substrate product line)
        - "SpawnMaster 3000" (inoculation equipment)
        - "GrowTek" (the company name)

        ## Tone Guidelines
        - Product descriptions: professional, informative, subtly enthusiastic
        - Growing guides: clear, step-by-step, encouraging for beginners
        - Error messages: helpful and specific; avoid jargon
        - Customer support copy: warm, patient, solution-focused

        ## Audience
        A mix of hobby growers and commercial cultivators. Default to
        accessible language; use technical terms only where context demands.

        ## Formality
        Use formal address in languages that distinguish it (e.g. "Sie" in
        German, "vous" in French, "usted" in Spanish).
```

`--llm-provider` / `--llm-model` override the configured defaults per run. `--systemPrompt` is not a CLI flag—set it in configuration when you need to steer tone or add policy notes. Combine `--llm` with `--dry-run` to skip API calls and avoid writes even if `--update` is supplied.

> **Note:** The source language for LLM translations is hardcoded to `en` (English). When translating to multiple locales, the LLM-polished English text is propagated to target-language catalogs' `<source>` elements so translators see source text alongside the translations.

## Development workflow

Inside `DistributionPackages/Two13Tec.L10nGuy` you can use the bundled `just` targets (ensure the project dev shell is active first):

```bash
# Format everything via treefmt
XDG_CACHE_HOME=$PWD/.cache just format

# Run formatting checks + php -l (manual phpstan when config available)
XDG_CACHE_HOME=$PWD/.cache just lint

# Execute unit + functional tests against the Senegal fixtures
XDG_CACHE_HOME=$PWD/.cache just test
```

Unit test fixtures live under `Tests/Fixtures/SenegalBaseline`; they are mirrored into Flow’s `Data/Temporary` folder for functional tests, so you can edit them freely and re-run the suites to simulate real catalog changes.

## Requirements

- PHP 8.4+
- Flow/Neos distribution bootstrapped via `composer install`; we only use Flow’s CLI, DI attributes, and XLIFF provider APIs available since Flow 8, so any Flow/Neos version that supports PHP 8.4 should work (no Flow 9–only features).

  See [GitHub Actions](https://github.com/serpent213/Two13Tec.L10nGuy/actions) for tested versions in CI.
- Translations stored in standard Flow/Neos `Resources/Private/Translations/<locale>/…` paths so the catalog writer can resolve files.
- LLM functionality is optional. `composer require --dev --update-with-all-dependencies php-llm/llm-chain` to install the required dependencies.
