<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use App\Enum\InclusionSurface;

/**
 * The native-SQL twin of the DQL inclusion rule, for Trending's DBAL reads: a subscription counts while it is switched
 * in, its network is, and no tag it carries, nor any ancestor of one, is switched out; exclusion wins.
 */
final class TrendingInclusionSql
{
    private const InclusionSurface SURFACE = InclusionSurface::Trending;

    public static function includes(string $subscription): string
    {
        return \sprintf(
            '(%1$s.%2$s = 1 AND %3$s AND %4$s)',
            $subscription,
            self::SURFACE->switchColumn(),
            self::networkIncluded($subscription),
            self::noTagSwitchedOut($subscription),
        );
    }

    private static function networkIncluded(string $subscription): string
    {
        return \sprintf(
            'NOT EXISTS (SELECT 1 FROM feed %1$s_feed JOIN network_exclusion %1$s_network'
            . ' ON %1$s_network.network = %1$s_feed.network AND %1$s_network.user_id = %1$s.user_id'
            . ' AND %1$s_network.surface = \'%2$s\' WHERE %1$s_feed.id = %1$s.feed_id)',
            $subscription,
            self::SURFACE->value,
        );
    }

    private static function noTagSwitchedOut(string $subscription): string
    {
        $ancestorJoins = '';
        $switchedOff = [self::switchedOff(self::tagAlias($subscription, 0))];
        foreach (range(1, Tag::MAX_DEPTH) as $level) {
            $ancestorJoins .= \sprintf(
                ' LEFT JOIN tag %1$s ON %1$s.id = %2$s.parent_id',
                self::tagAlias($subscription, $level),
                self::tagAlias($subscription, $level - 1),
            );
            $switchedOff[] = self::switchedOff(self::tagAlias($subscription, $level));
        }

        return \sprintf(
            'NOT EXISTS (SELECT 1 FROM subscription_tag %1$s_tagging JOIN tag %2$s ON %2$s.id = %1$s_tagging.tag_id%3$s'
            . ' WHERE %1$s_tagging.subscription_id = %1$s.id AND (%4$s))',
            $subscription,
            self::tagAlias($subscription, 0),
            $ancestorJoins,
            implode(' OR ', $switchedOff),
        );
    }

    private static function switchedOff(string $tag): string
    {
        return \sprintf('%s.%s = 0', $tag, self::SURFACE->switchColumn());
    }

    private static function tagAlias(string $subscription, int $level): string
    {
        return \sprintf('%s_tag%d', $subscription, $level);
    }

    private function __construct()
    {
    }
}
