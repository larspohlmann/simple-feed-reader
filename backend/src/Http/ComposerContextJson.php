<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Service\Social\Social\Model\CommunityModel;
use App\Service\Social\Social\Model\ComposerContextModel;
use App\Service\Social\Social\Model\LinkPostChoicesModel;
use App\Service\Social\Social\Model\PostPreviewModel;

/**
 * @phpstan-import-type LinkCardFields from LinkCardJson
 *
 * @phpstan-type PostTargetRow array{
 *     kind: 'post', authorName: string|null, authorHandle: string|null, excerpt: string, card: LinkCardFields|null
 * }
 * @phpstan-type LinkPostRow array{
 *     communities: list<array{id: int, handle: string}>, title: string,
 *     minTitleCharacters: int, maxTitleCharacters: int
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
     *     lemmy: LinkPostRow|null,
     *     kind: 'reply'|'quote'|'link',
     *     text: string,
     *     target: PostTargetRow|LinkTargetRow,
     * }
     */
    public static function from(ComposerContextModel $context): array
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
            'lemmy' => null === $context->defaults->linkPost
                ? null
                : self::buildLinkPostRow($context->defaults->linkPost),
            'kind' => $context->kind->value,
            'text' => $context->text,
            'target' => $context->target instanceof PostPreviewModel
                ? self::buildPostTargetRow($context->target)
                : self::buildLinkTargetRow($context->target),
        ];
    }

    /** @return LinkPostRow */
    private static function buildLinkPostRow(LinkPostChoicesModel $link): array
    {
        return [
            'communities' => array_map(
                static fn (CommunityModel $community): array => [
                    'id' => $community->id,
                    'handle' => $community->handle,
                ],
                $link->communities,
            ),
            'title' => $link->title,
            'minTitleCharacters' => $link->minTitleCharacters,
            'maxTitleCharacters' => $link->maxTitleCharacters,
        ];
    }

    /** @return PostTargetRow */
    private static function buildPostTargetRow(PostPreviewModel $post): array
    {
        return [
            'kind' => 'post',
            'authorName' => $post->authorName,
            'authorHandle' => $post->authorHandle,
            'excerpt' => $post->excerpt,
            'card' => null === $post->card ? null : LinkCardJson::toFields($post->card),
        ];
    }

    /** @return LinkTargetRow */
    private static function buildLinkTargetRow(LinkCardContent $card): array
    {
        return ['kind' => 'link', 'card' => LinkCardJson::toFields($card)];
    }
}
