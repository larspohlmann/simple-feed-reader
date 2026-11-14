<?php

declare(strict_types=1);

namespace App\Service\Account\Model;

use App\Entity\DigestSettings;
use App\Entity\FeedDirectoryScope;
use App\Entity\ReadingSettings;
use App\Entity\SharingSettings;

final readonly class AccountSettingsModel
{
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public ReadingSettings $reading,
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public SharingSettings $sharing,
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public DigestSettings $digest,
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public FeedDirectoryScope $feedDirectoryScope,
    ) {
    }
}
