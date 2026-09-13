<?php

namespace App\Services\Orders\Breakdown;

/**
 * An order's money as its store shows it, line by line, in the store's own
 * words, ending with the store's own bottom line.
 *
 * Amounts are in the order's currency, signed as they affect the bottom line.
 * The lines are checked against that bottom line: whatever the store did not
 * itemise is listed as its own line, so the total is never wrong and a figure
 * the store starts sending later is visible the day it arrives.
 */
final class OrderBreakdown
{
    public const UNITEMISED = 'Not itemised';

    /** @var list<array{label: string, amount: float, children: list<array{label: string, amount: float}>}> */
    private array $entries = [];

    /** @var array{label: string, amount: float}|null */
    private ?array $total = null;

    public function __construct(public readonly string $title)
    {
    }

    public function line(string $label, float $amount): self
    {
        $this->entries[] = ['label' => $label, 'amount' => round($amount, 2), 'children' => []];

        return $this;
    }

    /**
     * A heading whose figure is the sum of the lines under it.
     *
     * @param  list<array{0: string, 1: float}>  $children
     */
    public function group(string $label, array $children): self
    {
        $rows = array_map(fn (array $child) => ['label' => (string) $child[0], 'amount' => round((float) $child[1], 2)], $children);
        $this->entries[] = ['label' => $label, 'amount' => round(array_sum(array_column($rows, 'amount')), 2), 'children' => $rows];

        return $this;
    }

    public function total(string $label, float $amount): self
    {
        $this->total = ['label' => $label, 'amount' => round($amount, 2)];

        return $this;
    }

    /** What the store's bottom line holds beyond the lines it itemised. */
    public function unitemised(): float
    {
        if ($this->total === null) {
            return 0.0;
        }
        $gap = round($this->total['amount'] - array_sum(array_column($this->entries, 'amount')), 2);

        return abs($gap) < 0.01 ? 0.0 : $gap;
    }

    /** @return list<array{label: string, amount: float, level: int, kind: string}> */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->entries as $entry) {
            $rows[] = ['label' => $entry['label'], 'amount' => $entry['amount'], 'level' => 0, 'kind' => $entry['children'] === [] ? 'line' : 'group'];
            foreach ($entry['children'] as $child) {
                $rows[] = ['label' => $child['label'], 'amount' => $child['amount'], 'level' => 1, 'kind' => 'line'];
            }
        }
        if ($this->unitemised() !== 0.0) {
            $rows[] = ['label' => self::UNITEMISED, 'amount' => $this->unitemised(), 'level' => 0, 'kind' => 'line'];
        }
        if ($this->total !== null) {
            $rows[] = ['label' => $this->total['label'], 'amount' => $this->total['amount'], 'level' => 0, 'kind' => 'total'];
        }

        return $rows;
    }

    /**
     * A breakdown from sale lines whose last line is the total.
     *
     * @param  list<array{code: string, title: string, value: float}>  $lines
     */
    public static function fromSaleLines(string $title, array $lines): ?self
    {
        $total = array_pop($lines);
        if ($total === null || ($total['code'] ?? '') !== 'total') {
            return null;
        }
        $breakdown = new self($title);
        foreach ($lines as $line) {
            $breakdown->line($line['title'], (float) $line['value']);
        }

        return $breakdown->total($total['title'], (float) $total['value']);
    }
}
