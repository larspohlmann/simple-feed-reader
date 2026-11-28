<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\YourStreamSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/** No row means the stream runs at YourStreamTuning::defaults(). */
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

    public function __construct(User $user, YourStreamTuning $tuning)
    {
        $this->user = $user;
        $this->tuning = $tuning;
    }

    public function getUser(): User
    {
        return $this->user;
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
