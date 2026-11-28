<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\YourStreamSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: YourStreamSettingsRepository::class)]
#[ORM\Table(name: 'your_stream_settings')]
final class YourStreamSettings
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Embedded(class: YourStreamTuning::class, columnPrefix: false)]
    private YourStreamTuning $tuning;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, YourStreamTuning $tuning)
    {
        $this->user = $user;
        $this->tuning = $tuning;
    }

    public function getTuning(): YourStreamTuning
    {
        return $this->tuning;
    }

    public function changeTuning(YourStreamTuning $tuning): void
    {
        $this->tuning = $tuning;
    }
}
