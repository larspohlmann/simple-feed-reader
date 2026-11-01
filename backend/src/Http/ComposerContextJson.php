<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SocialAccount;
use App\Service\Social\Model\ComposerContextModel;
use App\Service\Social\Model\PostPreviewModel;

final class ComposerContextJson
{
    /**
     * @return array{
     *     accounts: list<array{id: int, handle: string}>,
     *     accountId: int,
     *     canSend: bool,
     *     maxCharacters: int,
     *     visibility: string|null,
     *     mentions: list<string>,
     *     quote: array{authorName: string|null, authorHandle: string|null, excerpt: string}|null,
     * }
     */
    public static function of(ComposerContextModel $context): array
    {
        return [
            'accounts' => array_map(
                static fn (SocialAccount $account): array => [
                    'id' => $account->requireId(),
                    'handle' => $account->getHandle(),
                ],
                $context->accounts,
            ),
            'accountId' => $context->account->requireId(),
            'canSend' => $context->canSend,
            'maxCharacters' => $context->defaults->maxCharacters,
            'visibility' => $context->defaults->visibility,
            'mentions' => $context->defaults->mentions,
            'quote' => null === $context->quote ? null : self::previewOf($context->quote),
        ];
    }

    /** @return array{authorName: string|null, authorHandle: string|null, excerpt: string} */
    private static function previewOf(PostPreviewModel $preview): array
    {
        return [
            'authorName' => $preview->authorName,
            'authorHandle' => $preview->authorHandle,
            'excerpt' => $preview->excerpt,
        ];
    }
}
