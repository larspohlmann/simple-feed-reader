<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** The files #1171 has yet to sweep, grouped by the PR that sweeps them; each PR deletes its own group. */
final class UnsweptCommentFiles
{
    public const array FILES = [
        // V
        'tests/Controller/Api/RecommendationRunControllerTest.php',
        'tests/Controller/Api/RecommendationRunHistoryControllerTest.php',
        'tests/Controller/Api/RecommendationSettingsControllerTest.php',
        'tests/Repository/RecommendationItemRepositoryTest.php',
        'tests/Repository/RecommendationRunHistoryRepositoryTest.php',
        'tests/Repository/RecommendationRunRepositoryTest.php',
        'tests/Service/Recommendation/Feed/Model/MonthWindowModelTest.php',
        'tests/Service/Recommendation/Feed/RecommendationForYouSummaryProviderTest.php',
        'tests/Service/Recommendation/Prompt/Factory/RecommendationCompletionRequestFactoryTest.php',
        'tests/Service/Recommendation/Prompt/RecommendationAnswerBudgetTest.php',
        'tests/Service/Recommendation/Prompt/RecommendationCandidateLoaderTest.php',
        'tests/Service/Recommendation/Prompt/RecommendationConsolidationParserTest.php',
        'tests/Service/Recommendation/Prompt/RecommendationPickParserTest.php',
        'tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php',
        'tests/Service/Recommendation/Run/DueRecommendationRunFinderTest.php',
        'tests/Service/Recommendation/Run/ForYouSweepTest.php',
        'tests/Service/Recommendation/Run/Pass/RecordedCallTest.php',
        'tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php',
        'tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php',
        'tests/Service/Recommendation/Run/RecommendationDrainSpawnerTest.php',
        'tests/Service/Recommendation/Run/RecommendationPipelineTest.php',
        'tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php',
        'tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php',
        'tests/Service/Recommendation/Run/RecommendationRunPurgerTest.php',
        'tests/Service/Recommendation/Run/RecommendationRunStarterTest.php',
        'tests/Service/Recommendation/Run/SweepStreamHeartbeatTest.php',
        'tests/Service/Recommendation/Run/TickLockKeepaliveTest.php',
        'tests/Service/Recommendation/Run/WorkerPresenceTest.php',
        'tests/Service/Recommendation/Settings/RecommendationSettingsWriterTest.php',
    ];
}
