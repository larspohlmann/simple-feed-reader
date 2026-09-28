<?php

declare(strict_types=1);

// Fixtures for ServiceRoleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Shop {
    use App\DependencyInjection\ProcessLifetimeState;
    use App\Service\Shop\Model\Price;
    use Psr\Clock\ClockInterface;
    use Symfony\Component\DependencyInjection\Attribute\Autowire;
    use Symfony\Contracts\Service\ResetInterface;

    interface PriceSource
    {
        public function price(): int;
    }

    final readonly class ListPriceSource implements PriceSource
    {
        public function price(): int
        {
            return 1;
        }
    }

    final readonly class ReceiptFactory
    {
        public function receipt(): string
        {
            return 'receipt';
        }
    }

    final readonly class CartModel
    {
        public function __construct(private int $items)
        {
        }

        public function items(): int
        {
            return $this->items;
        }
    }

    enum Aisle
    {
        case Fruit;
    }

    final class Rounding
    {
        private function __construct()
        {
        }

        public static function cents(float $amount): int
        {
            return (int) round($amount * 100);
        }
    }

    final readonly class Discount
    {
        public function __construct(private int $percent)
        {
        }

        public function percent(): int
        {
            return $this->percent;
        }
    }

    final class ScanProgress
    {
        private int $scanned = 0;

        public function scan(): void
        {
            ++$this->scanned;
        }
    }

    final readonly class PricedBasket
    {
        public function __construct(private ClockInterface $clock, private int $items)
        {
        }

        public function pricedAt(): string
        {
            return $this->clock->now()->format('c') . $this->items;
        }
    }

    final readonly class CheckoutContext
    {
        public function __construct(public int $till)
        {
        }
    }

    final readonly class Wrapper
    {
        public function wrap(string $item): string
        {
            return $item;
        }
    }

    final class TapeCounter
    {
        private int $tape = 0;

        public function roll(): int
        {
            return ++$this->tape;
        }
    }

    final readonly class Checkout
    {
        private Wrapper $wrapper;

        private TapeCounter $tape;

        public function __construct(private ClockInterface $clock)
        {
            $this->wrapper = new Wrapper();
            $this->tape = new TapeCounter();
        }

        public function discount(): Discount
        {
            return new Discount(10);
        }

        public function progress(): ScanProgress
        {
            return new ScanProgress();
        }

        public function basket(): PricedBasket
        {
            return new PricedBasket($this->clock, 3);
        }

        public function context(): CheckoutContext
        {
            return new CheckoutContext($this->tape->roll());
        }

        public function wrapped(string $item): string
        {
            return $this->wrapper->wrap($item);
        }
    }

    final class Cashier
    {
        public function greet(): string
        {
            return 'hello';
        }
    }

    readonly class Porter
    {
        public function carry(): string
        {
            return 'box';
        }
    }

    final readonly class Register
    {
        public function __construct(
            private Price $price,
            #[Autowire(service: 'shop.price')]
            private Price $configured,
            private ?Price $fallback = null,
        ) {
        }

        public function total(): int
        {
            return $this->price->cents + $this->configured->cents + ($this->fallback?->cents ?? 0);
        }
    }

    final class Memo
    {
        private ?string $cached = null;

        public function remember(string $value): string
        {
            return $this->cached ??= $value;
        }
    }

    final class ResettingMemo implements ResetInterface
    {
        private ?string $cached = null;

        public function remember(string $value): string
        {
            return $this->cached ??= $value;
        }

        public function reset(): void
        {
            $this->cached = null;
        }
    }

    #[ProcessLifetimeState('A fixture: the memo outlives every message on purpose')]
    final class LifetimeMemo
    {
        private ?string $cached = null;

        public function remember(string $value): string
        {
            return $this->cached ??= $value;
        }
    }
}

namespace App\Service\Shop\Rules {
    interface DiscountRule
    {
        public function applies(): bool;
    }

    final readonly class SummerDiscount implements DiscountRule
    {
        public function applies(): bool
        {
            return true;
        }
    }
}

namespace App\Service\Shop\Basket {
    interface BasketInterface
    {
        public function count(): int;
    }

    final readonly class SessionBasket implements BasketInterface
    {
        public function count(): int
        {
            return 0;
        }
    }

    final readonly class BasketTotals
    {
        public function total(): int
        {
            return 0;
        }
    }
}

namespace App\Service\Shop\Exception {
    interface ShopFailure extends \Throwable
    {
    }

    final class OutOfStockException extends \RuntimeException implements ShopFailure
    {
    }
}

namespace App\Service\Shop\Factory {
    final readonly class InvoiceBuilder
    {
        public function invoice(): string
        {
            return 'invoice';
        }
    }
}

