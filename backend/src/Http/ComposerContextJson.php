<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Service\Social\Publishing\Model\CommunityModel;
use App\Service\Social\Publishing\Model\ComposerContextModel;
use App\Service\Social\Publishing\Model\LemmyPostFieldsModel;
use App\Service\Social\Social\Model\PostPreviewModel;

/**
 * @phpstan-import-type LinkCardFields from LinkCardJson
 *
 * @phpstan-type PostSubjectRow array{
 *     kind: 'post', authorName: string|null, authorHandle: string|null, excerpt: string, card: LinkCardFields|null
 * }
 * @phpstan-type LemmyPostRow array{
 *     communities: list<array{id: int, handle: string}>, title: string,
 *     minTitleCharacters: int, maxTitleCharacters: int
 * }
 * @phpstan-type LinkSubjectRow array{kind: 'link', card: LinkCardFields}
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
     *     lemmy: LemmyPostRow|null,
     *     kind: 'reply'|'quote'|'link'|'post',
     *     text: string,
     *     subject: PostSubjectRow|LinkSubjectRow|null,
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
            'lemmy' => null === $context->defaults->lemmyPost
                ? null
                : self::buildLemmyPostRow($context->defaults->lemmyPost),
            'kind' => $context->kind->value,
            'text' => $context->text,
            'subject' => self::buildSubjectRow($context->subject),
        ];
    }

    /** @return PostSubjectRow|LinkSubjectRow|null */
    private static function buildSubjectRow(PostPreviewModel|LinkCardContent|null $subject): ?array
    {
        return match (true) {
            $subject instanceof PostPreviewModel => self::buildPostSubjectRow($subject),
            $subject instanceof LinkCardContent => self::buildLinkSubjectRow($subject),
            default => null,
        };
    }

    /** @return LemmyPostRow */
    private static function buildLemmyPostRow(LemmyPostFieldsModel $fields): array
    {
        return [
            'communities' => array_map(
                static fn (CommunityModel $community): array => [
                    'id' => $community->id,
                    'handle' => $community->handle,
                ],
                $fields->communities,
            ),
            'title' => $fields->title,
            'minTitleCharacters' => $fields->minTitleCharacters,
            'maxTitleCharacters' => $fields->maxTitleCharacters,
        ];
    }

    /** @return PostSubjectRow */
    private static function buildPostSubjectRow(PostPreviewModel $post): array
    {
        return [
            'kind' => 'post',
            'authorName' => $post->authorName,
            'authorHandle' => $post->authorHandle,
            'excerpt' => $post->excerpt,
            'card' => null === $post->card ? null : LinkCardJson::toFields($post->card),
        ];
    }

    /** @return LinkSubjectRow */
    private static function buildLinkSubjectRow(LinkCardContent $card): array
    {
        return ['kind' => 'link', 'card' => LinkCardJson::toFields($card)];
    }
}
