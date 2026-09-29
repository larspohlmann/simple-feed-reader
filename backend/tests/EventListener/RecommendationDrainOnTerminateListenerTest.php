<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Kernel;
use App\Service\Mail\DeferredMailer;
use App\Service\Process\DetachedProcessLauncher\DetachedProcessLauncherInterface;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\RecommendationDrainSpawner;
use App\Service\Recommendation\Run\WorkerPresence;
use App\Tests\Support\RecordingProcessLauncher;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Drives the real kernel with the container's RecordingProcessLauncher (services_test.yaml): handle() never launches,
 * terminate() may, and a console exit never does.
 */
final class RecommendationDrainOnTerminateListenerTest extends KernelTestCase
{
    private Kernel $bootedKernel;
    private EntityManagerInterface $entityManager;
    private RecordingProcessLauncher $launcher;
    private User $user;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $this->bootedKernel = $kernel;

        $this->launcher = new RecordingProcessLauncher();
        self::getContainer()->set(DetachedProcessLauncherInterface::class, $this->launcher);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('drain-terminate@example.test');
    }

    public function testHandleAloneSpawnsNothingAndTerminateSpawnsExactlyOnce(): void
    {
        $this->persistActiveRun();

        $request = $this->healthRequest();
        $response = $this->bootedKernel->handle($request);

        self::assertSame([], $this->launcher->launches, 'handle() must not spawn before terminate runs');

        $this->bootedKernel->terminate($request, $response);

        self::assertSame([[RecommendationDrainSpawner::DRAIN_COMMAND, '--detach']], $this->launcher->launches);
    }

    /** No heartbeat is marked, so an empty launch list proves the hasActiveRun() guard, not a presence read. */
    public function testNoActiveRunSpawnsNothingAndNeverReachesThePresenceRead(): void
    {
        $request = $this->healthRequest();
        $response = $this->bootedKernel->handle($request);
        $this->bootedKernel->terminate($request, $response);

        self::assertSame([], $this->launcher->launches);
    }

    public function testAFreshWorkerHeartbeatSuppressesTheSpawn(): void
    {
        $this->persistActiveRun();
        $this->presence()->mark(RecommendationDriverKind::PersistentWorker);

        $request = $this->healthRequest();
        $response = $this->bootedKernel->handle($request);
        $this->bootedKernel->terminate($request, $response);

        self::assertSame([], $this->launcher->launches);
    }

    /**
     * No console exit forks a drainer. A run is active and no heartbeat is marked, so an empty launch list means the
     * listener is not on this event.
     *
     * @param non-empty-string $commandName
     */
    #[DataProvider('consoleCommands')]
    public function testNoConsoleCommandSpawnsADrainer(string $commandName): void
    {
        $this->persistActiveRun();

        $this->dispatchConsoleTerminate($commandName);

        self::assertSame([], $this->launcher->launches);
    }

    /**
     * The control for the console cases: the same dispatch reaches DeferredMailFlushListener, which is on
     * ConsoleTerminateEvent, so their empty launch lists are not a dispatch that reached nobody.
     */
    public function testTheConsoleTerminateDispatchReachesTheListenersThatAreOnIt(): void
    {
        $mailer = $this->deferredMailer();
        $mailer->send(new RawMessage('positive control'), new Envelope(
            new Address('control@example.test'),
            [new Address('drain-terminate@example.test')],
        ));
        self::assertTrue($mailer->hasQueuedMail());

        $this->dispatchConsoleTerminate('app:feeds:refresh');

        self::assertFalse(
            $mailer->hasQueuedMail(),
            'console.terminate must reach the listeners registered on it, or the absence cases prove nothing',
        );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function consoleCommands(): iterable
    {
        yield 'the drainer itself' => [RecommendationDrainSpawner::DRAIN_COMMAND];
        yield 'the e2e purge that runs beside a stopped worker' => ['app:e2e:purge-users'];
        yield 'an unrelated command' => ['app:feeds:refresh'];
    }

    /** A closed EntityManager (MaintenanceTick's aborted refresh) must neither launch nor throw after the response. */
    public function testAClosedEntityManagerIsSurvivedWithoutLaunchingOrThrowing(): void
    {
        $this->persistActiveRun();
        $this->entityManager->close();

        $request = $this->healthRequest();
        $response = $this->bootedKernel->handle($request);
        $this->bootedKernel->terminate($request, $response);

        self::assertSame([], $this->launcher->launches);
    }

    private function persistActiveRun(): void
    {
        $run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-16T09:00:00Z'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();
    }

    private function healthRequest(): Request
    {
        return Request::create('/api/health');
    }

    private function dispatchConsoleTerminate(string $commandName): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $event = new ConsoleTerminateEvent(new Command($commandName), new ArrayInput([]), new NullOutput(), 0);
        $dispatcher->dispatch($event, ConsoleEvents::TERMINATE);
    }

    private function deferredMailer(): DeferredMailer
    {
        /** @var DeferredMailer $mailer */
        $mailer = self::getContainer()->get(DeferredMailer::class);

        return $mailer;
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }
}
