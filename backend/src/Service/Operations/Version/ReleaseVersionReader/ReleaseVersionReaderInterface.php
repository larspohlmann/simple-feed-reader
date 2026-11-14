<?php

declare(strict_types=1);

namespace App\Service\Version\ReleaseVersionReader;

use App\Service\Version\Exception\MalformedVersionFileException;
use App\Service\Version\Model\ReleaseVersionModel;

interface ReleaseVersionReaderInterface
{
    /**
     * @throws MalformedVersionFileException when a version file exists
     *                                                 but cannot be trusted
     */
    public function read(): ReleaseVersionModel;
}
