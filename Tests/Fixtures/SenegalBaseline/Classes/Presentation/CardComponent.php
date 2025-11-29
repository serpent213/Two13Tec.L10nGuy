<?php

declare(strict_types=1);

namespace Two13Tec\Senegal\Presentation;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\I18n;
use Neos\Flow\I18n\Translator;

final class CardComponent
{
    public function __construct(private Translator $translator)
    {
    }

    public function render(string $authorName): string
    {
        $cta = I18n::translate(
            'cards.authorPublishedBy',
            'Published by {authorName}',
            ['authorName' => $authorName],
            'Presentation.Cards:cards',
            'Two13Tec.Senegal'
        );

        $alert = $this->translator->translateById(
            'Two13Tec.Senegal:NodeTypes.Content.YouTube:error.no.videoid',
            [],
            null,
            null,
            'NodeTypes.Content.YouTube',
            'Two13Tec.Senegal'
        );

        return $cta . $alert;
    }
}
