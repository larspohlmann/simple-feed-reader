<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SocialAccount;
use App\Service\Social\Model\ComposerContextModel;
use App\Service\Social\Model\PostPreviewModel;

/**
 * @phpstan-import-type LinkCardFields from LinkCardJson
 *
 * @phpstan-type QuoteRow array{
 *     authorName: string|null, authorHandle: string|null, excerpt: string, card: LinkCardFields|null
 * }
 */
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
     *     quote: QuoteRow|null,
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

    /** @return QuoteRow */
    private static function previewOf(PostPreviewModel $preview): array
    {
        return [
            'authorName' => $preview->authorName,
            'authorHandle' => $preview->authorHandle,
            'excerpt' => $preview->excerpt,
            'card' => null === $preview->card ? null : LinkCardJson::fieldsOf($preview->card),
        ];
    }
}
