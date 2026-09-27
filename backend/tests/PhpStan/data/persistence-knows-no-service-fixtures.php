<?php

declare(strict_types=1);

// Fixtures for PersistenceKnowsNoServiceRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Entity\Fixtures {
    use App\Service\Crypto\SealedSecret;

    final class StoresAServiceValue
    {
        public function __construct(public SealedSecret $secret)
        {
        }
    }
}

namespace App\Enum\Fixtures {
    enum NamesAService: string
    {
        case Mailer = 'App\Service\Mail\AccountMailer';
    }
}

namespace App\Doctrine\Fixtures {
    use App\{Service\Search\WordBoundaries};

    final class SharesAServiceHelper
    {
        public function boundaries(): string
        {
            return WordBoundaries::class;
        }
    }
}

namespace App\Service\Fixtures {
    use App\Entity\SealedSecret;
    use App\Service\Mail\AccountMailer;

    final class ServicesMayKnowEntities
    {
        public function __construct(public SealedSecret $secret)
        {
        }

        public function mailer(): string
        {
            return AccountMailer::class;
        }
    }
}

namespace App\Entity\Fixtures\Clean {
    use App\Enum\ProxyType;

    final class KnowsItsOwnLayer
    {
        public function type(): string
        {
            return ProxyType::class;
        }

        public function lookalike(): string
        {
            return 'App\ServiceLocator\Thing';
        }
    }
}

namespace App\Entity\Fixtures\Gaps {
    use App\Service\{Crypto\SealedSecret, Search\WordBoundaries};
    use App\Service as Services;

    final class KnowsServicesThroughTheGaps
    {
        public function lowercaseString(): string
        {
            return 'app\service\Mail\AccountMailer';
        }

        public function fullyQualifiedName(): string
        {
            return \App\Service\Mail\AccountMailer::class;
        }

        public function interpolatedString(string $suffix): string
        {
            return "App\\Service\\{$suffix}";
        }
    }
}