namespace App\Service\Shop\Model {
    use App\Entity\User;
    use App\Service\Shop\Dto\OrderLine;
    use Psr\Clock\ClockInterface;

    final readonly class Price
    {
        public function __construct(public int $cents)
        {
        }
    }

    final class ShelfModel
    {
        public function __construct(public int $shelves)
        {
        }
    }

    final readonly class StockModel
    {
        public function __construct(public ClockInterface $clock)
        {
        }
    }

    final readonly class OrderModel
    {
        public function __construct(public OrderLine $line)
        {
        }
    }

    final readonly class OwnerModel
    {
        public function __construct(public User $owner)
        {
        }
    }

    enum Currency
    {
        case Euro;
    }
}

namespace App\Service\Shop\Dto {
    final readonly class OrderLine
    {
        public function __construct(public string $sku)
        {
        }
    }

    final readonly class RefundLineModel
    {
        public function __construct(public string $sku)
        {
        }
    }

    final class LooseLine
    {
        public function __construct(public readonly string $sku)
        {
        }
    }

    final class LineFields
    {
        private function __construct()
        {
        }

        /** @param array<string, string> $line */
        public static function sku(array $line): string
        {
            return $line['sku'] ?? '';
        }
    }
}

namespace App\Service\Shop\Support {
    final readonly class Clerk
    {
        public function help(): string
        {
            return 'help';
        }
    }
}

namespace App\Service\Shop\Message {
    final readonly class RestockShelves
    {
    }

    final readonly class CountTills
    {
    }
}

namespace App\Service\Shop\Handler {
    use App\Service\Shop\Message\CountTills;
    use App\Service\Shop\Message\RestockShelves;

    final readonly class RestockHandler
    {
        public function __invoke(RestockShelves $message): void
        {
        }
    }

    final readonly class CountTillsHandler
    {
        public function __invoke(CountTills $message): void
        {
        }
    }
}

namespace App\Service\Till {
    use App\Service\Shop\PriceSource;

    final readonly class TillPriceSource implements PriceSource
    {
        public function price(): int
        {
            return 2;
        }
    }
}

namespace App\Service\Crate\Factory {
    interface CrateFactoryInterface
    {
        public function crate(): string;
    }

    final readonly class WoodenCrateFactory implements CrateFactoryInterface
    {
        public function crate(): string
        {
            return 'wood';
        }
    }
}

namespace App\Service\Label\Factory {
    interface LabelFactoryInterface
    {
        public function label(): string;
    }

    final readonly class PaperLabelFactory implements LabelFactoryInterface
    {
        public function label(): string
        {
            return 'paper';
        }
    }

    final readonly class StickerFactory
    {
        public function sticker(): string
        {
            return 'sticker';
        }
    }
}

namespace App\Service\Badge\Model {
    interface ColourModelInterface
    {
        public function hex(): string;
    }

    final readonly class HexColourModel implements ColourModelInterface
    {
        public function hex(): string
        {
            return '#000';
        }
    }

    final readonly class LabelTextModel
    {
        public function __construct(public string $text)
        {
        }
    }
}

namespace App\Http\Fixtures {
    final readonly class ResponseFactory
    {
        public function body(): string
        {
            return '';
        }
    }
}

namespace App\EventListener\Fixtures {
    final readonly class NotifyTheShop
    {
        public function __invoke(): void
        {
        }
    }

    final readonly class ShopListener
    {
        public function __invoke(): void
        {
        }
    }
}

namespace App\Service\Stamp\Factory {
    interface StampSource
    {
        public function stamp(): string;
    }
}

namespace App\Service\Stamp\Model {
    interface InkInterface
    {
        public function ink(): string;
    }
}

namespace App\Service\Wrap {
    final readonly class Ribbon
    {
        public function tie(string $item): string
        {
            return $item;
        }
    }

    final readonly class Bow
    {
        public function __construct(public string $colour = 'red')
        {
        }
    }

    final readonly class GiftWrapper
    {
        public function __construct(private Ribbon $ribbon = new Ribbon())
        {
        }

        public function wrap(string $item, Bow $bow = new Bow()): string
        {
            return $this->ribbon->tie($item) . $bow->colour;
        }
    }
}

namespace App\Service\Wrap\Dto {
    final readonly class WrapRequest
    {
        public function __construct(public string $paper)
        {
        }
    }
}

namespace App\Service\Wrap {
    use App\Service\Wrap\Dto\WrapRequest;

    final readonly class PaperPicker
    {
        public function __construct(private WrapRequest $request)
        {
        }

        public function paper(): string
        {
            return $this->request->paper;
        }
    }
}
