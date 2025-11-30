<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Llm;

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
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;

/**
 * Extracts surrounding source lines for a translation reference.
 */
#[Flow\Scope('singleton')]
final class SourceContextExtractor
{
    public function extract(TranslationReference $reference, int $windowLines): ?string
    {
        if ($windowLines <= 0) {
            return null;
        }

        if (!is_file($reference->filePath)) {
            return null;
        }

        $lines = @file($reference->filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $lineIndex = max(0, $reference->lineNumber - 1);
        $start = max(0, $lineIndex - $windowLines);
        $end = min(count($lines), $lineIndex + $windowLines + 1);

        if ($start >= $end) {
            return null;
        }

        $snippet = array_slice($lines, $start, $end - $start);

        return implode("\n", $snippet);
    }
}
