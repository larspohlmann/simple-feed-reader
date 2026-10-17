<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Service\Backup\AccountBackupExporter;
use Psr\Container\ContainerInterface;

/** The foundation part of a real export: the only part AccountRestorer::start() reads. */
final class ExportedFoundation
{
    public static function of(ContainerInterface $container, User $user): string
    {
        /** @var AccountBackupExporter $exporter */
        $exporter = $container->get(AccountBackupExporter::class);
        foreach ($exporter->parts($user, 'https://source.example') as $part) {
            if ('000-foundation.ndjson.gz' === $part->memberName) {
                return $part->gzipBytes;
            }
        }

        throw new \LogicException('The exporter produced no foundation part.');
    }
}
