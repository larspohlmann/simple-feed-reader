<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\DigestSettingsRepository;
use App\Repository\EntryListRepository;
use App\Repository\ForYouStreamEntryRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Outbound\Mail\Digest\DigestComposer;
use App\Service\Outbound\Mail\Digest\DigestEntryFinder;
use App\Service\Outbound\Mail\Digest\DigestLinkBuilder;
use App\Service\Outbound\Mail\Digest\DigestSavedSearches\DigestSavedSearchesInterface;

trait BuildsDigestComposers
{
    private function digestComposer(DigestSavedSearchesInterface $savedSearches): DigestComposer
    {
        return new DigestComposer(
            $savedSearches,
            $this->digestEntryFinder(),
            new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example')),
            $this->containerService(DigestSettingsRepository::class),
        );
    }

    private function digestEntryFinder(): DigestEntryFinder
    {
        return new DigestEntryFinder(
            $this->containerService(SavedSearchEntryRepository::class),
            $this->containerService(EntryListRepository::class),
            $this->containerService(ForYouStreamEntryRepository::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function containerService(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
