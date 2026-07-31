<?php

declare(strict_types=1);

// Fixtures for ThinControllerRuleTest. These live in the App\Controller namespace
// on purpose, so the rule considers them, and are excluded from `composer stan`
// (see excludePaths in phpstan.dist.neon). Analysed only by the rule's RuleTestCase.

namespace App\Controller\Fixtures {
    final class ViolatingController
    {
        public function action(): int
        {
            return $this->assembleResponse();
        }

        private function assembleResponse(): int
        {
            return 1 + 1;
        }

        private static function readParameter(): string
        {
            return 'value';
        }

        private function allowedHelper(): int
        {
            return 1;
        }
    }
}

namespace App\Service\Fixtures {
    final class NotAController
    {
        public function run(): int
        {
            return $this->helper();
        }

        private function helper(): int
        {
            return 1;
        }
    }
}
