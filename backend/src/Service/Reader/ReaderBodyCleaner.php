<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\BodyCleaningStepInterface;
use App\Service\Reader\BodyCleaning\Model\BodyCleaningInputModel;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Cleans readability's article HTML through one shared document: parse once, run the steps in the order
 * services.yaml lists them, serialise once for EntrySanitizer (#586, #684, #748). A body too broken to parse is
 * returned unchanged, so a degenerate readability output falls through instead of failing the extraction.
 */
final readonly class ReaderBodyCleaner
{
    /** @param iterable<BodyCleaningStepInterface> $steps */
    public function __construct(private iterable $steps)
    {
    }

    #[WithSpan]
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
