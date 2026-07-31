<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ThinControllerRule>
 */
final class ThinControllerRuleTest extends RuleTestCase
{
    private const string FIXTURE_CONTROLLER = 'App\Controller\Fixtures\ViolatingController';

    protected function getRule(): Rule
    {
        // A dedicated allow-list keyed at the fixture's helper, so the test never
        // depends on the seeded production allow-list, which shrinks over #186.
        return new ThinControllerRule([
            self::FIXTURE_CONTROLLER . '::allowedHelper' => 'trivial fixture helper',
        ]);
    }

    public function testItFlagsPrivateAndStaticControllerMethodsButNotAllowListedOrNonControllerOnes(): void
    {
        $this->analyse(
            [__DIR__ . '/data/thin-controller-fixtures.php'],
            [
                // A private method that carries responsibility is reported.
                [$this->expectedMessage(self::FIXTURE_CONTROLLER, 'private', 'assembleResponse'), 17],
                // A private *static* method is reported too — a plain "private function"
                // grep would miss it, the rule does not.
                [$this->expectedMessage(self::FIXTURE_CONTROLLER, 'private', 'readParameter'), 22],
                // allowedHelper (line 27) is allow-listed, so it is not reported.
                // NotAController::helper (line 42) is outside App\Controller, so it is ignored.
            ],
        );
    }

    private function expectedMessage(string $className, string $visibility, string $method): string
    {
        return sprintf(
            'Controller %s has a %s method %s(). An action reads the request, delegates, and returns a '
            . 'response; move querying, response assembly, validation, entity mutation and security '
            . 'decisions into a service, a repository, or an src/Http/*Json.php mapper. See the '
            . '"Controllers hold no private methods that carry responsibility" rule in CLAUDE.md. If this '
            . 'is a trivial single-expression helper used by exactly one action in exactly one controller, '
            . 'add %s to ThinControllerRule::ALLOW_LIST with a comment that says why.',
            $className,
            $visibility,
            $method,
            $className . '::' . $method,
        );
    }
}
