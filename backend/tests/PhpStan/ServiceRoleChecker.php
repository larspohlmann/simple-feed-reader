<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

interface ServiceRoleChecker
{
    /** @return list<ServiceRoleViolation> */
    public function violationsIn(ServiceRoleMap $map): array;
}
