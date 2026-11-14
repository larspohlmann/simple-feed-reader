<?php

declare(strict_types=1);

namespace App\Service\Operations\Settings\EnrolledPasskeys;

interface EnrolledPasskeysInterface
{
    public function countAll(): int;

    public function deleteAll(): void;
}
