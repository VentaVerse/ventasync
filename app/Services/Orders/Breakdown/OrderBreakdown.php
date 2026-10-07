<?php

namespace App\Services\Orders\Breakdown;

final class OrderBreakdown
{
    public const UNITEMISED = 'Not itemised';

    private array $entries = [];

    private ?array $total = null;

    public function __construct(public readonly string $title)
    {
    }

    public function line(string $label, float $amount): self
    {
        $this->entries[] = ['label' => $label, 'amount' => round($amount, 2), 'children' => []];

        return $this;
    }

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

    public function unitemised(): float
    {
        if ($this->total === null) {
            return 0.0;
        }
        $gap = round($this->total['amount'] - array_sum(array_column($this->entries, 'amount')), 2);

        return abs($gap) < 0.01 ? 0.0 : $gap;
    }

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
