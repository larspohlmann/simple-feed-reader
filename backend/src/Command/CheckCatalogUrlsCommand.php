<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\CatalogDocument;
use App\Service\Catalog\Exception\BrokenCatalogUrlException;
use App\Service\Fetch\EgressOptions;
use App\Service\Fetch\ProxyConfig;
use App\Service\Fetch\ProxyEgressResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches every URL in resources/catalog/catalog.opml and reports the ones that
 * no longer serve a feed. Reads the SHIPPED DOCUMENT, not the database: this
 * checks what we hand a new install, which is the thing that rots unnoticed.
 *
 * Run on a schedule, never as a PR gate — 111 publisher domains produce enough
 * rate limits, bot blocks and transient outages to make a merge check useless.
 */
#[AsCommand(
    name: 'app:catalog:check-urls',
    description: 'Verify every catalog URL still serves a feed',
)]
final class CheckCatalogUrlsCommand extends Command
{
    private const int TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CatalogDocument $parser,
        private readonly string $userAgent,
        private readonly ProxyEgressResolver $proxyEgressResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Check at most this many URLs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $document = $this->parser->parse(
            (string) file_get_contents(\dirname(__DIR__, 2) . '/resources/catalog/catalog.opml'),
        );

        $feeds = [];
        foreach ($document->categories as $category) {
            foreach ($category->feeds as $feed) {
                $feeds[] = $feed;
            }
        }

        $limit = $this->limit($input);
        if (null !== $limit) {
            $feeds = \array_slice($feeds, 0, $limit);
        }

        // Resolved once for the whole sweep, not per URL: the instance proxy
        // cannot change mid-run, and re-reading it would cost one row lookup and
        // one password decryption for every catalog entry.
        $proxy = $this->proxyEgressResolver->resolve();

        $broken = [];
        foreach ($feeds as $feed) {
            try {
                $this->assertServesFeed($feed->url, $proxy);
            } catch (BrokenCatalogUrlException $e) {
                $broken[] = \sprintf('%s (%s): %s', $feed->title, $feed->url, $e->getMessage());
            }
        }

        if ([] === $broken) {
            $io->success(\sprintf('All %d catalog URLs still serve a feed.', \count($feeds)));

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d of %d catalog URLs need attention:', \count($broken), \count($feeds)));
        $io->listing($broken);

        return Command::FAILURE;
    }

    private function limit(InputInterface $input): ?int
    {
        $value = $input->getOption('limit');
        if (!\is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return max(1, (int) $value);
    }

    /** @throws BrokenCatalogUrlException */
    private function assertServesFeed(string $url, ?ProxyConfig $proxy): void
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                // The fetcher's agent: a publisher tolerating an unknown checker must not pass for healthy.
                'headers' => ['User-Agent' => $this->userAgent],
                ...(null !== $proxy ? EgressOptions::proxied($proxy) : []),
            ]);
            $status = $response->getStatusCode();
            $head = 200 === $status ? mb_substr($response->getContent(), 0, 2048) : '';
        } catch (ExceptionInterface $e) {
            throw new BrokenCatalogUrlException($e->getMessage(), 0, $e);
        }

        if (200 !== $status) {
            throw new BrokenCatalogUrlException('HTTP ' . $status);
        }
        if (!str_contains($head, '<rss') && !str_contains($head, '<feed') && !str_contains($head, '<rdf:RDF')) {
            throw new BrokenCatalogUrlException('not a feed document');
        }
    }
}
