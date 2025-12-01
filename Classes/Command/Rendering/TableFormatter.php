<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Command\Rendering;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Two13Tec\L10nGuy\Cli\Table\Table;
use Two13Tec\L10nGuy\Utility\PathResolver;

/**
 * Shared table styling and cell formatting utilities for CLI output.
 *
 * @Flow\Scope("singleton")
 */
class TableFormatter
{
    /**
     * Create a styled table with default colors
     */
    public function createStyledTable(): Table
    {
        $table = new Table();
        $table->setBorderStyle(Table::COLOR_BLUE);
        $table->setCellStyle(Table::COLOR_GREEN);
        $table->setHeaderStyle(Table::COLOR_RED, Table::BOLD);

        return $table;
    }

    /**
     * Format the source/ID cell with package, source name, and identifier
     */
    public function formatSourceCell(
        string $packageKey,
        string $sourceName,
        string $identifier,
        bool $hidePackagePrefix
    ): string {
        $sourceLine = $hidePackagePrefix ? $sourceName : sprintf('%s:%s', $packageKey, $sourceName);

        return implode(PHP_EOL, [
            $this->colorize($sourceLine, Table::COLOR_DARK_GRAY),
            $this->colorize($identifier, Table::ITALIC, Table::COLOR_LIGHT_YELLOW),
        ]);
    }

    /**
     * Format the file column with optional line number
     */
    public function formatFileColumn(Table $table, string $filePath, ?int $lineNumber = null): string
    {
        $relative = PathResolver::relativePath($filePath);
        $prefix = 'DistributionPackages/Two13Tec.Senegal/';
        if (str_starts_with($relative, $prefix)) {
            $relative = substr($relative, strlen($prefix));
        }

        $location = $relative;
        if ($lineNumber !== null) {
            $dataStyles = $table->dataStylesForColumn('File');
            $location .= Table::wrapWithStyles(':', [Table::COLOR_DARK_GRAY], $dataStyles);
            $location .= Table::wrapWithStyles((string)$lineNumber, [Table::COLOR_LIGHT_GRAY], $dataStyles);
        }

        return implode(PHP_EOL, ['', $location]);
    }

    /**
     * Format a translation cell value
     */
    public function formatTranslationCell(string $value): string
    {
        return implode(PHP_EOL, ['', $value]);
    }

    /**
     * Colorize text with ANSI escape codes
     */
    public function colorize(string $value, int ...$styles): string
    {
        if ($styles === []) {
            return $value;
        }

        return sprintf("\e[%sm%s\e[0m", implode(';', $styles), $value);
    }

    /**
     * Check if all package keys are the same
     *
     * @param array<string> $packageKeys
     */
    public function isSinglePackage(array $packageKeys): bool
    {
        return count(array_unique($packageKeys)) === 1;
    }
}
