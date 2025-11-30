# Package automation for Two13Tec.L10nGuy

default:
	@just --list

# Build Sphinx documentation
docs:
	sphinx-build -b html Documentation Documentation/_build/html

# Apply auto-formatters
format:
	treefmt --config-file treefmt.toml

# Verify formatting and run lightweight linting
lint:
	#!/usr/bin/env bash
	set -euo pipefail
	treefmt --config-file treefmt.toml --fail-on-change
	PHP_FILES=$(find Classes Tests -name '*.php' -type f 2>/dev/null || true)
	if [ -z "$PHP_FILES" ]; then
	  echo "No PHP files detected for linting."
	  exit 1
	fi
	printf '%s\n' "$PHP_FILES" | xargs -r -n1 php -l >/dev/null
	if [ -f phpstan.neon ]; then
	  composer exec phpstan analyse --configuration=phpstan.neon
	fi
	echo "Lint checks completed."

# Execute package-specific tests
test:
	#!/usr/bin/env bash
	set -euo pipefail
	if [ -d Tests/Unit ]; then
	  (cd ../.. && FLOW_CONTEXT=Testing ./bin/phpunit \
	    --configuration=Build/BuildEssentials/PhpUnit/UnitTests.xml \
	    --testsuite=Unit \
	    DistributionPackages/Two13Tec.L10nGuy/Tests/Unit)
	else
	  echo "No unit tests defined for Two13Tec.L10nGuy."
	  exit 1
	fi
	if [ -d Tests/Functional ]; then
	  (cd ../.. && FLOW_CONTEXT=Testing ./bin/phpunit \
	    --configuration=Build/BuildEssentials/PhpUnit/FunctionalTests.xml \
	    --testsuite=Functional \
	    DistributionPackages/Two13Tec.L10nGuy/Tests/Functional)
	else
	  echo "No functional tests defined for Two13Tec.L10nGuy."
	  exit 1
	fi
