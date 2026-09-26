<?php

declare(strict_types=1);

// Fixtures shared by the three ControllerMutatesNoEntity* rule tests (see excludePaths in phpstan.dist.neon).
// The namespaces deliberately do not match the path, hence the PSR-4 suppressions.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Entity\Fixtures {
    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity]
    class Widget
    {
        private string $label = '';

        public static function named(string $label): self
        {
            $widget = new self();
            $widget->setLabel($label);

            return $widget;
        }

        public static function maybeNamed(string $label): ?self
        {
            return '' === $label ? null : self::named($label);
        }

        /** @return list<self> */
        public static function many(string $label): array
        {
            return [self::named($label)];
        }

        public static function slug(string $label): string
        {
            return strtolower($label);
        }

        public function getLabel(): string
        {
            return $this->label;
        }

        public function isVisible(): bool
        {
            return '' !== $this->label;
        }

        public function requireId(): int
        {
            return 1;
        }

        public function setLabel(string $label): void
        {
            $this->label = $label;
        }

        public function rename(string $label): void
        {
            $this->label = $label;
        }
    }

    #[ORM\Embeddable]
    class Dimensions
    {
        private int $width = 0;

        public function getWidth(): int
        {
            return $this->width;
        }

        public function setWidth(int $width): void
        {
            $this->width = $width;
        }
    }

    final readonly class Coordinates
    {
        public function __construct(public int $x = 0)
        {
        }

        public static function origin(): self
        {
            return new self();
        }

        public function withX(int $x): self
        {
            return new self($x);
        }
    }
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Http\Fixtures {
    use App\Entity\Fixtures\Widget;

    final readonly class WidgetJson
    {
        /**
         * @param list<Widget> $widgets
         * @return list<Widget>
         */
        public static function ordered(array $widgets): array
        {
            return $widgets;
        }
    }
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Controller\Fixtures\Mutation {
    use App\Entity\EntryAttachment;
    use App\Entity\Exception\UnpersistedEntityException;
    use App\Entity\Fixtures\Coordinates;
    use App\Entity\Fixtures\Dimensions;
    use App\Entity\Fixtures\Widget;
    use App\Entity\Tag;
    use App\Entity\User;
    use App\Http\Fixtures\WidgetJson;

    final class MutatingController
    {
        public function create(): Widget
        {
            return new Widget();
        }

        public function update(Widget $widget, ?Widget $maybe): string
        {
            $widget->setLabel('new');
            $widget->rename('newer');
            $maybe?->setLabel('nullsafe');

            return $widget->getLabel() . ($widget->isVisible() ? 'shown' : 'hidden');
        }

        public function inClosure(Widget $widget): void
        {
            (static fn (Widget $inner) => $inner->setLabel('closure'))($widget);
        }

        public function readsTheId(Widget $widget): int
        {
            return $widget->requireId();
        }

        public function nonEntity(\ArrayObject $bag): \ArrayObject
        {
            $bag->setFlags(0);

            return new \ArrayObject();
        }

        /** @return list<\Closure> */
        public function firstClassCallables(Widget $widget, Tag $tag): array
        {
            return [
                $widget->getLabel(...),
                $tag->setName(...),
                User::normalizeEmail(...),
                Widget::named(...),
                Widget::slug(...),
            ];
        }

        /** @return list<?Widget> */
        public function staticCalls(): array
        {
            $label = Widget::slug('Static');

            return [
                Widget::named($label),
                Widget::maybeNamed($label),
                ...Widget::many($label),
            ];
        }

        /**
         * @param list<Widget> $widgets
         * @return list<Widget>
         */
        public function staticHelperOfAnUnmappedClass(array $widgets): array
        {
            return WidgetJson::ordered($widgets);
        }

        public function embeddable(Dimensions $dimensions): int
        {
            $dimensions->setWidth(3);

            return $dimensions->getWidth();
        }

        /** @return list<EntryAttachment> */
        public function unmappedClassesUnderAppEntity(int $x): array
        {
            if (0 > $x) {
                throw new UnpersistedEntityException(Widget::class);
            }

            $origin = (new Coordinates($x))->withX(Coordinates::origin()->x);

            return [
                new EntryAttachment('https://example.test/'),
                EntryAttachment::fromStored(['url' => 'https://example.test/' . $origin->x]),
            ];
        }
    }
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures\Mutation {
    use App\Entity\Fixtures\Widget;

    final class WidgetService
    {
        public function create(): Widget
        {
            $widget = new Widget();
            $widget->setLabel('services may');

            return Widget::named($widget->getLabel());
        }
    }
}
