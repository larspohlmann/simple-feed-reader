<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * The wording that betrays page furniture the reader pipeline kept, in the two
 * languages this installation's feeds publish in. Data, deliberately apart from
 * the rule that applies it: the list grows every time a publisher is added, and
 * a table is cheaper to review than a method full of str_contains calls.
 *
 * A family's weight is how strongly its wording says "this is not the article".
 * Consent walls and bot walls score highest because they mean the reader showed
 * the user no article at all.
 */
final readonly class SuspiciousPhrases
{
    /** @return list<PhraseFamily> */
    public static function families(): array
    {
        return [
            new PhraseFamily('wall_consent', 'HtmlPageFetcher / consent wall', 3, 600, [
                'empfohlener redaktioneller inhalt', 'an dieser stelle finden sie', 'externe inhalte',
                'wir verwenden cookies', 'cookie-einstellungen', 'datenschutzerklärung', 'einwilligung',
                'this site uses cookies', 'accept cookies', 'manage your privacy', 'privacy policy',
                'zustimmung', 'tracking',
            ]),
            new PhraseFamily('wall_javascript', 'HtmlPageFetcher / JS-rendered page', 3, 600, [
                'aktivieren sie javascript', 'javascript ist deaktiviert', 'bitte aktiviere javascript',
                'enable javascript', 'javascript is disabled', 'javascript to run this app',
                'browser wird nicht unterstützt', 'unsupported browser',
            ]),
            new PhraseFamily('wall_bot', 'HtmlPageFetcher / bot wall', 3, 600, [
                'are you a robot', 'access denied', 'zugriff verweigert', 'captcha',
                'unusual traffic', 'cloudflare', 'ihre ip-adresse wurde', 'request blocked',
            ]),
            new PhraseFamily('wall_paywall', 'not a cleaner bug — paywalled source', 2, 600, [
                'jetzt weiterlesen', 'weiterlesen mit', 'nur für abonnenten', 'abo abschließen',
                'sie haben keinen zugriff', 'kostenlos testen', 'jetzt abonnieren',
                'subscribe to continue', 'this article is for subscribers', 'to continue reading',
            ]),
            new PhraseFamily('chrome_navigation', 'NavigationChromeTrimmer', 3, 80, [
                'zum inhalt springen', 'skip to content', 'skip to main content', 'zur startseite',
                'zurück zur übersicht', 'startseite', 'menü', 'hauptmenü', 'navigation',
                'anmelden', 'abmelden', 'einloggen', 'suche', 'sitemap', 'impressum',
            ]),
            new PhraseFamily('chrome_share', 'ShareWidgetRemover', 2, 160, [
                'auf facebook teilen', 'auf x teilen', 'auf twitter teilen', 'per whatsapp',
                'per e-mail teilen', 'artikel teilen', 'link kopieren', 'share on', 'copy link',
                'diesen artikel teilen', 'jetzt teilen', 'drucken', 'merken', 'auf pinterest',
            ]),
            new PhraseFamily('chrome_newsletter', 'EdgeBoilerplateTrimmer', 2, 120, [
                'newsletter', 'jetzt anmelden', 'sign up for', 'subscribe to our', 'abonnieren sie',
            ]),
            new PhraseFamily('chrome_related', 'EdgeBoilerplateTrimmer', 2, 120, [
                'mehr zum thema', 'lesen sie auch', 'das könnte sie auch interessieren',
                'das könnte dich auch interessieren', 'auch interessant', 'ähnliche artikel',
                'ähnliche beiträge', 'weitere artikel', 'meistgelesen', 'am meisten gelesen',
                'related articles', 'related posts', 'you might also like', 'read more',
            ]),
            new PhraseFamily('chrome_comments', 'EdgeBoilerplateTrimmer', 2, 100, [
                'kommentar schreiben', 'kommentar hinterlassen', 'alle kommentare', 'kommentare',
                'leave a comment', 'join the discussion', 'zur diskussion',
            ]),
            new PhraseFamily('chrome_advert', 'EdgeBoilerplateTrimmer', 2, 160, [
                'anzeige', 'werbung', 'advertisement', 'sponsored', 'gesponsert',
                'affiliate', 'provision erhalten', 'partnerlinks',
            ]),
            new PhraseFamily('chrome_meta_line', 'EdgeBoilerplateTrimmer', 1, 90, [
                'minuten lesezeit', 'min lesezeit', 'lesezeit', 'min read', 'stand:',
                'veröffentlicht am', 'zuletzt aktualisiert', 'aktualisiert am', 'published on',
            ]),
            new PhraseFamily('chrome_credits', 'EdgeBoilerplateTrimmer', 1, 120, [
                'alle rechte vorbehalten', 'all rights reserved', 'bildrechte',
                'dpa-infocom', 'mit material von', 'quelle:',
            ]),
        ];
    }
}
