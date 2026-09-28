<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ServiceRoleRule> */
final class ServiceRoleRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/data/service-role-fixtures.php';
    private const string SHOP = 'App\Service\Shop\\';
    private const string NEEDS_SUFFIX = 'is an interface, so its name ends in Interface';
    private const string OUTSIDE_ITS_FOLDER = 'sits outside the folder named after it';
    private const string NOT_READONLY = 'keeps no state, so it is readonly';
    private const string NOT_FINAL = 'is a service, so it is final';
    private const string TAKES_A_MODEL = 'takes App\Service\Shop\Model\Price, which the container cannot supply';
    private const string DATA_NOT_READONLY = 'is data, so it is final readonly';
    private const string WRONG_FACTORY_FOLDER = 'sits in the wrong Factory/ folder';
    private const string WRONG_MODEL_FOLDER = 'sits in the wrong Model/ folder';
    private const string LISTENER = 'App\EventListener\Fixtures\NotifyTheShop';

    /** @var list<string> */
    private array $checks = [];

    protected function setUp(): void
    {
        parent::setUp();
        // ServiceRoleMap resolves collected names through ReflectionProvider::hasClass(), which only
        // recognises a fixture-only class once it is genuinely declared; analysing the file only parses it.
        require_once self::FIXTURES;
        $this->checks = array_map(
            static fn (ServiceRoleCheck $check): string => $check->value,
            ServiceRoleCheck::cases(),
        );
    }

    protected function getRule(): Rule
    {
        return new ServiceRoleRule(self::getContainer()->getByType(ReflectionProvider::class), $this->checks);
    }

    protected function getCollectors(): array
    {
        return [new ServiceRoleClassCollector(new NodeFinder()), new ServiceRoleInstantiationCollector()];
    }

    public function testEveryClassOutsideItsRoleIsReportedWithItsHome(): void
    {
        $this->analyse([self::FIXTURES], [
            [
                self::shop(
                    'interfaceFolder',
                    'PriceSource',
                    self::OUTSIDE_ITS_FOLDER,
                    'PriceSource\PriceSourceInterface',
                ),
                15,
            ],
            [self::shop('interfaceName', 'PriceSource', self::NEEDS_SUFFIX, 'PriceSource\PriceSourceInterface'), 15],
            [
                self::shop(
                    'interfaceFolder',
                    'ListPriceSource',
                    'implements App\Service\Shop\PriceSource, so it sits in that interface\'s folder',
                    'PriceSource\ListPriceSource',
                ),
                20,
            ],
            [
                self::shop(
                    'factoryName',
                    'ReceiptFactory',
                    'ends in Factory, so it sits in Factory/',
                    'Factory\ReceiptFactory',
                ),
                28,
            ],
            [self::shop('modelName', 'CartModel', 'ends in Model, so it sits in Model/', 'Model\CartModel'), 36],
            [self::shop('modelHome', 'Aisle', 'is an enum', 'Model\Aisle'), 48],
            [self::shop('supportHome', 'Rounding', 'is static-only', 'Support\Rounding'), 53],
            [self::shop('modelHome', 'Discount', 'is data built per call', 'Model\DiscountModel'), 65],
            [self::shop('passHome', 'ScanProgress', 'is built per call', 'Pass\ScanProgress'), 77],
            [self::shop('passHome', 'PricedBasket', 'is built per call', 'Pass\PricedBasket'), 87],
            [self::shop('passHome', 'CheckoutContext', 'is built per call', 'Pass\CheckoutContext'), 99],
            [self::shop('passHome', 'TapeCounter', 'is built per call', 'Pass\TapeCounter'), 114],
            [self::shop('rootService', 'Cashier', self::NOT_READONLY), 162],
            [self::shop('rootService', 'Porter', self::NOT_FINAL), 170],
            [self::shop('rootService', 'Register', self::TAKES_A_MODEL), 178],
            [
                self::shop(
                    'statefulService',
                    'Memo',
                    'keeps state in $cached; implement ResetInterface or add #[ProcessLifetimeState]',
                ),
                194,
            ],
            [
                self::shop(
                    'interfaceFolder',
                    'Rules\DiscountRule',
                    self::OUTSIDE_ITS_FOLDER,
                    'DiscountRule\DiscountRuleInterface',
                ),
                232,
            ],
            [
                self::shop(
                    'interfaceName',
                    'Rules\DiscountRule',
                    self::NEEDS_SUFFIX,
                    'DiscountRule\DiscountRuleInterface',
                ),
                232,
            ],
            [
                self::shop(
                    'interfaceFolder',
                    'Rules\SummerDiscount',
                    'implements App\Service\Shop\Rules\DiscountRule, so it sits in that interface\'s folder',
                    'DiscountRule\SummerDiscount',
                ),
                237,
            ],
            [
                self::shop(
                    'interfaceFolder',
                    'Basket\BasketTotals',
                    'sits in the folder of App\Service\Shop\Basket\BasketInterface but does not implement it',
                    'BasketTotals',
                ),
                260,
            ],
            [
                self::shop(
                    'interfaceName',
                    'Exception\ShopFailure',
                    self::roleSuffix('Exception'),
                    'Exception\ShopFailureExceptionInterface',
                ),
                270,
            ],
            [self::shop('factoryName', 'Factory\InvoiceBuilder', 'sits in Factory/, so its name ends in Factory'), 280],
            [self::shop('modelName', 'Model\Price', 'sits in Model/, so its name ends in Model'), 294],
            [self::shop('modelShape', 'Model\ShelfModel', self::DATA_NOT_READONLY), 301],
            [
                self::shop('modelShape', 'Model\StockModel', 'is data but takes the service Psr\Clock\ClockInterface'),
                308,
            ],
            [
                self::shop(
                    'modelShape',
                    'Model\OrderModel',
                    'names the DTO App\Service\Shop\Dto\OrderLine; map it to a model at the boundary',
                ),
                315,
            ],
            [self::shop('dtoShape', 'Dto\RefundLineModel', 'is a DTO named like a model'), 343],
            [
                self::shop(
                    'modelName',
                    'Dto\RefundLineModel',
                    'ends in Model, so it sits in Model/',
                    'Model\RefundLineModel',
                ),
                343,
            ],
            [self::shop('dtoShape', 'Dto\LooseLine', self::DATA_NOT_READONLY), 350],
            [self::shop('supportHome', 'Dto\LineFields', 'is static-only', 'Support\LineFields'), 357],
            [self::shop('supportHome', 'Support\Clerk', 'sits in Support/ but is not static-only'), 372],
            [
                self::shop(
                    'handlerName',
                    'Handler\RestockHandler',
                    'handles App\Service\Shop\Message\RestockShelves, so its name is RestockShelvesHandler',
                    'Handler\RestockShelvesHandler',
                ),
                395,
            ],
            [
                self::message(
                    'factoryFolder',
                    'App\Service\Label\Factory\LabelFactoryInterface',
                    self::WRONG_FACTORY_FOLDER,
                    'App\Service\Label\Factory\Label\LabelFactoryInterface',
                ),
                438,
            ],
            [
                self::message(
                    'factoryFolder',
                    'App\Service\Label\Factory\PaperLabelFactory',
                    self::WRONG_FACTORY_FOLDER,
                    'App\Service\Label\Factory\Label\PaperLabelFactory',
                ),
                443,
            ],
            [
                self::message(
                    'modelFolder',
                    'App\Service\Badge\Model\ColourModelInterface',
                    self::WRONG_MODEL_FOLDER,
                    'App\Service\Badge\Model\Colour\ColourModelInterface',
                ),
                461,
            ],
            [
                self::message(
                    'modelFolder',
                    'App\Service\Badge\Model\HexColourModel',
                    self::WRONG_MODEL_FOLDER,
                    'App\Service\Badge\Model\Colour\HexColourModel',
                ),
                466,
            ],
            [
                self::message(
                    'factoryName',
                    'App\Http\Fixtures\ResponseFactory',
                    'ends in Factory, so it sits in Factory/',
                    'App\Http\Fixtures\Factory\ResponseFactory',
                ),
                483,
            ],
            [self::listenerMessage(), 493],
            [
                self::message(
                    'interfaceName',
                    'App\Service\Stamp\Factory\StampSource',
                    self::roleSuffix('Factory'),
                    'App\Service\Stamp\Factory\StampSourceFactoryInterface',
                ),
                509,
            ],
            [
                self::message(
                    'interfaceName',
                    'App\Service\Stamp\Model\InkInterface',
                    self::roleSuffix('Model'),
                    'App\Service\Stamp\Model\InkModelInterface',
                ),
                516,
            ],
            [
                self::message(
                    'modelHome',
                    'App\Service\Wrap\Bow',
                    'is data built per call',
                    'App\Service\Wrap\Model\BowModel',
                ),
                531,
            ],
            [self::takesADto(), 563],
            [
                self::message(
                    'passHome',
                    'App\Service\Knot\Counter',
                    'is built per call',
                    'App\Service\Knot\Pass\Counter',
                ),
                577,
            ],
            [
                self::message(
                    'modelHome',
                    'App\Service\Knot\Label',
                    'is data built per call',
                    'App\Service\Knot\Model\LabelModel',
                ),
                587,
            ],
        ]);
    }

    public function testARunReportsOnlyTheChecksItIsGiven(): void
    {
        $this->checks = ['listenerName', 'rootService'];

        $this->analyse([self::FIXTURES], [
            [self::shop('rootService', 'Cashier', self::NOT_READONLY), 162],
            [self::shop('rootService', 'Porter', self::NOT_FINAL), 170],
            [self::shop('rootService', 'Register', self::TAKES_A_MODEL), 178],
            [self::listenerMessage(), 493],
            [self::takesADto(), 563],
        ]);
    }

    public function testAClassReflectionCannotResolveIsReportedNotDropped(): void
    {
        $map = ServiceRoleMap::fromCollectedData(
            self::getContainer()->getByType(ReflectionProvider::class),
            [self::FIXTURES => [['App\Service\Ghost\Phantom', 7, []]]],
            [],
        );

        $unresolved = $map->unresolvedClasses();

        self::assertSame(
            [['App\Service\Ghost\Phantom', self::FIXTURES, 7]],
            array_map(
                static fn (UnresolvedServiceRoleClass $class): array => [$class->name, $class->file, $class->line],
                $unresolved,
            ),
        );
        self::assertSame(
            'Service role: App\Service\Ghost\Phantom cannot be reflected, so no role check sees it.',
            $unresolved[0]->toError()->getMessage(),
        );
        self::assertSame('simpleFeedReader.serviceRole.unresolved', $unresolved[0]->toError()->getIdentifier());
    }

    private static function roleSuffix(string $role): string
    {
        return sprintf('is an interface in %s/, so its name ends in %sInterface', $role, $role);
    }

    private static function takesADto(): string
    {
        return self::message(
            'rootService',
            'App\Service\Wrap\PaperPicker',
            'takes App\Service\Wrap\Dto\WrapRequest, which the container cannot supply',
        );
    }

    private static function listenerMessage(): string
    {
        return self::message(
            'listenerName',
            self::LISTENER,
            'is an event listener, so its name ends in Listener',
            self::LISTENER . 'Listener',
        );
    }

    private static function shop(string $check, string $class, string $problem, ?string $home = null): string
    {
        return self::message($check, self::SHOP . $class, $problem, null === $home ? null : self::SHOP . $home);
    }

    private static function message(string $check, string $class, string $problem, ?string $home = null): string
    {
        $message = sprintf('Service role "%s": %s %s.', $check, $class, $problem);

        return null === $home ? $message : $message . sprintf(' Its home is %s.', $home);
    }
}
