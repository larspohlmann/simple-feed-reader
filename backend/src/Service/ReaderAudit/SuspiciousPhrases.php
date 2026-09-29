<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\ReaderAudit\Model\PhraseFamilyModel;
use App\Service\ReaderAudit\Model\PhraseScope;

/**
 * The wording that betrays page furniture the reader pipeline kept, in the two
 * languages this installation's feeds publish in. Data, deliberately apart
 * from the rule that applies it: the list grows every time a publisher is
 * added, and a table is cheaper to review than a method full of str_contains
 * calls. Only wording this codebase could act on — a paywalled source was
 * dropped for the same reason an unreachable page is not reported: a list
 * full of work nobody can do is a list nobody reads (#744).
 *
 * Two kinds, each confined to the region where it means anything. A wall
 * (consent, JavaScript, bot) counts only on a body that never reaches a
 * paragraph, because a wall IS the absence of the article; inside a real
 * article the same words are its own newsletter fine print. Chrome counts
 * only above the first paragraph — below it, it is the site's tail (#744).
 */
final readonly class SuspiciousPhrases
{
    /** @return list<PhraseFamilyModel> */
    public function families(): array
    {
        return [...self::walls(), ...self::chrome()];
    }

    /** @return list<PhraseFamilyModel> */
    private static function walls(): array
    {
        return [
            new PhraseFamilyModel(
                'wall_consent',
                'HtmlPageFetcher / consent wall',
                4,
                600,
                PhraseScope::OnlyWhenNoArticle,
                [
                    'empfohlener redaktioneller inhalt', 'an dieser stelle finden sie', 'externe inhalte',
                    'wir verwenden cookies', 'cookie-einstellungen', 'datenschutzerklärung', 'einwilligung',
                    'this site uses cookies', 'accept cookies', 'manage your privacy', 'privacy policy',
                ],
            ),
            new PhraseFamilyModel(
                'wall_javascript',
                'HtmlPageFetcher / JS-rendered page',
                4,
                600,
                PhraseScope::OnlyWhenNoArticle,
                [
                'aktivieren sie javascript', 'javascript ist deaktiviert', 'bitte aktiviere javascript',
                'enable javascript', 'javascript is disabled', 'javascript to run this app',
                'browser wird nicht unterstützt', 'unsupported browser',
                ],
            ),
            new PhraseFamilyModel('wall_bot', 'HtmlPageFetcher / bot wall', 4, 600, PhraseScope::OnlyWhenNoArticle, [
                'are you a robot', 'access denied', 'zugriff verweigert', 'captcha',
                'unusual traffic', 'ihre ip-adresse wurde', 'request blocked',
            ]),
        ];
    }

    /**
     * Short limits on purpose: a navigation word is a menu entry, and a menu
     * entry is two or three words. The 57-character line "Mehr Deutschlandfunk
     * in der Google-Suche" is what a wider limit matched on "suche".
     *
     * @return list<PhraseFamilyModel>
     */
    private static function chrome(): array
    {
        return [
            new PhraseFamilyModel('chrome_navigation', 'NavigationChromeTrimmer', 3, 30, PhraseScope::AboveTheArticle, [
                'zum inhalt springen', 'skip to content', 'skip to main content', 'zur startseite',
                'zurück zur übersicht', 'startseite', 'menü', 'hauptmenü', 'navigation',
                'anmelden', 'abmelden', 'einloggen', 'suche', 'sitemap', 'impressum',
            ]),
            new PhraseFamilyModel('chrome_share', 'ShareWidgetRemover', 3, 60, PhraseScope::AboveTheArticle, [
                'auf facebook teilen', 'auf x teilen', 'auf twitter teilen', 'per whatsapp',
                'per e-mail teilen', 'artikel teilen', 'link kopieren', 'share on', 'copy link',
                'diesen artikel teilen', 'jetzt teilen', 'drucken', 'merken',
            ]),
            new PhraseFamilyModel('chrome_newsletter', 'EdgeBoilerplateTrimmer', 2, 90, PhraseScope::AboveTheArticle, [
                'newsletter', 'jetzt anmelden', 'sign up for', 'subscribe to our',
            ]),
            new PhraseFamilyModel('chrome_related', 'EdgeBoilerplateTrimmer', 2, 90, PhraseScope::AboveTheArticle, [
                'mehr zum thema', 'lesen sie auch', 'das könnte sie auch interessieren',
                'auch interessant', 'ähnliche artikel', 'weitere artikel', 'meistgelesen',
                'related articles', 'related posts', 'you might also like',
            ]),
            new PhraseFamilyModel('chrome_advert', 'EdgeBoilerplateTrimmer', 2, 60, PhraseScope::AboveTheArticle, [
                'anzeige', 'werbung', 'advertisement', 'sponsored', 'gesponsert',
            ]),
        ];
    }
}
