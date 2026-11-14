<?php

declare(strict_types=1);

namespace App\Service\Content\Reader;

use App\Service\Foundation\Html\Exception\UnparseableHtmlException;
use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\BodyCleaningStepInterface;
use App\Service\Content\Reader\BodyCleaning\Model\BodyCleaningInputModel;
use App\Service\Content\Reader\BodyCleaning\Pass\BodyCleaningPass;

/**
 * Cleans readability's article HTML through one shared document: parse once, run the steps in the order
 * services.yaml lists them, serialise once for EntrySanitizer. A body too broken to parse is returned unchanged, so
 * a degenerate readability output falls through instead of failing the extraction.
 */
final readonly class ReaderBodyCleaner
{
    /** @param iterable<BodyCleaningStepInterface> $steps */
    public function __construct(private iterable $steps)
    {
    }

    public function clean(string $contentHtml, BodyCleaningInputModel $input): string
    {
        try {
            $pass = new BodyCleaningPass(HtmlDocumentParser::parse($contentHtml), $input);
        } catch (UnparseableHtmlException) {
            return $contentHtml;
        }

        foreach ($this->steps as $step) {
            $step->cleanIn($pass);
        }

        return $pass->document->saveHtml();
    }
}
