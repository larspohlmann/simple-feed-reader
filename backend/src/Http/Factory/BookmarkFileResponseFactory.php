<?php

declare(strict_types=1);

namespace App\Http\Factory;

use App\Service\Library\SavedListExport\Model\SavedListEntryModel;
use App\Service\Library\SavedListExport\Model\SavedListFilenameModel;
use App\Service\Library\SavedListExport\Model\SavedListScopeModel;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class BookmarkFileResponseFactory
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /** @param \Generator<int, list<SavedListEntryModel>> $batches */
    public function stream(SavedListScopeModel $scope, \Generator $batches): StreamedResponse
    {
        $filename = new SavedListFilenameModel($scope, $this->clock->now())->withExtension('.html');
        $header = self::buildHeader(self::escaped($scope->exportName()));

        return new StreamedResponse(
            static function () use ($header, $batches): void {
                echo $header;
                foreach ($batches as $batch) {
                    echo implode('', array_map(self::buildLine(...), $batch));
                    flush();
                }
                echo "</DL><p>\n</DL><p>\n";
            },
            headers: [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            ],
        );
    }

    private static function buildHeader(string $folderName): string
    {
        return "<!DOCTYPE NETSCAPE-Bookmark-file-1>\n"
            . "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=UTF-8\">\n"
            . "<TITLE>Bookmarks</TITLE>\n"
            . "<H1>Bookmarks</H1>\n"
            . "<DL><p>\n"
            . '<DT><H3>' . $folderName . "</H3>\n"
            . "<DL><p>\n";
    }

    private static function buildLine(SavedListEntryModel $entry): string
    {
        if ($entry->url === null) {
            return '';
        }
        $tags = $entry->labelNames === []
            ? ''
            : ' TAGS="' . self::escaped(implode(',', str_replace(',', ' ', $entry->labelNames))) . '"';

        return sprintf(
            "<DT><A HREF=\"%s\" ADD_DATE=\"%d\"%s>%s</A>\n",
            self::escaped($entry->url),
            $entry->effectiveDate->getTimestamp(),
            $tags,
            self::escaped($entry->title),
        );
    }

    private static function escaped(string $text): string
    {
        return htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }
}
