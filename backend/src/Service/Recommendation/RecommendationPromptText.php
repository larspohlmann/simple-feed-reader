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
        . "message holds four sections. FAVORITES, KEPT and VIEWED list posts from the reader's history, newest "
        . 'first. FAVORITES weighs strongest, KEPT next, VIEWED least. CANDIDATES lists unread posts; each line '
        . 'starts with the candidate id in square brackets. Score each candidate from 0 to 100 for how strongly '
        . "the reader's history suggests they would open it: 90-100 squarely inside a theme the history shows "
        . 'strong, repeated interest in; 60-89 clearly matches a visible interest; 30-59 plausibly interesting '
        . 'but the connection is loose; 0-29 no visible connection. Prefer recent posts. When several candidates '
        . 'cover the same story, score only the best source and omit the others.';

    public const string MERGE_ROLE = 'You merge ranked shortlists from earlier rounds into one final ranking '
        . 'for the same reader. The user message lists WINNERS; each line starts with the candidate id in '
        . 'square brackets, followed by title, source, date and the reason it was shortlisted. Prefer recent '
        . 'posts. When several entries cover the same story, keep exactly one of them.';

    public const string OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"recommendations": '
        . '[{"id": <candidate id>, "score": <0-100>, "reason": "<one short sentence>"}]}. Score every '
        . 'candidate. Use only ids that appear in the candidate lines.';

    public const string CORRECTIVE = 'Your previous reply was not usable. Reply again with JSON only, exactly '
        . 'in the required shape, using only candidate ids.';

    public const string DEFAULT_GUIDANCE = 'Recommend the posts this reader is most likely to open next, '
        . 'judged by how strongly they match the interests visible in the reading history.';

    private function __construct()
    {
    }
}
