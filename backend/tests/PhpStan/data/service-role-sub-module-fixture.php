<?php

declare(strict_types=1);

// A fixture for ServiceRoleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Recommendation\Probe\ProbeCatalog {
    /** @noinspection AutoloadingIssuesInspection */
    interface ProbeCatalogInterface
    {
        public function models(): string;
    }
}

namespace App\Service\Recommendation\Llm {
    use App\Service\Recommendation\Probe\ProbeCatalog\ProbeCatalogInterface;

    /** @noinspection AutoloadingIssuesInspection */
    final readonly class LlmProbeCatalog implements ProbeCatalogInterface
    {
        public function models(): string
        {
            return 'llm';
        }
    }
}
