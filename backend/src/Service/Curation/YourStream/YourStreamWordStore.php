<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\User;
use App\Entity\YourStreamState;
use App\Entity\YourStreamWord;
use App\Repository\YourStreamStateRepository;
use App\Repository\YourStreamWordRepository;
use App\Service\Curation\YourStream\Model\StreamWordModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\SettledCeiling;
use Doctrine\ORM\EntityManagerInterface;

final readonly class YourStreamWordStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private YourStreamStateRepository $states,
        private YourStreamWordRepository $words,
        private SettledCeiling $ceiling,
        private NaiveUtcClock $clock,
    ) {
    }

    /** A new state starts its mark at the settled ceiling, so the stream never backfills. */
    public function ensureState(User $user): YourStreamState
    {
        $state = $this->states->findForUser($user);
        if (null !== $state) {
            return $state;
        }
        $state = new YourStreamState($user, $this->ceiling->readSettledCeiling());
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        return $state;
    }

    /** @param list<StreamWordModel> $words */
    public function replaceWords(YourStreamState $state, string $fingerprint, array $words): void
    {
        $this->entityManager->wrapInTransaction(function () use ($state, $fingerprint, $words): void {
            $user = $state->getUser();
            $this->words->deleteForUser($user->requireId());
            foreach ($words as $word) {
                $this->entityManager->persist(new YourStreamWord(
                    $user,
                    $word->language,
                    $word->stem,
                    mb_substr($word->word, 0, YourStreamWord::WORD_MAX_LENGTH),
                    $word->weight,
                    $word->rarity,
                ));
            }
            $state->recordBuilt($fingerprint, $this->clock->now());
            $this->entityManager->flush();
        });
    }

    public function recordChecked(YourStreamState $state): void
    {
        $state->recordChecked($this->clock->now());
        $this->entityManager->flush();
    }
}
