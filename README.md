# L10nGuy for Neos CMS

Flow CLI companion that keeps Neos translation catalogs in sync with the actual usage inside Fusion, PHP and YAML sources.

## Features

- `./flow l10n:scan` – builds reference & catalog indexes, reports missing translations, warns about placeholder drift, and optionally writes new `<trans-unit>` entries grouped per locale/package/source.
- `./flow l10n:unused` – lists catalog entries that no longer have a matching reference; can delete unused nodes in place to keep XLFs tidy.
- `./flow l10n:format` – re-renders existing catalogs using the helper’s writer so indentation, attribute ordering, and trailing newlines stay consistent (supports `--check` for CI).
- Shared diagnostics: table or JSON output, deterministic exit codes for CI pipelines, detailed logging of XML parse errors, duplicates, and missing catalogs.
- Comprehensive fixtures + functional tests mirror real-life components

## Usage

Run the commands from the project root (defaults to `Development` context). Common options work for both commands:

```bash
./flow l10n:scan \
  --package Two13Tec.Senegal \
  --path DistributionPackages/Two13Tec.Senegal \
  --locales de,en \
  --format table|json
```

```bash
./flow l10n:unused \
  --package Two13Tec.Senegal \
  --path DistributionPackages/Two13Tec.Senegal \
  --locales de,en \
  --format table|json
```

- `./flow l10n:format --check` shares the same filters (`--package`, `--source`, `--path`, `--locales`) and exits non-zero when a catalog would be rewritten (without touching the files). Drop `--check` to re-render the catalogs in place.
- `--update` for `l10n:scan` creates missing entries via the catalog writer. Without `--update` the command is read-only.
- `--delete` for `l10n:unused` removes unused `<trans-unit>` nodes. Without `--delete` the command only reports unused entries.
- Exit codes: `0` clean, `5` missing translations, `6` unused translations (unless deleted), `8` catalogs need formatting (from `--check`), `7` fatal failure.

See `docs/llm/flow_i18n_helper.md` for the full implementation brief, data model notes, and fixture descriptions.

## Configuration presets

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

Neos/Flow configuration conventions apply, so you can override these keys per context (`Settings.Development.yaml`, etc.) without touching the distributed defaults.

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

- PHP 8.4 (provided via the repo’s Nix/devshell setup).
- Flow/Neos distribution bootstrapped via `composer install`.
- Translations stored in standard Flow/Neos `Resources/Private/Translations/<locale>/…` paths so the catalog writer can resolve files.
