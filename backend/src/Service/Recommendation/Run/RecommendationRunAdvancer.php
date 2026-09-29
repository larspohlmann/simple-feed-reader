<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Factory\ProviderConnectionFactory;
use App\Service\Ai\Model\ProviderTimeoutsModel;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Exception\RecommendationTickLockLostException;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The driver-agnostic tick (#311): the worker, the poll endpoint and the cron sweep all call advance(), one tick
 * per account at a time behind a per-user lock. TickPhases decides what the tick does.
 */
final readonly class RecommendationRunAdvancer
{
    private const string LOCK_NAME_PREFIX = 'ai-recommendations-';

    /**
     * Headroom over the longest silence a live holder produces: loading and packing before a request, banking
     * between waves, the whole snapshot tick (#444). Public so the test pins the TTL against its two inputs.
     */
    public const float LOCK_TTL_MARGIN_SECONDS = 300.0;

    public function __construct(
        private RecommendationRunRepository $runs,
        private LockFactory $lockFactory,
        private AiProviderConfigurator $configurator,
        private ProviderConnectionFactory $connections,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
        private RecommendationSettingsResolver $settingsResolver,
        private TickLockKeepalive $keepalive,
        private TickPhases $phases,
    ) {
    }

    /** The one place the lock's name is formed; RecommendationPollDriver logs this very name (#439). */
    public static function lockNameFor(User $user): string
    {
        return self::LOCK_NAME_PREFIX . $user->requireId();
    }

    public function advance(User $user, TickDriver $driver = TickDriver::Poll): RecommendationRunReportModel
    {
        $lockName = self::lockNameFor($user);
        $lock = $this->lockFactory->createLock($lockName, $this->lockTtlFor($user));

        if (!$lock->acquire()) {
            // Silent: a failed acquire is the healthy, frequent case; only the poll driver can tell a stall (#439).
            return RecommendationRunReportModel::busy();
        }

        // A hard request kill (Strato's 240 s cap) never reaches the finally below and would strand the lock for
        // its whole TTL. The delete is token-scoped, so on the normal path this hook is a harmless no-op.
        register_shutdown_function(static function () use ($lock): void {
            try {
                $lock->release();
            } catch (\Throwable) {
                // A failed release during shutdown must not raise a second fatal; the TTL still bounds the stall.
            }
        });

        $this->keepalive->hold($lock, $lockName);

        try {
            return $this->tick($user, $driver);
        } finally {
            // Disarmed before the release, never after: a beat in between would refresh a lock on its way out.
            $this->keepalive->release();
            $lock->release();
        }
    }

    /**
     * The TTL clears the longest silence a live holder produces, one first-byte wait, not the whole tick: the
     * keepalive refreshes the lock on streamed chunks (#444). A slow-marked connection waits longer (#433).
     */
    private function lockTtlFor(User $user): float
    {
        $settings = $this->configurator->settingsFor($user);
        $timeouts = null === $settings
            ? ProviderTimeoutsModel::standard()
            : $this->connections->timeoutsFor($settings);

        return $timeouts->firstByteSeconds + self::LOCK_TTL_MARGIN_SECONDS;
    }

    private function tick(User $user, TickDriver $driver): RecommendationRunReportModel
    {
        $run = $this->runs->findActiveForUser($user);

        if (null === $run) {
            $latest = $this->runs->findLatestForUser($user);

            return null === $latest
                ? RecommendationRunReportModel::none()
                : RecommendationRunReportModel::fromRun($latest);
        }

        try {
            return $this->phases->advance(new TickContext(
                $run,
                $this->activeConnection($user),
                $this->settingsResolver->forUser($user),
                $driver,
            ));
        } catch (RecommendationRunCancelledException | RecommendationTickLockLostException) {
            // Stopped by the user or by a lost lock (#444): drop this tick's work, re-read the row its owner wrote.
            $this->entityManager->refresh($run);

            return RecommendationRunReportModel::fromRun($run);
        } catch (AiNotConfiguredException | AiKeyUnreadableException $e) {
            // Such a run can never advance again, so it fails here for every driver (#311), and the error still
            // propagates to the HTTP mapping and the worker's fault floor.
            $this->failPermanently($run, self::failureMessageFor($e));

            throw $e;
        }
    }

    private function activeConnection(User $user): AiProviderSettings
    {
        $connection = $this->configurator->requireConfiguration($user);
        if (!$connection->hasModel()) {
            throw new AiNotConfiguredException('No model is chosen.');
        }

        return $connection;
    }

    private function failPermanently(RecommendationRun $run, string $message): void
    {
        $run->fail($message, $this->clock->now());
        $this->entityManager->flush();
    }

    private static function failureMessageFor(AiNotConfiguredException | AiKeyUnreadableException $e): string
    {
        return $e instanceof AiKeyUnreadableException
            ? 'The stored API key can no longer be read.'
            : 'The AI provider is no longer configured.';
    }
}
