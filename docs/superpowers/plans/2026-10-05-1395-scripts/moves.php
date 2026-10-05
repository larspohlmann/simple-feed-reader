<?php

declare(strict_types=1);

// #1395 Task 5: old FQCN => new FQCN, for move-classes.php, compare-moves.php, compare-import-lines.php and
// stale-names.php (run from backend/). Spec D6's neutral names for the engine side; System One keeps its own.

return [
    'App\Service\Recommendation\Jev\Factory\JevStateFactory'
        => 'App\Service\Recommendation\Scoring\Factory\ScoringStateFactory',
    'App\Service\Recommendation\Jev\Factory\SystemOneRequestFactory'
        => 'App\Service\Recommendation\Scoring\Factory\SystemOneRequestFactory',
    'App\Service\Recommendation\Jev\JevBatchPacker'
        => 'App\Service\Recommendation\Scoring\ScoringBatchPacker',
    'App\Service\Recommendation\Jev\JevBatchWave'
        => 'App\Service\Recommendation\Scoring\ScoringBatchWave',
    'App\Service\Recommendation\Jev\JevRecommendationEngine'
        => 'App\Service\Recommendation\Scoring\ScoringRecommendationEngine',
    'App\Service\Recommendation\Jev\Model\NoulParseResultModel'
        => 'App\Service\Recommendation\Scoring\Model\ScoreParseResultModel',
    'App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel'
        => 'App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel',
    'App\Service\Recommendation\Jev\Model\SystemOneReplyModel'
        => 'App\Service\Recommendation\Scoring\Model\ScoringReplyModel',
    'App\Service\Recommendation\Jev\Model\SystemOneRequestModel'
        => 'App\Service\Recommendation\Scoring\Model\SystemOneRequestModel',
    'App\Service\Recommendation\Jev\NoulReplyParser'
        => 'App\Service\Recommendation\Scoring\ScoreParser',
    'App\Service\Recommendation\Jev\Pass\JevWave'
        => 'App\Service\Recommendation\Scoring\Pass\ScoringWave',
    'App\Service\Recommendation\Jev\Pass\SystemOneWave'
        => 'App\Service\Recommendation\Scoring\Pass\ResponseWave',
    'App\Service\Recommendation\Jev\Support\FittingPrefix'
        => 'App\Service\Recommendation\Scoring\Support\FittingPrefix',
    'App\Service\Recommendation\Jev\Support\JevArticle'
        => 'App\Service\Recommendation\Scoring\Support\ScoringArticle',
    'App\Service\Recommendation\Jev\Support\NoulScore'
        => 'App\Service\Recommendation\Scoring\Support\ProbabilityScore',
    'App\Service\Recommendation\Jev\Support\QuestionId'
        => 'App\Service\Recommendation\Scoring\Support\QuestionId',
    'App\Service\Recommendation\Jev\Support\SystemOneJson'
        => 'App\Service\Recommendation\Scoring\Support\CompactJson',
    'App\Service\Recommendation\Jev\Support\SystemOneReplyDecoder'
        => 'App\Service\Recommendation\Scoring\Support\SystemOneReplyDecoder',
    'App\Service\Recommendation\Jev\SystemOneClient\HttpSystemOneClient'
        => 'App\Service\Recommendation\Scoring\SystemOneClient\HttpSystemOneClient',
    'App\Service\Recommendation\Jev\SystemOneClient\SystemOneClientInterface'
        => 'App\Service\Recommendation\Scoring\SystemOneClient\SystemOneClientInterface',
    'App\Tests\Service\Recommendation\Jev\Factory\JevStateFactoryTest'
        => 'App\Tests\Service\Recommendation\Scoring\Factory\ScoringStateFactoryTest',
    'App\Tests\Service\Recommendation\Jev\Factory\SystemOneRequestFactoryTest'
        => 'App\Tests\Service\Recommendation\Scoring\Factory\SystemOneRequestFactoryTest',
    'App\Tests\Service\Recommendation\Jev\JevBatchPackerTest'
        => 'App\Tests\Service\Recommendation\Scoring\ScoringBatchPackerTest',
    'App\Tests\Service\Recommendation\Jev\JevPipelineTest'
        => 'App\Tests\Service\Recommendation\Scoring\ScoringPipelineTest',
    'App\Tests\Service\Recommendation\Jev\JevRecommendationEngineTest'
        => 'App\Tests\Service\Recommendation\Scoring\ScoringRecommendationEngineTest',
    'App\Tests\Service\Recommendation\Jev\Model\SystemOneRequestModelTest'
        => 'App\Tests\Service\Recommendation\Scoring\Model\SystemOneRequestModelTest',
    'App\Tests\Service\Recommendation\Jev\NoulReplyParserTest'
        => 'App\Tests\Service\Recommendation\Scoring\ScoreParserTest',
    'App\Tests\Service\Recommendation\Jev\Pass\SystemOneWaveTest'
        => 'App\Tests\Service\Recommendation\Scoring\Pass\ResponseWaveTest',
    'App\Tests\Service\Recommendation\Jev\Support\FittingPrefixTest'
        => 'App\Tests\Service\Recommendation\Scoring\Support\FittingPrefixTest',
    'App\Tests\Service\Recommendation\Jev\Support\NoulScoreTest'
        => 'App\Tests\Service\Recommendation\Scoring\Support\ProbabilityScoreTest',
    'App\Tests\Service\Recommendation\Jev\Support\SystemOneReplyDecoderTest'
        => 'App\Tests\Service\Recommendation\Scoring\Support\SystemOneReplyDecoderTest',
    'App\Tests\Service\Recommendation\Jev\SystemOneClient\HttpSystemOneClientTest'
        => 'App\Tests\Service\Recommendation\Scoring\SystemOneClient\HttpSystemOneClientTest',
];
