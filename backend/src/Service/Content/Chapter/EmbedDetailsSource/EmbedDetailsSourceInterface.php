<?php

declare(strict_types=1);

namespace App\Service\Content\Chapter\EmbedDetailsSource;

use App\Service\Content\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Content\Chapter\Model\EmbedAddressModel;
use App\Service\Content\Chapter\Model\EmbedDescriptionModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One provider's account-free way to an embed's title, length and description, keyed by its ChapteredEmbed value. */
#[AutoconfigureTag('app.embed_details_source')]
interface EmbedDetailsSourceInterface
{
    /** @throws EmbedDescriptionUnreachableException */
    public function detailsOf(EmbedAddressModel $embed): EmbedDescriptionModel;
}
