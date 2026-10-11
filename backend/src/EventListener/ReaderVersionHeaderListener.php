<?php

declare(strict_types=1);

namespace App\EventListener;

use App\DependencyInjection\ReaderOutputVersionPass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Every response names the current reader-output version, so a client can judge its cached articles before reading one. */
final readonly class ReaderVersionHeaderListener
{
    public const string HEADER = 'Reader-Version';

    public function __construct(
        #[Autowire(param: ReaderOutputVersionPass::PARAMETER)] private string $readerVersion,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER, $this->readerVersion);
    }
}
