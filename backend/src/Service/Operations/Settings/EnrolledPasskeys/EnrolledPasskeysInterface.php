<?php

declare(strict_types=1);

namespace App\Service\Settings\EnrolledPasskeys;

interface EnrolledPasskeysInterface
{
    public function countAll(): int;

    public function deleteAll(): void;
}
