<?php

declare(strict_types=1);

namespace App\Service\Social\SocialCandidates;

use App\Entity\SocialAccount;
use App\Service\Social\CandidateSource\CandidateSourceInterface;
use App\Service\Social\Model\NetworkCandidatesModel;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NetworkSocialCandidates implements SocialCandidatesInterface
{
    public function __construct(
        #[AutowireLocator('app.social_candidate_source', defaultIndexMethod: 'network')]
        private ContainerInterface $sources,
    ) {
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $source = $this->sources->get($account->getNetwork()->value);
        \assert($source instanceof CandidateSourceInterface);

        return $source->candidates($account);
    }
}
