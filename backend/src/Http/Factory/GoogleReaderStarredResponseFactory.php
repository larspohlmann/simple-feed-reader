<?php

declare(strict_types=1);

namespace App\Http\Factory;

use App\Service\Library\SavedListExport\Model\SavedListEntryModel;
use App\Service\Library\SavedListExport\Model\SavedListFilenameModel;
use App\Service\Library\SavedListExport\Model\SavedListOriginModel;
use App\Service\Library\SavedListExport\Model\SavedListScopeModel;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class GoogleReaderStarredResponseFactory
{
    private const string STARRED = 'user/-/state/com.google/starred';
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private ClockInterface $clock)
    {
    }

    /** @param \Generator<int, list<SavedListEntryModel>> $batches */
    public function stream(SavedListScopeModel $scope, \Generator $batches): StreamedResponse
    {
        $now = $this->clock->now();
        $filename = new SavedListFilenameModel($scope, $now)->withExtension('-starred.json');
        $header = sprintf(
            '{"id":%s,"title":%s,"updated":%d,"items":[',
            self::encoded(self::STARRED),
            self::encoded($scope->exportName()),
            $now->getTimestamp(),
        );

        return new StreamedResponse(
            static function () use ($header, $batches): void {
                echo $header;
                $separator = '';
                foreach ($batches as $batch) {
                    foreach ($batch as $entry) {
                        echo $separator . self::encoded(self::buildItem($entry));
                        $separator = ',';
                    }
                    flush();
                }
                echo ']}';
            },
            headers: [
                'Content-Type' => 'application/json',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function buildItem(SavedListEntryModel $entry): array
    {
        $timestamp = $entry->effectiveDate->getTimestamp();
        $item = [
            'id' => $entry->guid,
            'title' => $entry->title,
            'published' => $timestamp,
            'updated' => $timestamp,
        ];
        if ($entry->author !== null && $entry->author !== '') {
            $item['author'] = $entry->author;
        }
        if ($entry->url !== null) {
            $item['alternate'] = [['href' => $entry->url, 'type' => 'text/html']];
        }
        $item['content'] = ['direction' => 'ltr', 'content' => $entry->contentHtml];
        $item['origin'] = self::buildOrigin($entry->origin);
        $item['categories'] = [
            self::STARRED,
            ...array_map(static fn (string $name): string => 'user/-/label/' . $name, $entry->labelNames),
        ];

        return $item;
    }

    /** @return array<string, string> */
    private static function buildOrigin(SavedListOriginModel $origin): array
    {
        if (!$origin->isSubscribable()) {
            return ['title' => $origin->title];
        }
        $fields = ['streamId' => 'feed/' . $origin->feedUrl, 'title' => $origin->title];
        if ($origin->siteUrl !== null) {
            $fields['htmlUrl'] = $origin->siteUrl;
        }

        return $fields;
    }

    private static function encoded(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }
}
