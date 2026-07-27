<?php

declare(strict_types=1);

namespace App\Service\Version;

interface ReleaseVersionReader
{
    /**
     * @throws Exception\MalformedVersionFileException when a version file exists
     *                                                 but cannot be trusted
     */
    public function read(): ReleaseVersion;
}
