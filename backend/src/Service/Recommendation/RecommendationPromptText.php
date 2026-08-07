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
    public const string SYSTEM_ROLE = "You rank candidate posts for one reader of an RSS reader. The user "
        . "message holds four sections. FAVORITES, KEPT and VIEWED list posts from the reader's history, newest "
        . 'first. FAVORITES weighs strongest, KEPT next, VIEWED least. CANDIDATES lists unread posts; each line '
        . 'starts with the candidate id in square brackets. Prefer recent posts. When several candidates cover '
        . 'the same story, pick exactly one of them — the best source.';

    public const string MERGE_ROLE = 'You merge ranked shortlists from earlier rounds into one final ranking '
        . 'for the same reader. The user message lists WINNERS; each line starts with the candidate id in '
        . 'square brackets, followed by title, source, date and the reason it was shortlisted. Prefer recent '
        . 'posts. When several entries cover the same story, keep exactly one of them.';

    public const string OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"recommendations": '
        . '[{"id": <candidate id>, "reason": "<one short sentence>"}]}. Order the array best first. Include at '
        . 'most %d picks. Use only ids that appear in the candidate lines.';

    public const string CORRECTIVE = 'Your previous reply was not usable. Reply again with JSON only, exactly '
        . 'in the required shape, using only candidate ids.';

    public const string DEFAULT_GUIDANCE = 'Recommend the posts this reader is most likely to open next, '
        . 'judged by how strongly they match the interests visible in the reading history.';

    private function __construct()
    {
    }
}
