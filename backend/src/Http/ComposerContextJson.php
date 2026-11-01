<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Service\Social\Model\ComposerContextModel;
use App\Service\Social\Model\PostPreviewModel;

/**
 * @phpstan-import-type LinkCardFields from LinkCardJson
 *
 * @phpstan-type PostTargetRow array{
 *     kind: 'post', authorName: string|null, authorHandle: string|null, excerpt: string, card: LinkCardFields|null
 * }
 * @phpstan-type LinkTargetRow array{kind: 'link', card: LinkCardFields}
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
     *     target: PostTargetRow|LinkTargetRow,
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
            'target' => $context->target instanceof PostPreviewModel
                ? self::postOf($context->target)
                : self::linkOf($context->target),
        ];
    }

    /** @return PostTargetRow */
    private static function postOf(PostPreviewModel $post): array
    {
        return [
            'kind' => 'post',
            'authorName' => $post->authorName,
            'authorHandle' => $post->authorHandle,
            'excerpt' => $post->excerpt,
            'card' => null === $post->card ? null : LinkCardJson::fieldsOf($post->card),
        ];
    }

    /** @return LinkTargetRow */
    private static function linkOf(LinkCardContent $card): array
    {
        return ['kind' => 'link', 'card' => LinkCardJson::fieldsOf($card)];
    }
}
