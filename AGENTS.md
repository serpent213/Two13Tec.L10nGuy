# Repository Guidelines

## Project Structure & Module Organization
Core PHP services, CLI commands, and DTOs live under `Classes/` using PSR-4 (`Two13Tec\L10nGuy\…`). Flow and Neos settings or command wiring go in `Configuration/`. Architecture documentation lives in `Documentation/` following Flow/Neos Sphinx conventions. Tests are grouped by type inside `Tests/Unit`, `Tests/Functional`, and reusable fixtures under `Tests/Fixtures/SenegalBaseline`, which mirror the production `Two13Tec.Senegal` package so behaviour stays realistic. If you need project automation, check `justfile` and `treefmt.toml` at the package root.

## Build, Test, and Development Commands
- `composer install` (run in the distribution root) brings Flow + dev tools.
- `XDG_CACHE_HOME=$PWD/.cache just format` runs treefmt with php-cs-fixer + prettier.
- `XDG_CACHE_HOME=$PWD/.cache just lint` enforces formatting, `php -l`, and optional phpstan when `phpstan.neon` is present.
- `XDG_CACHE_HOME=$PWD/.cache just test` spawns Flow’s phpunit suites (`FLOW_CONTEXT=Testing`) for Unit and Functional directories.
- `./flow l10n:scan` / `./flow l10n:unused` (from repo root) exercise the commands against the Senegal fixtures; writes only occur when `--update` or `--delete` is passed.

ALWAYS run `just format`, `just lint` and `just test` in this project root folder after making chnges (and it between as required).

## Coding Style & Naming Conventions
Stick to modern PHP 8.4 with `declare(strict_types=1);`, four-space indentation, and promoted constructor properties for value objects. Prefer descriptive DTO names (`TranslationReference`, `CatalogEntry`) and suffix collectors or services by responsibility (`PhpReferenceCollector`). Translation IDs use `package:source:id` or `source.identifier` per Flow conventions. Formatting is automated by treefmt running php-cs-fixer (config in `../../.php-cs-fixer.php`) and prettier for YAML/composer files; never bypass it before opening a PR.

## Testing Guidelines
Fast logic belongs in `Tests/Unit`, while end-to-end Flow boots reside in `Tests/Functional`. Reference fixtures in `Tests/Fixtures/SenegalBaseline` and copy them when mutating expected catalog content. Name tests `*Test.php` and assert CLI exit codes (`0/5/6/7`) plus table/json payloads so regressions surface in CI. Always run `just test` before pushing, or scope with phpunit filters when iterating (`FLOW_CONTEXT=Testing ./bin/phpunit --filter TranslationReferenceTest` from repo root).

## Commit & Pull Request Guidelines
The git history shows Conventional Commit headers (`feat:`, `fix:`, `refactor:`), so follow that format, keep scopes short, and wrap bodies at ~72 chars. Each PR should describe the problem, solution, and testing (command outputs, fixture notes). Link Flow issues or Notion specs when relevant, attach screenshots or sample CLI output for UX/log formatting changes, and mention whether catalogs were touched so reviewers can diff XLF updates deliberately.
