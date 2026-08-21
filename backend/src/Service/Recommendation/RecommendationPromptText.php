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
        . 'square brackets. Score each candidate from 0 to 1000 for how strongly the '
        . "reader's history — above all the FAVORITES — suggests they would open it. Be "
        . 'critical and sparing with high scores: most candidates are only a weak or partial '
        . 'fit and must score below 500. Reserve 900-1000 for the rare candidate that is an '
        . 'unmistakable, specific match to a strong, repeated favorite interest; 700-899 for a '
        . 'clear, direct match to a demonstrated interest; 400-699 for a real but partial or '
        . 'merely thematic match; 100-399 for a weak or tangential link; 0-99 for no visible '
        . "connection. A candidate whose only support in the reader's history is a VIEWED post "
        . 'scores below 400, however closely it matches: opening a post is not liking it. '
        . 'A post that only shares a broad topic the reader occasionally reads '
        . 'is a partial match, not a strong one. If you are giving many candidates scores '
        . 'above 800, you are being too generous — lower them. When uncertain, score lower. '
        . 'Prefer recent posts. Use the whole range and give each candidate its own exact '
        . 'number: 843, 617, 291. Do not round to multiples of ten, and do not give the same '
        . 'score to several candidates — if two are close, decide which is the better fit and '
        . 'score it higher. Score every candidate you are shown, including one that repeats a '
        . 'story another candidate covers — never leave a candidate out.';

    public const string DEDUP_ROLE = 'You remove duplicate stories from a ranked list built for one reader of '
        . 'an RSS reader. The user message lists RANKED entries, best first; each line starts with the entry id '
        . 'in square brackets, followed by the title, the date and the opening of the post. Two entries are '
        . 'duplicates only when they report the same specific event or announcement — the same occurrence, told '
        . 'by different sources. Entries that merely share a topic, a subject area, a company, a technology or a '
        . 'person are not duplicates, and separate developments in one ongoing story are not duplicates either. '
        . 'When you are not certain two entries are the same story, name neither. When several entries do cover '
        . 'the same story, keep the best-ranked source and name the others as duplicates.';

    public const string DEDUP_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"duplicates": '
        . '[<entry id>, ...]}. List only ids of entries that duplicate a better-ranked entry. If there are no '
        . 'duplicates, reply {"duplicates": []}. Use only ids that appear in the lines.';

    public const string OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"recommendations": '
        . '[{"id": <candidate id>, "score": <0-1000>, "reason": "<one short sentence>"}]}. Return one object '
        . 'for every candidate line, in the order the lines appear. Use only ids that appear in the candidate '
        . 'lines. The reason is shown to the reader, so name what this post is about and the interest or the '
        . 'earlier post it matches; do not open with "Directly aligns", "Matches the reader\'s" or any other '
        . 'fixed phrase.';

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

    public const string BATCH_SYSTEM_ROLE = 'You score candidate posts for one reader of an RSS reader. The user '
        . 'message holds a PROFILE describing the reader, a FAVORITES section listing the posts the reader liked most '
        . '(newest first), and a CANDIDATES section listing unread posts; each candidate line starts with the '
        . 'candidate id in square brackets. Score each candidate from 0 to 1000 for how strongly the PROFILE and the '
        . 'FAVORITES suggest the reader would open it. Be critical and sparing with high scores: most candidates are '
        . 'only a weak or partial fit and must score below 500. Reserve 900-1000 for the rare candidate that is an '
        . 'unmistakable, specific match to a strong, repeated interest; 700-899 for a clear, direct match; 400-699 '
        . 'for a real but partial or merely thematic match; 100-399 for a weak or tangential link; 0-99 for no '
        . 'visible connection. If you are giving many candidates scores above 800, you are being too generous — lower '
        . 'them. When uncertain, score lower. Prefer recent posts. Use the whole range and give each candidate its '
        . 'own exact number: 843, 617, 291. Do not round to multiples of ten, and do not give the same score to '
        . 'several candidates. Score every candidate you are shown — never leave a candidate out. Return the id and '
        . 'the score only; do not write a reason.';

    public const string BATCH_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"recommendations": '
        . '[{"id": <candidate id>, "score": <0-1000>}]}. Return one object for every candidate line, in the order '
        . 'the lines appear. Use only ids that appear in the candidate lines.';

    public const string DISTILL_ROLE = 'You read one reader\'s history from an RSS reader and write a short '
        . 'preference profile for them. The user message holds three sections — FAVORITES, KEPT and VIEWED, newest '
        . 'first — where FAVORITES weighs strongest, KEPT next, VIEWED least. Write a compact profile, at most about '
        . '300 words, that names the reader\'s specific, repeated interests — topics, subjects, companies, '
        . 'technologies, people, kinds of story — and what they clearly avoid. Name concrete interests, not broad '
        . 'categories: prefer "self-hosted home automation" over "technology". The profile is used to score unread '
        . 'posts, so it must be specific enough to tell a strong match from a weak one.';

    public const string DISTILL_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: '
        . '{"profile": "<the preference profile>"}.';

    public const string DISTILL_CORRECTIVE = 'Your previous reply was not usable. Reply again with JSON only, '
        . 'exactly in the required shape: {"profile": "<the preference profile>"}.';

    public const string CONSOLIDATION_ROLE = 'You rank a shortlist of unread posts for one reader of an RSS reader '
        . 'and remove duplicates. The user message holds a PROFILE describing the reader, a FAVORITES section listing '
        . 'the posts the reader liked most (newest first), and a SHORTLIST of candidate posts, each line starting '
        . 'with the candidate id in square brackets. Do two things. First, score each shortlisted post from 0 to 1000 '
        . 'for how strongly the PROFILE and the FAVORITES suggest the reader would open it; use the whole range, '
        . 'give each its own exact number, and write one short sentence for each, shown to the reader, that names '
        . 'what the post is about and the interest or earlier post it matches. Do not open a reason with a fixed '
        . 'phrase such as "Directly aligns" or "Matches the reader\'s". Second, name the duplicates: two posts are '
        . 'duplicates only when they report the same specific event, told by different sources; posts that merely '
        . 'share a topic, company, technology or person are not duplicates. Keep the best-scored source and name the '
        . 'others as duplicates; when in doubt, name neither.';

    public const string CONSOLIDATION_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"recommendations": '
        . '[{"id": <id>, "score": <0-1000>, "reason": "<one short sentence>"}], "duplicates": [<id>, ...]}. Return '
        . 'one recommendation object for every shortlist line, in the order the lines appear. List in "duplicates" '
        . 'only ids that duplicate a better-scored post; if there are none, reply "duplicates": []. Use only ids '
        . 'that appear in the lines.';

    public const string CONSOLIDATION_CORRECTIVE = 'Your previous reply was not usable. Reply again with JSON only, '
        . 'exactly in the required shape, using only ids that appear in the lines. Score and give a reason for every '
        . 'shortlist line, and name a duplicate only when it reports the same specific story as a better-scored '
        . 'entry.';

    private function __construct()
    {
    }
}
