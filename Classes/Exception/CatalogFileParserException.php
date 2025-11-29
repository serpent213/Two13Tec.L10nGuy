<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Exception;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

class CatalogFileParserException extends \RuntimeException
{
    public static function becauseUnreadable(string $filePath): self
    {
        return new self(sprintf('Unable to read catalog file "%s".', $filePath));
    }

    public static function becauseEmpty(string $filePath): self
    {
        return new self(sprintf('Catalog file "%s" is empty.', $filePath));
    }

    public static function becauseMalformed(string $filePath, string $reason = ''): self
    {
        $suffix = trim($reason) === '' ? '' : sprintf(' (%s)', trim($reason));

        return new self(sprintf('Catalog file "%s" contains malformed XML%s.', $filePath, $suffix));
    }
}
