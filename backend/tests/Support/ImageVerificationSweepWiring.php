<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\PendingImageVerificationRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** ImageVerificationSweep over a stub downloader and no rendition probes, on one shared clock. */
final class ImageVerificationSweepWiring
{
    private function __construct()
    {
    }

    public static function build(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        StubGuardedImageDownloader $downloader,
        MockClock $clock,
    ): ImageVerificationSweep {
        $pendingVerifications = $container->get(PendingImageVerificationRepository::class);
        $verifier = new ImageVerifier(
            $downloader,
            new NaiveUtcClock($clock),
            RenditionProbes::none(),
            new StubProbedLadders(),
        );

        return new ImageVerificationSweep($pendingVerifications, $verifier, $entityManager, $clock);
    }
}
