<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Content\Reader\LeadingEngagementRules;
use App\Service\Content\ReaderAudit\BodyShapeMarkers;
use App\Service\Content\ReaderAudit\CleanupMarkers;
use App\Service\Content\ReaderAudit\LeadingChromeMarkers;
use App\Service\Content\ReaderAudit\LeadingEngagementMarkers;
use App\Service\Content\ReaderAudit\LeadingRegion;
use App\Service\Content\ReaderAudit\PhraseMarkers;
use App\Service\Content\ReaderAudit\SocialWidgetMarkers;
use App\Service\Content\ReaderAudit\SuspiciousPhrases;

/** The audit's marker rules over the reader's real leading-block rules, wired as the container wires them. */
final class AuditMarkers
{
    private function __construct()
    {
    }

    public static function cleanupMarkers(): CleanupMarkers
    {
        return new CleanupMarkers(
            self::leadingChrome(),
            self::leadingEngagement(),
            new SocialWidgetMarkers(),
            new BodyShapeMarkers(),
            self::phrases(),
        );
    }

    public static function leadingRegion(): LeadingRegion
    {
        return new LeadingRegion(new LeadingEngagementRules());
    }

    public static function leadingChrome(): LeadingChromeMarkers
    {
        return new LeadingChromeMarkers(self::leadingRegion());
    }

    public static function leadingEngagement(): LeadingEngagementMarkers
    {
        return new LeadingEngagementMarkers(new LeadingEngagementRules(), self::leadingRegion());
    }

    public static function phrases(): PhraseMarkers
    {
        return new PhraseMarkers(self::leadingRegion(), new SuspiciousPhrases());
    }
}
