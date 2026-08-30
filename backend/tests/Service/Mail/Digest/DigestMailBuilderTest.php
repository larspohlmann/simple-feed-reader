<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Tests\Support\FixedPublicBaseUrl;
use App\Entity\User;
use App\Service\Mail\Digest\DigestBrandLogo;
use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestFormat;
use App\Service\Mail\Digest\DigestGroup;
use App\Service\Mail\Digest\DigestHtmlRenderer;
use App\Service\Mail\Digest\DigestImageEmbedderInterface;
use App\Service\Mail\Digest\DigestImageSet;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailBuilder;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\DigestPageBuilder;
use App\Service\Mail\Digest\DigestTextRenderer;
use App\Service\Mail\Digest\EmbeddedImage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class DigestMailBuilderTest extends TestCase
{
    private function builder(DigestImageSet $set): DigestMailBuilder
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $dir = \dirname(__DIR__, 4) . '/translations';
        $translator->addResource('yaml', "{$dir}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$dir}/emails.de.yaml", 'de', 'emails');
        $links = new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example'));

        $embedder = $this->createStub(DigestImageEmbedderInterface::class);
        $embedder->method('embed')->willReturn($set);

        return new DigestMailBuilder(
            new DigestPageBuilder(),
            $embedder,
            new DigestTextRenderer($translator),
            new DigestHtmlRenderer($translator, $links),
            $links,
            new DigestBrandLogo(\dirname(__DIR__, 4)),
            'noreply@feeds.example.com',
            'Simple Feed Reader',
        );
    }

    private function model(): DigestModel
    {
        $entry = new DigestEntry(
            'Rust 1.80',
            'Rust Blog',
            'Summary.',
            'https://reader.example/?entry=1',
            null,
            null,
            null,
        );

        return new DigestModel([new DigestGroup('rust', 1, [$entry], false, 'https://reader.example/?q=rust')], 1);
    }

    private function user(DigestFormat $format): User
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));
        $user->getPreferences()->setDigestFormat($format);

        return $user;
    }

    public function testHtmlFormatBuildsAlternativeWithHtmlTextAndInlineImage(): void
    {
        $set = new DigestImageSet([new EmbeddedImage('imgFAV', 'PNGBYTES', 'image/png')], []);

        $email = $this->builder($set)->build($this->user(DigestFormat::Html), $this->model());

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
        $email = $this->builder(new DigestImageSet([], []))->build($this->user(DigestFormat::Text), $this->model());

        self::assertNull($email->getHtmlBody());
        self::assertNotNull($email->getTextBody());
        self::assertCount(0, $email->getAttachments());
    }
}
