<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

use App\Entity\InterestPhrase;

/** The AI's phrases and the stems built from them, which are stored together. */
final readonly class ExtractedTermsModel
{
    /**
     * @param list<InterestPhrase> $phrases
     * @param list<TermStemModel> $stems
     */
    public function __construct(public array $phrases, public array $stems)
    {
    }
}
