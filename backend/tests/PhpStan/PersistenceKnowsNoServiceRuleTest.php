<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<PersistenceKnowsNoServiceRule> */
final class PersistenceKnowsNoServiceRuleTest extends RuleTestCase
{
    private const string ENTITY = 'App\Entity\Fixtures';
    private const string DOCTRINE = 'App\Doctrine\Fixtures';
    private const string GAPS = 'App\Entity\Fixtures\Gaps';
    private const string SEALED_SECRET = 'App\Service\Crypto\SealedSecret';
    private const string WORD_BOUNDARIES = 'App\Service\Search\WordBoundaries';
    private const string ACCOUNT_MAILER = 'App\Service\Mail\AccountMailer';

    protected function getRule(): Rule
    {
        return new PersistenceKnowsNoServiceRule(new NodeFinder(), ['App\Repository\Fixtures\EntryBatchInserter']);
    }

    public function testItReportsServicesInEntitiesEnumsAndTheOrmExtensionsOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/persistence-knows-no-service-fixtures.php'],
            [
                [self::message(self::ENTITY, self::SEALED_SECRET), 9],
                [self::message(self::ENTITY, self::SEALED_SECRET), 13],
                [self::message('App\Enum\Fixtures', self::ACCOUNT_MAILER), 22],
                [self::message(self::DOCTRINE, self::WORD_BOUNDARIES), 27],
                [self::message(self::DOCTRINE, self::WORD_BOUNDARIES), 33],
                [self::message(self::GAPS, self::SEALED_SECRET), 73],
                [self::message(self::GAPS, self::WORD_BOUNDARIES), 73],
                [self::message(self::GAPS, 'App\Service'), 74],
                [self::message(self::GAPS, 'app\service\Mail\AccountMailer'), 80],
                [self::message(self::GAPS, self::ACCOUNT_MAILER), 85],
                [self::message(self::GAPS, 'App\Service\\'), 90],
            ],
        );
    }

    public function testARepositoryNamesOnlyServiceValuesExceptWhereAllowed(): void
    {
        $this->analyse(
            [
                __DIR__ . '/data/persistence-knows-no-service/SpeaksServiceValues.php',
                __DIR__ . '/data/persistence-knows-no-service/EntryBatchInserter.php',
            ],
            [
                [self::repositoryMessage('App\Service\Url\UrlNormalizer'), 13],
                [self::repositoryMessage('App\Service\Backup\Dto\EntryLine'), 14],
                [self::repositoryMessage('App\Service\Url\UrlNormalizer'), 18],
                [self::repositoryMessage('App\Service\Backup\Dto\EntryLine'), 24],
            ],
        );
    }

    private static function message(string $namespaceName, string $reference): string
    {
        return sprintf(
            'Persistence code must not know a service: %s references %s. '
            . 'Move the shared value to App\Entity, App\Enum or App\Doctrine (docs/architecture.md §8).',
            $namespaceName,
            $reference,
        );
    }

    private static function repositoryMessage(string $reference): string
    {
        return sprintf(
            'Repositories name only Service values: App\Repository\Fixtures references %s. Hand the repository '
            . 'a model, a helper\'s result or an interface it implements (docs/architecture.md §8).',
            $reference,
        );
    }
}
