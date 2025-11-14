# Package automation for Neos.MetaData

default:
  @just --list

# Apply auto-formatters
format:
  treefmt --config treefmt.toml

# Verify formatting and run lightweight linting
lint:
  #!/usr/bin/env bash
  set -euo pipefail
  treefmt --config treefmt.toml --fail-on-change
  for dir in Classes Tests; do
    if [ -d "$dir" ]; then
      while IFS= read -r -d '' file; do
        php -l "$file" > /dev/null
      done < <(find "$dir" -name '*.php' -print0)
    fi
  done
  echo "Lint checks completed."

# Execute package-specific tests (if defined)
test:
  #!/usr/bin/env bash
  set -euo pipefail
  if [ -d Tests ]; then
    (cd ../.. && FLOW_CONTEXT=Testing ./bin/phpunit --configuration=Build/BuildEssentials/PhpUnit/UnitTests.xml -- DistributionPackages/Neos.MetaData/Tests) || exit 1
  else
    echo "No tests defined for Neos.MetaData."
  fi
