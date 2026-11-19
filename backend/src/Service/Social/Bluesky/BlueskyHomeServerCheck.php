<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Social\BlueskyPost\Support\AtPostUri;
use App\Service\Social\Bluesky\BlueskyApiClient;
use App\Service\Social\Bluesky\BlueskyIdentityResolver;
use App\Service\Social\Bluesky\Model\BlueskyIdentityModel;
use App\Service\Social\Bluesky\Support\BlueskyRecordUri;
use App\Service\Social\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Social\HomeServerCheck\HomeServerCheckInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks the author's own PDS, anonymously, for the post record: only its RecordNotFound proves the post deleted.
 * A post named by handle proves nothing, as the handle may since belong to another account.
 */
#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyHomeServerCheck implements HomeServerCheckInterface
{
    private const string GET_RECORD = '/xrpc/com.atproto.repo.getRecord?';
    private const string RECORD_NOT_FOUND = 'RecordNotFound';

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskyIdentityResolver $identities,
    ) {
    }

    public function confirmsDeleted(string $address): bool
    {
        $author = AtPostUri::authorityOf($address);
        $recordKey = BlueskyRecordUri::recordKeyOf($address);
        if (null === $author || null === $recordKey) {
            return false;
        }
        try {
            $identity = $this->identities->resolve($author);
        } catch (SocialAccountUnresolvableException) {
            return false;
        }

        return $this->pdsHasNoRecord($identity, $recordKey);
    }

    private function pdsHasNoRecord(BlueskyIdentityModel $identity, string $recordKey): bool
    {
        $query = http_build_query(
            ['repo' => $identity->did, 'collection' => BlueskyRecordUri::POST, 'rkey' => $recordKey],
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );
        $answer = $this->bluesky->answer(ApiRequestModel::get($identity->pdsUrl . self::GET_RECORD . $query));

        return Response::HTTP_BAD_REQUEST === $answer->status
            && self::RECORD_NOT_FOUND === JsonNodeModel::of($answer->json())->string('error');
    }
}
