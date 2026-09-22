<?php

declare(strict_types=1);

namespace App\Service\Search;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * A saved search's stable URL slug: its id, then a readable slug of its term.
 * The id prefix makes the slug unique; the term half is cosmetic, so an
 * operator-only term that slugifies to nothing leaves just the id.
 */
final readonly class SavedSearchSlug
{
    public function __construct(private SluggerInterface $slugger)
    {
    }

    public function build(int $id, string $term): string
    {
        $readable = $this->slugger->slug($term)->lower()->toString();

        return $readable === '' ? (string) $id : $id . '-' . $readable;
    }
}
