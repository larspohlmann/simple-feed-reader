<?php

declare(strict_types=1);

namespace App\Service\Social\HomeServerCheck;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's anonymous question to a post's own server, keyed by its SocialNetwork value. */
#[AutoconfigureTag('app.social_home_server_check')]
interface HomeServerCheckInterface
{
    public static function network(): string;

    /**
     * True only when the post's own server answers that the post is gone; any other answer proves nothing.
     *
     * @throws SocialNetworkUnreachableException when no answer arrived
     */
    public function confirmsDeleted(string $address): bool;
}
