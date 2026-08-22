<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\CatalogDocument;
use App\Service\Fetch\EgressOptions;
use App\Service\Fetch\ProxyEgressResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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

        $broken = [];
        foreach ($feeds as $feed) {
            $failure = $this->check($feed->url);
            if (null !== $failure) {
                $broken[] = \sprintf('%s (%s): %s', $feed->title, $feed->url, $failure);
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

    /**
     * @return string|null the reason it is broken, or null when it is fine
     */
    private function check(string $url): ?string
    {
        $proxy = $this->proxyEgressResolver->resolve();

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                // The fetcher's agent, not one of its own: a publisher that blocks
                // the reader but tolerates an unfamiliar checker would otherwise
                // let this command report a healthy catalog nobody can subscribe to.
                'headers' => ['User-Agent' => $this->userAgent],
                ...(null !== $proxy ? EgressOptions::proxied($proxy) : []),
            ]);

            if (200 !== $response->getStatusCode()) {
                return 'HTTP ' . $response->getStatusCode();
            }

            // A prefix is enough: a feed announces itself in its root element.
            $head = mb_substr($response->getContent(), 0, 2048);
            $isFeed = str_contains($head, '<rss')
                || str_contains($head, '<feed')
                || str_contains($head, '<rdf:RDF');

            return $isFeed ? null : 'not a feed document';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
