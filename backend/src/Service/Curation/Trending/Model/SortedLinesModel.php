<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class SortedLinesModel
{
    public function __construct(private string $text)
    {
    }

    public function valueOf(string $key): ?string
    {
        $low = 0;
        $high = strlen($this->text);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            $start = max($low, $this->lineStartBefore($middle));
            $end = (int) strpos($this->text, "\n", $middle);
            $separator = (int) strpos($this->text, "\t", $start);
            $order = strcmp(substr($this->text, $start, $separator - $start), $key);
            if (0 === $order) {
                return substr($this->text, $separator + 1, $end - $separator - 1);
            }
            [$low, $high] = $order < 0 ? [$end + 1, $high] : [$low, $start];
        }

        return null;
    }

    private function lineStartBefore(int $offset): int
    {
        if (0 === $offset) {
            return 0;
        }
        $newline = strrpos($this->text, "\n", $offset - strlen($this->text) - 1);

        return false === $newline ? 0 : $newline + 1;
    }
}
