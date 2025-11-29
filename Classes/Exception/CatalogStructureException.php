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

final class CatalogStructureException extends CatalogFileParserException
{
    public static function becauseUnsupportedGroupNodes(string $filePath): self
    {
        if (defined('FLOW_PATH_ROOT')) {
            $filePath = ltrim(str_replace(FLOW_PATH_ROOT, '', $filePath), '/');
        }

        return new self(sprintf(
            'Catalog "%s" contains group nodes that are currently unsupported.',
            $filePath
        ));
    }
}
