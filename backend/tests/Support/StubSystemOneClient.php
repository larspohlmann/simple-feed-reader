<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel;
use App\Service\Recommendation\Jev\Model\SystemOneRequestModel;
use App\Service\Recommendation\Jev\Support\SystemOneReplyDecoder;
use App\Service\Recommendation\Jev\SystemOneClient\SystemOneClientInterface;

/**
 * The test container's SystemOneClientInterface: records every request and answers each from one FIFO queue, so
 * "rate limited, then answered" keeps its order. Replies are shaped and decoded like OpenRouter's, which sends no
 * request-id header.
 */
final class StubSystemOneClient implements SystemOneClientInterface
{
    public const string REQUEST_ID = 'gen-dec-1790947706-stub';
    public const string ANSWERING_MODEL = 'typesafe/jev-1.13-20260917';
    public const int INPUT_TOKENS = 1200;
    public const int COST_NANO_CREDITS = 4_200_000;

    /** @var list<\Closure(SystemOneRequestModel): SystemOneOutcomeModel> */
    private array $queue = [];

    /** @var list<SystemOneRequestModel> */
    private array $requests = [];

    private ?\Closure $duringNextCall = null;

    /** @param \Closure(int): float $nouls each question's Noul, by the candidate's entry id */
    public function queueNouls(\Closure $nouls): void
    {
        $this->queue[] = static fn (SystemOneRequestModel $request): SystemOneOutcomeModel
            => SystemOneOutcomeModel::answered(
                SystemOneReplyDecoder::decode(self::replyBody($request, $nouls), null),
            );
    }

    public function queueBody(string $body): void
    {
        $this->queue[] = static fn (): SystemOneOutcomeModel
            => SystemOneOutcomeModel::answered(SystemOneReplyDecoder::decode($body, null));
    }

    public function queueFailure(\RuntimeException $failure): void
    {
        $this->queue[] = static fn (): SystemOneOutcomeModel => SystemOneOutcomeModel::failed($failure);
    }

    /** Runs inside the next evaluateMany(), before it answers: the provider call is where a tick can change underneath. */
    public function duringNextCall(\Closure $hook): void
    {
        $this->duringNextCall = $hook;
    }

    /** @return list<SystemOneRequestModel> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function evaluateMany(ProviderCredentialsModel $credentials, array $requests): array
    {
        $hook = $this->duringNextCall;
        $this->duringNextCall = null;
        if (null !== $hook) {
            $hook();
        }

        return array_map(function (SystemOneRequestModel $request): SystemOneOutcomeModel {
            $this->requests[] = $request;
            $script = array_shift($this->queue) ?? throw new \LogicException('No System One reply is queued.');

            return $script($request);
        }, $requests);
    }

    /** @param \Closure(int): float $nouls */
    private static function replyBody(SystemOneRequestModel $request, \Closure $nouls): string
    {
        $answers = [];
        foreach (array_keys($request->questions) as $questionId) {
            $entryId = (int) substr((string) $questionId, \strlen('entry-'));
            $answers[$questionId] = ['type' => 'noul', 'noul' => $nouls($entryId)];
        }

        return json_encode([
            'model' => self::ANSWERING_MODEL,
            'answers' => $answers,
            'usage' => ['input_tokens' => self::INPUT_TOKENS, 'output_tokens' => 30, 'cost' => 0.0042],
            'id' => self::REQUEST_ID,
            'provider' => 'TypeSafe',
        ], \JSON_THROW_ON_ERROR);
    }
}
