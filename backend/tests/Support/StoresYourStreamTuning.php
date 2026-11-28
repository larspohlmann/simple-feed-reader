<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Entity\YourStreamTuning;
use App\Service\Curation\YourStream\YourStreamSettingsWriter;

trait StoresYourStreamTuning
{
    private function storeYourStreamTuning(User $user, YourStreamTuning $tuning): void
    {
        $writer = self::getContainer()->get(YourStreamSettingsWriter::class);
        self::assertInstanceOf(YourStreamSettingsWriter::class, $writer);
        $writer->save($user, $tuning);
    }
}
