<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Repository\LinkCardRepository;
use App\Service\LinkCard\Exception\FetchBudgetSpentException;
use App\Service\LinkCard\Pass\FetchBudget;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\Sanitize\EntrySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Links a post's bare URLs and stores a card for each page it shares that declares one. */
final readonly class PostCardWriter
{
    public function __construct(
        private PageCardReader $pageCards,
        private EntrySanitizer $sanitizer,
        private LinkCardRepository $linkCards,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws FetchBudgetSpentException before it changes the post */
    public function write(Entry $post, FetchBudget $budget): void
    {
        $body = (string) $post->getContentHtml();
        $cards = $this->cardsFor(SharedLinks::in($body), $budget);

        $linked = SharedLinks::linked($body);
        if ($linked !== $body) {
            $post->setContentHtml($this->sanitizer->sanitize($linked));
        }
        $this->linkCards->replaceFor($post, $cards);
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $sharedUrls
     *
     * @return list<LinkCardContent>
     */
    private function cardsFor(array $sharedUrls, FetchBudget $budget): array
    {
        $cards = [];
        foreach ($sharedUrls as $sharedUrl) {
            $budget->spendOne();
            try {
                $cards[] = $this->pageCards->read($sharedUrl);
            } catch (\Exception $exception) {
                $this->logger->warning(
                    'No link card for {url}',
                    ['url' => $sharedUrl, 'exception' => $exception::class],
                );
            }
        }

        return array_values(array_filter($cards));
    }
}
