<?php

declare(strict_types=1);

namespace App\Service\Chapter\EmbedDescriptionSource;

use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\Model\EmbedAddressModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One provider's account-free way to an embed's description, keyed by its ChapteredEmbed value. */
#[AutoconfigureTag('app.embed_description_source')]
interface EmbedDescriptionSourceInterface
{
    public static function provider(): string;

    /**
     * '' when the embed has no description.
     *
     * @throws EmbedDescriptionUnreachableException
     */
    public function descriptionOf(EmbedAddressModel $embed): string;
}
