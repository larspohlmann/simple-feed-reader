<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FeedDirectoryScopeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One row per account, created with it by UserSettingsRowsListener. */
#[ORM\Entity(repositoryClass: FeedDirectoryScopeRepository::class)]
#[ORM\Table(name: 'user_feed_directory_scope')]
final class FeedDirectoryScope
{
    use KeyedByUser;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $countries = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $languages = [];

    /** @return list<string> */
    public function getCountries(): array
    {
        return $this->countries;
    }

    /** @return list<string> */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    /**
     * @param list<string> $countries
     * @param list<string> $languages
     */
    public function change(array $countries, array $languages): void
    {
        $this->countries = $countries;
        $this->languages = $languages;
    }
}
