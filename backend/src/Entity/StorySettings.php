<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StorySettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/** No row = the defaults; the row exists once the account saves its story settings. */
#[ORM\Entity(repositoryClass: StorySettingsRepository::class)]
#[ORM\Table(name: 'user_story_settings')]
final class StorySettings
{
    use KeyedByUser;

    public const bool DEFAULT_TITLES_ENABLED = true;

    /** The connection that writes story text; null means the active one. */
    #[ORM\ManyToOne(targetEntity: AiProviderSettings::class)]
    #[ORM\JoinColumn(name: 'writing_connection_id', nullable: true, onDelete: 'SET NULL')]
    private ?AiProviderSettings $writingConnection = null;

    #[ORM\Column(options: ['default' => self::DEFAULT_TITLES_ENABLED])]
    private bool $titlesEnabled = self::DEFAULT_TITLES_ENABLED;

    public function update(?AiProviderSettings $writingConnection, bool $titlesEnabled): void
    {
        $this->writingConnection = $writingConnection;
        $this->titlesEnabled = $titlesEnabled;
    }

    public function getWritingConnection(): ?AiProviderSettings
    {
        return $this->writingConnection;
    }

    public function isTitlesEnabled(): bool
    {
        return $this->titlesEnabled;
    }

    public function forgetConnection(AiProviderSettings $connection): void
    {
        if ($this->writingConnection === $connection) {
            $this->writingConnection = null;
        }
    }
}
