<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\Model\ParsedCategoryModel;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\EntryTitle;

/**
 * Turns a Mixcloud `/<user>/cloudcasts/` list into a ParsedFeedModel. The list carries no descriptions, and an
 * exclusive show never plays for a reader without a subscription, so it is skipped.
 */
final readonly class MixcloudJsonParser
{
    public function parse(string $body): ParsedFeedModel
    {
        $shows = $this->shows($body);
        $owner = $this->record($shows[0] ?? null, 'user');

        return new ParsedFeedModel(
            $this->text($owner, 'name'),
            $this->text($owner, 'url'),
            null,
            null,
            $this->entries($shows),
        );
    }

    /** @return list<mixed> */
    private function shows(string $body): array
    {
        /** @var mixed $document */
        $document = json_decode(trim($body), true);
        $shows = \is_array($document) ? ($document['data'] ?? null) : null;
        if (!\is_array($shows) || !array_is_list($shows)) {
            throw new FeedParseException('Mixcloud body is not a cloudcasts list');
        }

        return $shows;
    }

    /**
     * @param list<mixed> $shows
     *
     * @return list<ParsedEntryModel>
     */
    private function entries(array $shows): array
    {
        $entries = [];
        foreach ($shows as $show) {
            $entry = \is_array($show) ? $this->entry($show) : null;
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Null for a show that becomes no entry: an exclusive one, or one without a key, url or name.
     *
     * @param array<mixed> $show
     */
    private function entry(array $show): ?ParsedEntryModel
    {
        $key = $this->text($show, 'key');
        $url = $this->text($show, 'url');
        $name = $this->text($show, 'name');
        if ($key === null || $url === null || $name === null || ($show['is_exclusive'] ?? false) === true) {
            return null;
        }

        return new ParsedEntryModel(
            guid: $key,
            url: $url,
            headline: EntryTitle::of($name, null),
            author: $this->text($this->record($show, 'user'), 'name'),
            summary: null,
            contentHtml: null,
            publishedAt: DateParser::parse($this->text($show, 'created_time')),
            media: new ParsedEntryMediaModel($this->image($show)),
            categories: $this->categories($show),
        );
    }

    /** @param array<mixed> $show */
    private function image(array $show): ?DeclaredImageModel
    {
        $url = $this->text($this->record($show, 'pictures'), 'extra_large');

        return $url === null ? null : new DeclaredImageModel($url);
    }

    /**
     * @param array<mixed> $show
     *
     * @return list<ParsedCategoryModel>
     */
    private function categories(array $show): array
    {
        $tags = $show['tags'] ?? null;
        $categories = [];
        foreach (\is_array($tags) ? $tags : [] as $tag) {
            $label = \is_array($tag) ? $this->text($tag, 'name') : null;
            if ($label !== null) {
                $categories[] = new ParsedCategoryModel($label);
            }
        }

        return $categories;
    }

    /** @return array<mixed> the object under `$field`, or an empty one */
    private function record(mixed $container, string $field): array
    {
        $value = \is_array($container) ? ($container[$field] ?? null) : null;

        return \is_array($value) ? $value : [];
    }

    /** @param array<mixed> $record */
    private function text(array $record, string $field): ?string
    {
        $value = $record[$field] ?? null;
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
