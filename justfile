# Package automation for Two13Tec.L10nGuy

default:
	@just --list

# Apply auto-formatters
format:
	treefmt --config-file treefmt.toml

# Verify formatting and run lightweight linting
lint:
	#!/usr/bin/env bash
	set -euo pipefail
	treefmt --config-file treefmt.toml --fail-on-change
	PHP_FILES=$(rg --files -g '*.php' Classes Tests 2>/dev/null || true)
	if [ -z "$PHP_FILES" ]; then
	  echo "No PHP files detected for linting."
	  exit 0
	fi
	printf '%s\n' "$PHP_FILES" | xargs -r -n1 php -l >/dev/null
	if [ -f phpstan.neon ]; then
	  composer exec phpstan analyse --configuration=phpstan.neon
	fi
	echo "Lint checks completed."

# Execute package-specific tests (if defined)
test:
	#!/usr/bin/env bash
	set -euo pipefail
	if [ -d Tests ]; then
	  (cd ../.. && FLOW_CONTEXT=Testing ./bin/phpunit \
	    --configuration=Build/BuildEssentials/PhpUnit/UnitTests.xml \
	    -- DistributionPackages/Two13Tec.L10nGuy/Tests)
	else
	  echo "No tests defined for Two13Tec.L10nGuy."
	fi
