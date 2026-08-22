<?php

declare(strict_types=1);

namespace App\Tests\Service\Version;

use App\Service\Version\GitHubLatestReleaseReader;
use App\Service\Version\LatestRelease;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use PHPUnit\Framework\TestCase;

final class GitHubLatestReleaseReaderTest extends TestCase
{
    private const string REPOSITORY = 'larspohlmann/simple-feed-reader';

    public function testReadsTheTagAndNotesUrlFromTheLatestRelease(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode([
            'tag_name' => 'v1.4.2',
            'html_url' => 'https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.4.2',
        ])));

        $latest = $this->reader($client)->read();

        self::assertInstanceOf(LatestRelease::class, $latest);
        self::assertSame('v1.4.2', $latest->version);
        self::assertSame(
            'https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.4.2',
            $latest->notesUrl,
        );
    }

    public function testReturnsNullWhenNoReleaseHasBeenCut(): void
    {
        $client = new MockHttpClient(new MockResponse('{"message":"Not Found"}', ['http_code' => 404]));

        self::assertNull($this->reader($client)->read());
    }

    public function testReturnsNullWhenGitHubCannotBeReached(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('network down');
        });

        self::assertNull($this->reader($client)->read());
    }

    public function testDoesNotReachGitHubWhenNoRepositoryIsConfigured(): void
    {
        $client = new MockHttpClient();

        $latest = $this->reader($client, repository: '')->read();

        self::assertNull($latest);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testCachesTheResultSoASecondReadMakesNoRequest(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode([
            'tag_name' => 'v1.4.2',
            'html_url' => 'https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.4.2',
        ])));
        $reader = $this->reader($client);

        $reader->read();
        $second = $reader->read();

        self::assertInstanceOf(LatestRelease::class, $second);
        self::assertSame('v1.4.2', $second->version);
        self::assertSame(1, $client->getRequestsCount());
    }

    private function reader(MockHttpClient $client, string $repository = self::REPOSITORY): GitHubLatestReleaseReader
    {
        return new GitHubLatestReleaseReader(
            $client,
            new ArrayAdapter(),
            new NullLogger(),
            $repository,
            'SimpleFeedReader/1.0',
        );
    }
}
