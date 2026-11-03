<?php

namespace App\Models\Concerns;

use LogicException;

trait HasCompositeKey
{
    abstract protected function keyColumns(): array;

    protected function setKeysForSaveQuery($query)
    {
        foreach ($this->keyColumns() as $column) {
            $value = $this->original[$column] ?? $this->getAttribute($column);
            if ($value === null) {
                throw new LogicException(static::class . ' has no ' . $column . ' to save by.');
            }
            $query->where($column, '=', $value);
        }

        return $query;
    }
}
