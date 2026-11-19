<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\PageCardReader;

use App\Service\Content\LinkCard\Model\PageCardModel;
use App\Service\Content\LinkCard\Model\PageIdentityModel;
use App\Service\Content\LinkCard\Support\DeclaredPageMedium;
use App\Service\Content\LinkCard\Support\StorableLinkCard;
use App\Service\Content\Reader\Exception\PageFetchException;
use App\Service\Content\Reader\PageHeadFetcher;
use App\Service\Content\Reader\Support\DeclaredPreview;
use App\Service\Foundation\Html\Support\DeclaredPageAddress;
use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Foundation\Html\Support\MetaTags;
use App\Service\Ingestion\Parser\Support\VisualMedium;
use App\Service\Outbound\Fetch\Pass\PageUrls;

/** Reads the card a page declares for itself, and the picture or video file it is or declares. */
final readonly class PageCardReader implements PageCardReaderInterface
{
    public function __construct(private PageHeadFetcher $heads)
    {
    }

    /** @throws PageFetchException */
    public function read(string $sharedUrl): PageCardModel
    {
        $page = $this->heads->fetch($sharedUrl);
        if ($page->fileType !== null) {
            return new PageCardModel(null, VisualMedium::servedAs($sharedUrl, $page->fileType));
        }
        $document = HtmlDocumentParser::parseOrEmpty($page->head);
        $preview = DeclaredPreview::fromDocument($document, $sharedUrl, $page->finalUrl);

        return new PageCardModel(
            StorableLinkCard::fromCard($preview->card()),
            DeclaredPageMedium::fromMeta(MetaTags::contentByKey($document)),
            new PageIdentityModel(
                $page->finalUrl,
                new PageUrls($page->finalUrl)->httpUrl(DeclaredPageAddress::in($document)),
                $preview->title,
            ),
            $preview->language,
        );
    }
}
