<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ArticleExtractor\ArticleExtractor;
use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\AuthorBioSeparator;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\PageMediaPlacement;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\DuplicateBlockCollapser;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\EdgeBoilerplateTrimmer;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\FeedDimensionStamper;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\LeadingEngagementCleaner;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\LeadingTitleRemover;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\InBodyEmbedRewriter;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\SubstackPosterLink;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\TeaserPlayerInserter;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\MediaOnlyLede;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\NavigationChromeTrimmer;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\PlayerChromeCleaner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\RecipeFactsCleaner;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\SlideshowInserter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReaderBodyCleanerWiringTest extends KernelTestCase
{
    /** The call sequence ReaderBodyCleaner::clean() hard-coded before #1163. The order is behaviour. */
    private const array ORDER = [
        InBodyEmbedRewriter::class,
        SubstackPosterLink::class,
        PlayerChromeCleaner::class,
        NavigationChromeTrimmer::class,
        LeadingEngagementCleaner::class,
        LeadingTitleRemover::class,
        EdgeBoilerplateTrimmer::class,
        SlideshowInserter::class,
        RecipeFactsCleaner::class,
        DuplicateBlockCollapser::class,
        PageMediaPlacement::class,
        TeaserPlayerInserter::class,
        MediaOnlyLede::class,
        AuthorBioSeparator::class,
        FeedDimensionStamper::class,
    ];

    public function testTheWiredStepsRunInTheOrderTheCleanerHardCoded(): void
    {
        self::assertSame(self::ORDER, $this->classesOf($this->wiredSteps()));
    }

    public function testTheTestsHandBuiltPipelineMatchesTheWiring(): void
    {
        $steps = ReaderBodyCleanerTest::steps(new EmbedProviders([new YouTubeEmbedProvider()]));

        self::assertSame(self::ORDER, $this->classesOf($steps));
    }

    /** @return iterable<mixed> */
    private function wiredSteps(): iterable
    {
        self::bootKernel();
        $extractor = self::getContainer()->get(ArticleExtractorInterface::class);
        self::assertInstanceOf(ArticleExtractor::class, $extractor);
        $cleaner = new \ReflectionProperty(ArticleExtractor::class, 'bodyCleaner')->getValue($extractor);
        self::assertInstanceOf(ReaderBodyCleaner::class, $cleaner);
        $steps = new \ReflectionProperty(ReaderBodyCleaner::class, 'steps')->getValue($cleaner);
        self::assertIsIterable($steps);

        return $steps;
    }

    /**
     * @param iterable<mixed> $steps
     *
     * @return list<string>
     */
    private function classesOf(iterable $steps): array
    {
        $classes = [];
        foreach ($steps as $step) {
            self::assertIsObject($step);
            $classes[] = $step::class;
        }

        return $classes;
    }
}
