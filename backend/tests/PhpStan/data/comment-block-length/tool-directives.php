<?php

declare(strict_types=1);

// Fixture for CommentBlockLengthRuleTest, excluded from `composer stan`; the line numbers matter.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Tests\PhpStan\Fixtures\CommentBlockLength;

final class ToolDirectives
{
    /**
     * One.
     * Two.
     * Three.
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     * @see ToolDirectives::withReasons()
     * {@inheritDoc}
     * @noinspection PhpUnused
     */
    public function bareDirectives(): void
    {
        // One.
        // Two.
        // Three.
        // @psalm-suppress MixedAssignment, MixedArgument
        // phpcs:ignore Generic.Files.LineLength
        // @lang JSON
        // @codeCoverageIgnore
        $this->withReasons();
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList") One: a reason is prose.
     * @see ToolDirectives::bareDirectives() Two: so is the text after a reference.
     * {@inheritDoc} Three: and after an inherited doc.
     * @noinspection PhpUnused Four: and after an inspection name.
     */
    public function withReasons(): void
    {
        // @psalm-suppress MixedAssignment (One: the reason in brackets is prose.)
        // phpcs:ignore Generic.Files.LineLength -- Two: so is phpcs's.
        // @lang JSON: Three.
        // @codeCoverageIgnore Four.
        $this->bareDirectives();
    }
}
