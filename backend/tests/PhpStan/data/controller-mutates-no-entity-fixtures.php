<?php

declare(strict_types=1);

// Fixtures for ControllerMutatesNoEntityRuleTest, analysed only by that RuleTestCase (see excludePaths in
// phpstan.dist.neon). The namespaces deliberately do not match the path, hence the PSR-4 suppressions.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Entity\Fixtures {
    class Widget
    {
        private string $label = '';

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
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Controller\Fixtures\Mutation {
    use App\Entity\Fixtures\Widget;

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

            return $widget;
        }
    }
}
