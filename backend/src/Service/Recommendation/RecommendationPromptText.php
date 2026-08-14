<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The fixed prompt strings for the recommendation feature. Kept apart from
 * RecommendationPromptBuilder so the contractual wording sits in one place,
 * free of the sizing and packing logic that assembles it into messages.
 */
final class RecommendationPromptText
{
    public const string SYSTEM_ROLE = 'You score candidate posts for one reader of an RSS reader. The user '
        . 'message holds four sections. FAVORITES, KEPT and VIEWED list posts from the '
        . "reader's history, newest first. FAVORITES weighs strongest, KEPT next, VIEWED "
        . 'least. CANDIDATES lists unread posts; each line starts with the candidate id in '
        . 'square brackets. Score each candidate from 0 to 100 for how strongly the '
        . "reader's history — above all the FAVORITES — suggests they would open it. Be "
        . 'critical and sparing with high scores: most candidates are only a weak or partial '
        . 'fit and must score below 50. Reserve 90-100 for the rare candidate that is an '
        . 'unmistakable, specific match to a strong, repeated favorite interest; 70-89 for a '
        . 'clear, direct match to a demonstrated interest; 40-69 for a real but partial or '
        . 'merely thematic match; 10-39 for a weak or tangential link; 0-9 for no visible '
        . 'connection. A post that only shares a broad topic the reader occasionally reads '
        . 'is a partial match, not a strong one. If you are giving many candidates scores '
        . 'above 80, you are being too generous — lower them. When uncertain, score lower. '
        . 'Prefer recent posts. When several candidates cover the same story, score only the '
        . 'best source and omit the others.';

    public const string DEDUP_ROLE = 'You remove duplicate stories from a ranked list built for one reader of '
        . 'an RSS reader. The user message lists RANKED entries, best first; each line starts with the entry id '
        . 'in square brackets, followed by title, source, date and the reason it was chosen. Two entries are '
        . 'duplicates only when they report the same specific event or announcement — the same occurrence, told '
        . 'by different sources. Entries that merely share a topic, a subject area, a company, a technology or a '
        . 'person are not duplicates, and separate developments in one ongoing story are not duplicates either. '
        . 'When you are not certain two entries are the same story, name neither. When several entries do cover '
        . 'the same story, keep the best-ranked source and name the others as duplicates.';

    public const string DEDUP_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"duplicates": '
        . '[<entry id>, ...]}. List only ids of entries that duplicate a better-ranked entry. If there are no '
        . 'duplicates, reply {"duplicates": []}. Use only ids that appear in the lines.';

    public const string OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"recommendations": '
        . '[{"id": <candidate id>, "score": <0-100>, "reason": "<one short sentence>"}]}. Score every '
        . 'candidate. Use only ids that appear in the candidate lines.';

    public const string CORRECTIVE = 'Your previous reply was not usable. Reply again with JSON only, exactly '
        . 'in the required shape, using only candidate ids.';

    /**
     * The dedup phase's own correction. A dedup reply is rejected for either
     * of two reasons — it did not parse, or it named an implausible share of
     * the list — and the run stores only the reply itself, not which of the
     * two it was. So this says both, and each half is sound advice whichever
     * rejection produced it (#396).
     */
    public const string DEDUP_CORRECTIVE = 'Your previous reply was not usable. Reply again with JSON only, '
        . 'exactly in the required shape, using only ids that appear in the lines. Name an entry only when it '
        . 'reports the same specific story as a better-ranked entry; when in doubt, leave it out.';

    public const string DEFAULT_GUIDANCE = 'Recommend the posts this reader is most likely to open next, '
        . 'judged by how strongly they match the interests visible in the reading history.';

    private function __construct()
    {
    }
}
