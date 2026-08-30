<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestGroup;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\DigestTextRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * DigestTextRenderer turns a DigestModel into the plain-text subject and body
 * an email carries (#636). The renderer is exercised against the real shipped
 * `emails` translation files rather than a stub translator, so a missing or
 * misspelled catalog key fails the test instead of silently echoing the key.
 */
final class DigestTextRendererTest extends TestCase
{
    private DigestTextRenderer $renderer;

    protected function setUp(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $dir = \dirname(__DIR__, 4) . '/translations';
        $translator->addResource('yaml', "{$dir}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$dir}/emails.de.yaml", 'de', 'emails');

        $this->renderer = new DigestTextRenderer($translator);
    }

    public function testTwoGroupModelRendersSubjectAndBodyInEnglish(): void
    {
        $model = $this->twoGroupModel();

        $rendered = $this->renderer->render($model, 'en');

        self::assertStringContainsString('7', $rendered->subject);

        // A short introductory line opens the body, before any results.
        self::assertStringStartsWith('These are new entries matching your saved searches', $rendered->body);

        self::assertStringContainsString('rust (5)', $rendered->body);
        self::assertStringContainsString('golang (2)', $rendered->body);

        self::assertStringContainsString(
            "Rust 1.80 released — Rust Blog\n  A short summary.\n  https://example.com/1",
            $rendered->body,
        );
        self::assertStringContainsString("Second post — Rust Blog\n  https://example.com/2", $rendered->body);

        self::assertStringContainsString('+ more', $rendered->body);
        self::assertStringContainsString('https://reader.example/digest?q=rust', $rendered->body);

        self::assertStringContainsString('Settings', $rendered->body);
    }

    public function testGermanLocaleTranslatesFixedStrings(): void
    {
        $model = $this->twoGroupModel();

        $rendered = $this->renderer->render($model, 'de');

        self::assertStringStartsWith('Das sind neue Einträge', $rendered->body);
        self::assertStringContainsString('Einstellungen', $rendered->body);
    }

    public function testBodyContainsNoLiteralBackslashN(): void
    {
        $rendered = $this->renderer->render($this->twoGroupModel(), 'en');

        self::assertStringNotContainsString('\\n', $rendered->body);
    }

    private function twoGroupModel(): DigestModel
    {
        $rustEntries = [
            new DigestEntry(
                'Rust 1.80 released',
                'Rust Blog',
                'A short summary.',
                'https://example.com/1',
                null,
                null,
                null,
            ),
            new DigestEntry('Second post', 'Rust Blog', '', 'https://example.com/2', null, null, null),
        ];
        $rust = new DigestGroup('rust', 5, $rustEntries, true, 'https://reader.example/digest?q=rust');

        $golangEntries = [
            new DigestEntry('Golang release', 'Go Blog', '', 'https://example.com/3', null, null, null),
        ];
        $golang = new DigestGroup('golang', 2, $golangEntries, false, '');

        return new DigestModel([$rust, $golang], 7);
    }
}
