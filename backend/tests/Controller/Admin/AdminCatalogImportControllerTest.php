<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CatalogFeed;
use App\Service\Catalog\BundledCatalog;
use App\Service\Catalog\ParsedCatalog;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminCatalogImportControllerTest extends WebTestCase
{
    /**
     * @param list<string> $roles
     *
     * @return array<string, string>
     */
    private function authHeader(string $email, array $roles): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = (new UserFactory($em, $hasher))->create($email, roles: $roles);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /** @param list<array{title: string, url: string}> $feeds */
    private function payload(array $feeds, string $mode): string
    {
        $outlines = '';
        foreach ($feeds as $feed) {
            $outlines .= \sprintf(
                '<outline type="rss" text="%s" xmlUrl="%s"/>',
                htmlspecialchars($feed['title'], \ENT_XML1),
                htmlspecialchars($feed['url'], \ENT_XML1),
            );
        }

        $document = \sprintf(
            '<opml version="2.0"><head><title>t</title></head><body>'
            . '<outline text="Technology" key="technology" icon="memory" color="#3b82f6">%s</outline>'
            . '</body></opml>',
            $outlines,
        );

        return json_encode(['mode' => $mode, 'document' => $document], \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function responseBody(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testANonAdminIsRefused(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/admin/catalog/import',
            server: $this->authHeader('plain@example.com', []),
            content: $this->payload([], 'merge'),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanImportAndGetsCounts(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('importer@example.com', ['ROLE_ADMIN']);

        $client->request(
            'POST',
            '/api/admin/catalog/import',
            server: $headers,
            content: $this->payload(
                [['title' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml']],
                'merge',
            ),
        );

        self::assertResponseIsSuccessful();
        $body = $this->responseBody($client);

        self::assertSame(1, $body['categoriesCreated']);
        self::assertSame(1, $body['feedsCreated']);
    }

    public function testAMalformedDocumentChangesNothingAndReturns422(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('badimport@example.com', ['ROLE_ADMIN']);

        $badDocument = '<opml version="2.0"><head><title>t</title></head><body>'
            . '<outline text="X" key="x" icon="memory" color="not-a-colour"/>'
            . '</body></opml>';

        $client->request(
            'POST',
            '/api/admin/catalog/import',
            server: $headers,
            content: json_encode(['mode' => 'merge', 'document' => $badDocument], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertCount(0, $em->getRepository(CatalogFeed::class)->findAll());
    }

    public function testAnUnknownModeIsRejected(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('mode@example.com', ['ROLE_ADMIN']);

        $client->request(
            'POST',
            '/api/admin/catalog/import',
            server: $headers,
            content: $this->payload([], 'obliterate'),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheBundledDocumentIsDescribedWithoutImportingIt(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('bundledinfo@example.com', ['ROLE_ADMIN']);

        $client->request('GET', '/api/admin/catalog/bundled', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->responseBody($client);

        $document = $this->bundledDocument();
        self::assertTrue($body['available']);
        self::assertSame(\count($document->categories), $body['categories']);
        self::assertSame($document->feedCount(), $body['feeds']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertCount(0, $em->getRepository(CatalogFeed::class)->findAll(), 'describing must not import');
    }

    public function testTheBundledDocumentCanBeImportedWithoutUploadingAFile(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('bundled@example.com', ['ROLE_ADMIN']);

        $client->request(
            'POST',
            '/api/admin/catalog/import/bundled',
            server: $headers,
            content: json_encode(['mode' => 'merge'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $body = $this->responseBody($client);

        $document = $this->bundledDocument();
        self::assertSame(\count($document->categories), $body['categoriesCreated']);
        self::assertSame($document->feedCount(), $body['feedsCreated']);
    }

    private function bundledDocument(): ParsedCatalog
    {
        $catalog = self::getContainer()->get(BundledCatalog::class);
        self::assertInstanceOf(BundledCatalog::class, $catalog);

        return $catalog->document();
    }
}
