<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

final readonly class HeadlineFiller
{
    private const float WINDOW_SHARE = 0.03;
    private const int COUNTED_ENTRIES_FLOOR = 1_500;
    private const int FEED_MINIMUM_ENTRIES = 4;
    private const float FEED_SHARE = 0.5;

    /** German, English, French and Spanish; already in HeadlineStems' form: casefolded, without diacritics, cut to six characters. */
    private const array SEED_STEMS = [
        'the' => true, 'and' => true, 'for' => true, 'with' => true, 'from' => true, 'that' => true,
        'this' => true, 'these' => true, 'those' => true, 'are' => true, 'was' => true, 'were' => true,
        'has' => true, 'have' => true, 'had' => true, 'will' => true, 'would' => true, 'could' => true,
        'should' => true, 'not' => true, 'but' => true, 'you' => true, 'your' => true, 'our' => true,
        'its' => true, 'their' => true, 'they' => true, 'them' => true, 'his' => true, 'her' => true,
        'she' => true, 'who' => true, 'what' => true, 'when' => true, 'where' => true, 'which' => true,
        'why' => true, 'how' => true, 'after' => true, 'over' => true, 'into' => true, 'about' => true,
        'than' => true, 'can' => true, 'all' => true, 'out' => true, 'been' => true, 'also' => true,
        'just' => true, 'there' => true, 'then' => true, 'some' => true, 'any' => true, 'only' => true,
        'becaus' => true, 'before' => true, 'agains' => true, 'betwee' => true, 'during' => true,
        'withou' => true, 'throug' => true, 'under' => true, 'while' => true, 'more' => true,
        'der' => true, 'die' => true, 'das' => true, 'und' => true, 'mit' => true, 'von' => true,
        'fur' => true, 'auf' => true, 'ist' => true, 'ein' => true, 'eine' => true, 'einen' => true,
        'einem' => true, 'einer' => true, 'eines' => true, 'nicht' => true, 'sich' => true, 'auch' => true,
        'als' => true, 'aus' => true, 'bei' => true, 'nach' => true, 'wie' => true, 'dem' => true,
        'den' => true, 'des' => true, 'zum' => true, 'zur' => true, 'uber' => true, 'noch' => true,
        'nur' => true, 'wird' => true, 'werden' => true, 'wurde' => true, 'sind' => true, 'hat' => true,
        'haben' => true, 'oder' => true, 'aber' => true, 'wenn' => true, 'dass' => true, 'sie' => true,
        'wir' => true, 'ihr' => true, 'jetzt' => true, 'mehr' => true, 'schon' => true, 'vor' => true,
        'bis' => true, 'unter' => true, 'gegen' => true, 'durch' => true, 'ins' => true, 'vom' => true,
        'seit' => true, 'beim' => true, 'ohne' => true, 'sein' => true, 'seine' => true, 'diese' => true,
        'dieser' => true, 'dieses' => true, 'kann' => true, 'soll' => true, 'alle' => true,
        'les' => true, 'une' => true, 'aux' => true, 'dans' => true, 'sur' => true, 'sous' => true, 'pour' => true,
        'par' => true, 'avec' => true, 'que' => true, 'qui' => true, 'quoi' => true, 'dont' => true,
        'est' => true, 'sont' => true, 'etre' => true, 'ont' => true, 'pas' => true, 'cet' => true,
        'cette' => true, 'ces' => true, 'ses' => true, 'leur' => true, 'leurs' => true, 'elle' => true,
        'ils' => true, 'elles' => true, 'nous' => true, 'vous' => true, 'mais' => true, 'donc' => true,
        'apres' => true, 'avant' => true, 'entre' => true, 'chez' => true, 'vers' => true, 'selon' => true,
        'contre' => true, 'depuis' => true, 'lors' => true, 'tres' => true, 'aussi' => true, 'deja' => true,
        'encore' => true, 'comme' => true, 'tout' => true, 'tous' => true, 'toute' => true, 'fait' => true,
        'los' => true, 'las' => true, 'una' => true, 'unos' => true, 'unas' => true, 'del' => true, 'por' => true,
        'para' => true, 'como' => true, 'mas' => true, 'sus' => true, 'pero' => true,
        'sobre' => true, 'hasta' => true, 'desde' => true, 'tras' => true, 'ante' => true, 'este' => true,
        'esta' => true, 'estos' => true, 'estas' => true, 'ese' => true, 'esa' => true, 'eso' => true,
        'esos' => true, 'esas' => true, 'muy' => true, 'tambie' => true, 'fue' => true, 'ser' => true,
        'hay' => true, 'sido' => true, 'segun' => true, 'cuando' => true, 'donde' => true,
        'porque' => true, 'tiene' => true, 'todo' => true, 'todos' => true, 'toda' => true, 'otro' => true,
        'otra' => true, 'durant' => true, 'contra' => true,
    ];

    public function isSeed(string $stem): bool
    {
        return isset(self::SEED_STEMS[$stem]);
    }

    public function isWindowFiller(int $documentFrequency, int $entryCount): bool
    {
        return $documentFrequency >= self::WINDOW_SHARE * self::countedEntries($entryCount);
    }

    public function isFeedFiller(int $feedDocumentFrequency, int $feedEntryCount): bool
    {
        return $feedEntryCount >= self::FEED_MINIMUM_ENTRIES
            && $feedDocumentFrequency >= self::FEED_SHARE * $feedEntryCount;
    }

    public function rarityWeight(int $documentFrequency, int $entryCount): float
    {
        return log((self::countedEntries($entryCount) + 2) / ($documentFrequency + 1));
    }

    private static function countedEntries(int $entryCount): int
    {
        return max($entryCount, self::COUNTED_ENTRIES_FLOOR);
    }
}
