<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ProfileFeedRule;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** How one network addresses a person's public feed, keyed by SocialNetwork. */
#[AutoconfigureTag('app.social_profile_feed_rule')]
interface ProfileFeedRuleInterface
{
    /** null when $identity names no account of this network or does not round-trip through parseIdentity() */
    public function buildFeedUrl(string $identity): ?string;

    public function parseIdentity(string $feedUrl): ?string;

    public function formatHandle(string $identity): string;

    public function formatTitle(string $feedUrl, string $channelTitle): string;
}
