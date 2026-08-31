<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * The wording that betrays page furniture the reader pipeline kept, in the two
 * languages this installation's feeds publish in. Data, deliberately apart from
 * the rule that applies it: the list grows every time a publisher is added, and
 * a table is cheaper to review than a method full of str_contains calls.
 *
 * Two kinds. A wall — consent, JavaScript, bot, paywall — means the reader
 * showed the user no article at all, so it counts wherever it stands. Chrome
 * counts only above the first paragraph: below it, it is the site's tail and the
 * reader's user has already read the article (#744).
 */
final readonly class SuspiciousPhrases
{
    private const bool ANYWHERE = false;
    private const bool LEADING_ONLY = true;

    /** @return list<PhraseFamily> */
    public static function families(): array
    {
        return [...self::walls(), ...self::chrome()];
    }

    /** @return list<PhraseFamily> */
    private static function walls(): array
    {
        return [
            new PhraseFamily('wall_consent', 'HtmlPageFetcher / consent wall', 4, 600, self::ANYWHERE, [
                'empfohlener redaktioneller inhalt', 'an dieser stelle finden sie', 'externe inhalte',
                'wir verwenden cookies', 'cookie-einstellungen', 'datenschutzerklärung', 'einwilligung',
                'this site uses cookies', 'accept cookies', 'manage your privacy', 'privacy policy',
            ]),
            new PhraseFamily('wall_javascript', 'HtmlPageFetcher / JS-rendered page', 4, 600, self::ANYWHERE, [
                'aktivieren sie javascript', 'javascript ist deaktiviert', 'bitte aktiviere javascript',
                'enable javascript', 'javascript is disabled', 'javascript to run this app',
                'browser wird nicht unterstützt', 'unsupported browser',
            ]),
            new PhraseFamily('wall_bot', 'HtmlPageFetcher / bot wall', 4, 600, self::ANYWHERE, [
                'are you a robot', 'access denied', 'zugriff verweigert', 'captcha',
                'unusual traffic', 'ihre ip-adresse wurde', 'request blocked',
            ]),
            new PhraseFamily('wall_paywall', 'not a cleaner bug — paywalled source', 2, 600, self::ANYWHERE, [
                'jetzt weiterlesen', 'weiterlesen mit', 'nur für abonnenten', 'abo abschließen',
                'sie haben keinen zugriff', 'subscribe to continue', 'this article is for subscribers',
            ]),
        ];
    }

    /**
     * Short limits on purpose: a navigation word is a menu entry, and a menu
     * entry is two or three words. The 57-character line "Mehr Deutschlandfunk
     * in der Google-Suche" is what a wider limit matched on "suche".
     *
     * @return list<PhraseFamily>
     */
    private static function chrome(): array
    {
        return [
            new PhraseFamily('chrome_navigation', 'NavigationChromeTrimmer', 3, 30, self::LEADING_ONLY, [
                'zum inhalt springen', 'skip to content', 'skip to main content', 'zur startseite',
                'zurück zur übersicht', 'startseite', 'menü', 'hauptmenü', 'navigation',
                'anmelden', 'abmelden', 'einloggen', 'suche', 'sitemap', 'impressum',
            ]),
            new PhraseFamily('chrome_share', 'ShareWidgetRemover', 3, 60, self::LEADING_ONLY, [
                'auf facebook teilen', 'auf x teilen', 'auf twitter teilen', 'per whatsapp',
                'per e-mail teilen', 'artikel teilen', 'link kopieren', 'share on', 'copy link',
                'diesen artikel teilen', 'jetzt teilen', 'drucken', 'merken',
            ]),
            new PhraseFamily('chrome_newsletter', 'EdgeBoilerplateTrimmer', 2, 90, self::LEADING_ONLY, [
                'newsletter', 'jetzt anmelden', 'sign up for', 'subscribe to our',
            ]),
            new PhraseFamily('chrome_related', 'EdgeBoilerplateTrimmer', 2, 90, self::LEADING_ONLY, [
                'mehr zum thema', 'lesen sie auch', 'das könnte sie auch interessieren',
                'auch interessant', 'ähnliche artikel', 'weitere artikel', 'meistgelesen',
                'related articles', 'related posts', 'you might also like',
            ]),
            new PhraseFamily('chrome_advert', 'EdgeBoilerplateTrimmer', 2, 60, self::LEADING_ONLY, [
                'anzeige', 'werbung', 'advertisement', 'sponsored', 'gesponsert',
            ]),
        ];
    }
}
