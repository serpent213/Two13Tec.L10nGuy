<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Reference\Collector;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use SplFileInfo;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;

/**
 * Collector contract that turns a file into translation references.
 */
interface ReferenceCollectorInterface
{
    public function supports(SplFileInfo $file): bool;

    /**
     * @return list<TranslationReference>
     */
    public function collect(SplFileInfo $file): array;
}
