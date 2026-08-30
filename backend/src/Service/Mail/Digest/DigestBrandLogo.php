<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Service\Mail\Digest\Exception\ImageProcessingException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The fixed brand mark embedded in the digest header (#726), read straight from
 * disk — unlike article thumbnails and favicons, it is never fetched or resized.
 */
final readonly class DigestBrandLogo
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function bytes(): string
    {
        $bytes = file_get_contents($this->projectDir . '/resources/mail/digest-logo.png');
        if ($bytes === false) {
            throw new ImageProcessingException('The digest brand logo asset is missing.');
        }

        return $bytes;
    }

    public function contentType(): string
    {
        return 'image/png';
    }
}
