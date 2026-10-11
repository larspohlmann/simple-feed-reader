<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\DependencyInjection\ReaderOutputVersionPass;
use App\EventListener\ReaderVersionHeaderListener;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReaderVersionHeaderListenerTest extends WebTestCase
{
    public function testEveryResponseNamesTheReaderOutputVersion(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/health');

        self::assertResponseHeaderSame(
            ReaderVersionHeaderListener::HEADER,
            self::getContainer()->getParameter(ReaderOutputVersionPass::PARAMETER),
        );
    }
}
