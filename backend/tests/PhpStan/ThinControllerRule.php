<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces the "thin controller" rule from CLAUDE.md: a controller action reads
 * the request, delegates, and returns a response. A private or protected method
 * on a controller that carries responsibility — querying, response assembly,
 * validation, entity mutation, a security decision, or logic duplicated in a
 * second controller — belongs in a service, a repository, or an
 * `src/Http/*Json.php` mapper, not on the controller.
 *
 * The one permitted exception is a trivial single-expression helper used by
 * exactly one action in exactly one controller. Such a helper is named in
 * {@see self::ALLOW_LIST} with a comment that justifies it. The same helper in a
 * second controller is duplication, so the exception no longer applies.
 *
 * @implements Rule<InClassMethodNode>
 */
final readonly class ThinControllerRule implements Rule
{
    private const string CONTROLLER_NAMESPACE_PREFIX = 'App\\Controller\\';

    /**
     * Keyed `Fully\Qualified\Class::method`. Seeded from the live audit in #186
     * with every violation present the day the rule lands, so `composer stan` is
     * green from the start. Each later slice of #186 deletes its own entries as
     * it removes the violation, so the list only ever shrinks; when the refactor
     * finishes it holds only genuine trivial-helper exceptions. Every entry
     * carries a comment that justifies it under the rule above.
     *
     * @var array<string, string>
     */
    private const array ALLOW_LIST = [
        // #190 — entity lookup guards, two of them byte-identical across two
        // controllers, so they do not qualify for the trivial-helper exception.
        'App\Controller\Admin\AdminCatalogCategoryController::requireCategory' => 'duplicate lookup guard; #190',
        'App\Controller\Admin\AdminCatalogFeedController::requireFeed' => 'lookup guard; #190',
        'App\Controller\Admin\AdminCatalogFeedController::requireCategory' => 'duplicate lookup guard; #190',
        'App\Controller\Admin\AdminUserController::requireUser' => 'lookup guard; #190',
        'App\Controller\Admin\AdminUserController::requireNotSelf' => 'self-lockout guard; #190',

        // #191 — validation, entity mutation and a security decision, each of
        // which belongs in a service.
        'App\Controller\Admin\AdminCatalogFeedController::applyFeed' => 'entity mutation; #191',
        'App\Controller\Api\TagController::assertExactSet' => 'validation rule; #191',
        'App\Controller\MaintenanceController::isAuthorized' => 'security decision; #191',

        // #192 — the OAuth cookie and redirect lifecycle. Moved to
        // Service/OAuth/FlowCookie and Service/OAuth/OAuthRedirectFactory.
        'App\Controller\Api\OAuthController::flowCookie' => 'cookie lifecycle; #192',
        'App\Controller\Api\OAuthController::clearFlowCookie' => 'cookie lifecycle; #192',
        'App\Controller\Api\OAuthController::failure' => 'redirect assembly; #192',
        'App\Controller\Api\OAuthController::frontendBaseUrl' => 'redirect assembly; #192',
        'App\Controller\Api\OAuthController::param' => 'request parameter reader; #192',
    ];

    /**
     * @param array<string, string> $allowList keyed `Fully\Qualified\Class::method`;
     *        defaults to the seeded {@see self::ALLOW_LIST} and is overridable only
     *        so the rule's own test can exercise it against fixtures.
     */
    public function __construct(private array $allowList = self::ALLOW_LIST)
    {
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getMethodReflection();
        if ($method->isPublic()) {
            return [];
        }

        $className = $node->getClassReflection()->getName();
        if (!str_starts_with($className, self::CONTROLLER_NAMESPACE_PREFIX)) {
            return [];
        }

        $qualifiedName = $className . '::' . $method->getName();
        if (array_key_exists($qualifiedName, $this->allowList)) {
            return [];
        }

        $visibility = $method->isPrivate() ? 'private' : 'protected';

        return [
            RuleErrorBuilder::message(sprintf(
                'Controller %s has a %s method %s(). An action reads the request, delegates, and returns a '
                . 'response; move querying, response assembly, validation, entity mutation and security '
                . 'decisions into a service, a repository, or an src/Http/*Json.php mapper. See the '
                . '"Controllers hold no private methods that carry responsibility" rule in CLAUDE.md. If this '
                . 'is a trivial single-expression helper used by exactly one action in exactly one controller, '
                . 'add %s to ThinControllerRule::ALLOW_LIST with a comment that says why.',
                $className,
                $visibility,
                $method->getName(),
                $qualifiedName,
            ))
                ->identifier('simpleFeedReader.thinController')
                ->build(),
        ];
    }
}
