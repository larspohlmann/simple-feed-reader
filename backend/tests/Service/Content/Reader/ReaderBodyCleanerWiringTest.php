<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Reader;

use App\Service\Content\Reader\ArticleExtractor\ArticleExtractor;
use App\Service\Content\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Content\Reader\ArticleExtractor\PlayerPageExtractor;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\AudioPlayerNormalizer;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\AuthorBioSeparator;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\DuplicateBlockCollapser;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\EdgeBoilerplateTrimmer;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\FeedDimensionStamper;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\InBodyEmbedRewriter;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\LeadingEngagementCleaner;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\LeadingTitleRemover;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\LegalLinkListRemover;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\MediaOnlyLede;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\NavigationChromeTrimmer;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\PageMediaPlacement;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\PastedTextBreakRestorer;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\PlayerChromeCleaner;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\RecipeFactsCleaner;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\SlideshowInserter;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\SubstackPosterLink;
use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\TeaserPlayerInserter;
use App\Service\Content\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Content\Reader\Media\EmbedProviders;
use App\Service\Content\Reader\ReaderBodyCleaner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReaderBodyCleanerWiringTest extends KernelTestCase
{
    /** The order is behaviour. */
    private const array ORDER = [
        InBodyEmbedRewriter::class,
        SubstackPosterLink::class,
        PlayerChromeCleaner::class,
        NavigationChromeTrimmer::class,
        LeadingEngagementCleaner::class,
        LeadingTitleRemover::class,
        EdgeBoilerplateTrimmer::class,
        LegalLinkListRemover::class,
        SlideshowInserter::class,
        RecipeFactsCleaner::class,
        DuplicateBlockCollapser::class,
        PageMediaPlacement::class,
        TeaserPlayerInserter::class,
        AudioPlayerNormalizer::class,
        MediaOnlyLede::class,
        AuthorBioSeparator::class,
        FeedDimensionStamper::class,
        PastedTextBreakRestorer::class,
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
        self::assertInstanceOf(PlayerPageExtractor::class, $extractor);
        $extractor = new \ReflectionProperty(PlayerPageExtractor::class, 'inner')->getValue($extractor);
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
