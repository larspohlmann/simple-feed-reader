<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming\Model;

final readonly class SortedLinesModel
{
    public function __construct(private string $text)
    {
    }

    public function findValue(string $key): ?string
    {
        $field = $key . "\t";
        $fieldLength = strlen($field);
        $low = 0;
        $high = strlen($this->text);
        while ($low < $high) {
            $start = max($low, $this->lineStartBefore(intdiv($low + $high, 2)));
            $order = substr_compare($this->text, $field, $start, $fieldLength);
            if (0 === $order) {
                return $this->valueFrom($start + $fieldLength);
            }
            if ($order < 0) {
                $low = $this->lineEndAfter($start) + 1;
            } else {
                $high = $start;
            }
        }

        return null;
    }

    private function valueFrom(int $offset): string
    {
        return substr($this->text, $offset, $this->lineEndAfter($offset) - $offset);
    }

    private function lineEndAfter(int $offset): int
    {
        return (int) strpos($this->text, "\n", $offset);
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
