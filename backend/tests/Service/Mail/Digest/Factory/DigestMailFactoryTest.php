<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest\Factory;

use App\Entity\User;
use App\Enum\DigestFormat;
use App\Repository\DigestSettingsRepository;
use App\Service\Mail\Digest\DigestBrandLogo;
use App\Service\Mail\Digest\DigestHtmlRenderer;
use App\Service\Mail\Digest\DigestImageEmbedder\DigestImageEmbedderInterface;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestTextRenderer;
use App\Service\Mail\Digest\Factory\DigestMailFactory;
use App\Service\Mail\Digest\Factory\DigestPageFactory;
use App\Service\Mail\Digest\Model\DigestEntryModel;
use App\Service\Mail\Digest\Model\DigestGroupModel;
use App\Service\Mail\Digest\Model\DigestImageSetModel;
use App\Service\Mail\Digest\Model\DigestModel;
use App\Service\Mail\Digest\Model\EmbeddedImageModel;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Mail\Settings\Model\MailIdentityModel;
use App\Tests\DbTestCase;
use App\Tests\Support\DigestTwigEnvironment;
use App\Tests\Support\FixedPublicBaseUrl;
use App\Tests\Support\StoredUserSettings;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class DigestMailFactoryTest extends DbTestCase
{
    private function factory(DigestImageSetModel $set): DigestMailFactory
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translationsDirectory = \dirname(__DIR__, 5) . '/translations';
        $translator->addResource('yaml', "{$translationsDirectory}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$translationsDirectory}/emails.de.yaml", 'de', 'emails');
        $links = new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example'));

        $embedder = $this->createStub(DigestImageEmbedderInterface::class);
        $embedder->method('embed')->willReturn($set);

        return new DigestMailFactory(
            new DigestPageFactory(),
            $embedder,
            new DigestTextRenderer($translator),
            new DigestHtmlRenderer(
                DigestTwigEnvironment::withTranslator($translator),
                $links,
                new MockClock('2026-08-30T12:00:00Z'),
            ),
            $links,
            new DigestBrandLogo(\dirname(__DIR__, 5)),
            $this->mailIdentity('noreply@feeds.example.com', 'Simple Feed Reader'),
            $this->digestSettings(),
        );
    }

    private function model(): DigestModel
    {
        $entry = new DigestEntryModel(
            'Rust 1.80',
            'Rust Blog',
            'Summary.',
            'https://reader.example/?entry=1',
            null,
            null,
            null,
        );

        return new DigestModel([new DigestGroupModel('rust', 1, [$entry], false, 'https://reader.example/?q=rust')], 1);
    }

    private function user(DigestFormat $format): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = new UserFactory($this->entityManager, $hasher)->create('reader@example.com');
        StoredUserSettings::digest($this->entityManager, $user)->setFormat($format);

        return $user;
    }

    public function testHtmlFormatBuildsAlternativeWithHtmlTextAndInlineImage(): void
    {
        $set = new DigestImageSetModel([new EmbeddedImageModel('imgFAV', 'PNGBYTES', 'image/png')], []);

        $email = $this->factory($set)->build($this->user(DigestFormat::Html), $this->model());

        self::assertNotNull($email->getHtmlBody());
        self::assertNotNull($email->getTextBody());
        $attachments = $email->getAttachments();
        self::assertCount(2, $attachments, 'The brand logo and the one embedded image are inline parts.');
        $filenames = array_map(static fn ($part) => $part->getFilename(), $attachments);
        self::assertContains(DigestHtmlRenderer::LOGO_CID, $filenames, 'The brand logo is embedded by its CID name.');
        $header = $email->getHeaders()->get('List-Unsubscribe');
        self::assertNotNull($header);
        self::assertSame('<https://reader.example/settings/email>', $header->getBodyAsString());
    }

    public function testTextFormatBuildsPlainTextOnly(): void
    {
        $email = $this->factory(new DigestImageSetModel([], []))
            ->build($this->user(DigestFormat::Text), $this->model());

        self::assertNull($email->getHtmlBody());
        self::assertNotNull($email->getTextBody());
        self::assertCount(0, $email->getAttachments());
    }

    private function digestSettings(): DigestSettingsRepository
    {
        $repository = self::getContainer()->get(DigestSettingsRepository::class);
        self::assertInstanceOf(DigestSettingsRepository::class, $repository);

        return $repository;
    }

    private function mailIdentity(string $address, string $name): MailSendingSettingsInterface
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('identity')->willReturn(new MailIdentityModel($address, $name));

        return $settings;
    }
}
