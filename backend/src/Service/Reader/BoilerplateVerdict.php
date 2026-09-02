<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\ClassTokenMatcher;
use Dom\Element;

/**
 * Decides whether one edge block is boilerplate. Two or more independent
 * structural signals condemn it: a fingerprint class, a link list, a list of
 * picture cards, a form or email input. A single signal needs a corroborating
 * heading phrase, a phrase alone never condemns, and a standalone ad label
 * condemns on its own (#582, #779).
 */
final readonly class BoilerplateVerdict
{
    /** Whole class tokens that fingerprint an edge-boilerplate block. */
    private const array BOILERPLATE_CLASS_TOKENS = [
        // related-post grids
        'related', 'related-posts', 'related-articles', 'yarpp-related', 'jp-relatedposts',
        // newsletter / subscribe
        'newsletter', 'subscribe', 'mc4wp', 'mailchimp',
        // comments
        'comments', 'comments-area', 'comment-respond', 'comment-form', 'disqus',
    ];

    /** A link list, or a list of picture cards, carries at least this many links. */
    private const int MIN_LINKS_FOR_LIST = 3;

    /** Standalone labels that mark a block as ad chrome, no corroboration needed. */
    private const array AD_LABELS = ['advertisement', 'anzeige', 'werbung', 'sponsored'];

    /**
     * Lowercase heading fragments that corroborate a boilerplate verdict, German
     * and English. Corroboration only: a phrase never removes a block on its own.
     */
    private const array PHRASE_FRAGMENTS = [
        // German
        'ähnliche beiträge', 'das könnte dich auch interessieren', 'mehr zum thema',
        'auch interessant', 'newsletter', 'jetzt anmelden', 'schreibe einen kommentar',
        'kommentar hinterlassen', 'kommentare',
        // English
        'related posts', 'related articles', 'you might also like', 'read more',
        'more from', 'sign up', 'subscribe', 'leave a comment', 'comments',
    ];

    public function condemns(Element $block): bool
    {
        if ($this->isAdLabel($block)) {
            return true;
        }

        $structural = (int) $this->hasFingerprint($block)
            + (int) $this->isLinkList($block)
            + (int) $this->isPictureCardList($block)
            + (int) $this->hasFormOrEmail($block);

        if ($structural >= 2) {
            return true;
        }

        return $structural >= 1 && $this->hasCorroboratingPhrase($block);
    }

    /**
     * A block whose entire (separator-collapsed) text is one of AD_LABELS, e.g.
     * "- Advertisement -" or "Anzeige". A block that merely mentions the word
     * among other prose does not match.
     */
    private function isAdLabel(Element $block): bool
    {
        $text = mb_strtolower(trim((string) preg_replace(
            '/[\s\-–—|:]+/u',
            ' ',
            (string) $block->textContent,
        )));

        return in_array($text, self::AD_LABELS, true);
    }

    private function hasFingerprint(Element $block): bool
    {
        return ClassTokenMatcher::hasAnyToken($block, self::BOILERPLATE_CLASS_TOKENS);
    }

    private function isLinkList(Element $block): bool
    {
        return $block->getElementsByTagName('a')->length >= self::MIN_LINKS_FOR_LIST
            && BlockText::isLinkDominated($block);
    }

    /**
     * Three or more links that each wrap a picture: a related-articles grid
     * drawn as teaser cards, whether or not the publisher labels it (#779).
     */
    private function isPictureCardList(Element $block): bool
    {
        $cards = 0;
        foreach ($block->getElementsByTagName('a') as $link) {
            $cards += $link->getElementsByTagName('img')->length > 0 ? 1 : 0;
        }

        return $cards >= self::MIN_LINKS_FOR_LIST;
    }

    private function hasFormOrEmail(Element $block): bool
    {
        if ($block->getElementsByTagName('form')->length > 0) {
            return true;
        }

        foreach ($block->getElementsByTagName('input') as $input) {
            if (strtolower($input->getAttribute('type') ?? '') === 'email') {
                return true;
            }
        }

        return false;
    }

    private function hasCorroboratingPhrase(Element $block): bool
    {
        $heading = $block->querySelector('h1, h2, h3, h4');
        if ($heading === null) {
            return false;
        }

        $text = mb_strtolower((string) $heading->textContent);

        return array_any(
            self::PHRASE_FRAGMENTS,
            static fn (string $fragment): bool => str_contains($text, $fragment),
        );
    }
}
