<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Service\Ai\AiConfigurationEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real entity manager: these edits are only interesting once they
 * reach the database, so each case reads the row back after a clear().
 */
final class AiConfigurationEditorTest extends DbTestCase
{
    public function testRenameRoundTripsTheName(): void
    {
        $settings = $this->savedConfiguration('editor-rename@example.test', 'Old name');

        $this->editor()->rename($settings, 'New name');

        self::assertSame('New name', $this->reload($settings)->getName());
    }

    public function testSetSuppressReasoningPersistsTheFlag(): void
    {
        $settings = $this->savedConfiguration('editor-reasoning@example.test', null);

        $this->editor()->setSuppressReasoning($settings, false);

        self::assertFalse($this->reload($settings)->suppressesReasoning());
    }

    public function testSetBatchConcurrencyPersistsTheValue(): void
    {
        $settings = $this->savedConfiguration('editor-concurrency@example.test', null);

        $this->editor()->setBatchConcurrency($settings, 3);

        self::assertSame(3, $this->reload($settings)->batchConcurrency());
    }

    private function editor(): AiConfigurationEditor
    {
        $editor = self::getContainer()->get(AiConfigurationEditor::class);
        self::assertInstanceOf(AiConfigurationEditor::class, $editor);

        return $editor;
    }

    private function savedConfiguration(string $email, ?string $name): AiProviderSettings
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->em, $hasher))->create($email);

        $settings = AiProviderSettingsFactory::build($user, $name);
        $this->em->persist($settings);
        $this->em->flush();

        return $settings;
    }

    /**
     * clear() first: without it the identity map serves the entity the test
     * already holds, so the assertion would pass even if nothing was written.
     */
    private function reload(AiProviderSettings $settings): AiProviderSettings
    {
        $id = $settings->getId();
        self::assertNotNull($id);
        $this->em->clear();

        $reloaded = $this->em->find(AiProviderSettings::class, $id);
        self::assertInstanceOf(AiProviderSettings::class, $reloaded);

        return $reloaded;
    }
}
