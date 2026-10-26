<?php

declare(strict_types=1);

namespace App\Service\Chapter\EmbedDetailsSource;

use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Model\EmbedDescriptionModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One provider's account-free way to an embed's title, length and description, keyed by its ChapteredEmbed value. */
#[AutoconfigureTag('app.embed_details_source')]
interface EmbedDetailsSourceInterface
{
    public static function provider(): string;

    /** @throws EmbedDescriptionUnreachableException */
    public function detailsOf(EmbedAddressModel $embed): EmbedDescriptionModel;
}
