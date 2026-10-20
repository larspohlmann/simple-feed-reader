<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\CommentsJson;
use App\Http\EntryJson;
use App\Http\EntryPage;
use App\Http\LinkCardJson;
use App\Http\RecommendationFeedJson;
use App\Http\SavedSearchPage;
use App\Http\SearchPage;
use App\Http\ThreadPostJson;
use App\Service\Reader\ReaderUrlSignature;

final class JsonMappers
{
    public const string SECRET = 'test-link-card-secret';

    public static function linkCardJson(): LinkCardJson
    {
        return new LinkCardJson(new ReaderUrlSignature(self::SECRET));
    }

    public static function threadPostJson(): ThreadPostJson
    {
        return new ThreadPostJson(self::linkCardJson());
    }

    public static function commentsJson(): CommentsJson
    {
        return new CommentsJson(self::linkCardJson());
    }

    public static function entryJson(): EntryJson
    {
        return new EntryJson(self::linkCardJson(), self::threadPostJson());
    }

    public static function entryPage(): EntryPage
    {
        return new EntryPage(self::entryJson());
    }

    public static function searchPage(): SearchPage
    {
        return new SearchPage(self::entryPage());
    }

    public static function savedSearchPage(): SavedSearchPage
    {
        return new SavedSearchPage(self::entryPage());
    }

    public static function recommendationFeedJson(): RecommendationFeedJson
    {
        return new RecommendationFeedJson(self::entryJson());
    }
}
