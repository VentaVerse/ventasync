<?php

namespace App\Integrations;

use Closure;

class OrderTab
{
    /**
     * @param Closure():int|null $unprocessedCounter Returns the badge count; null if no badge.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $icon,
        public string $accent,
        public string $routeName,
        public string $permission,
        public ?Closure $unprocessedCounter = null,
        public array $routeParams = [],
    ) {
    }

    public function unprocessedCount(): ?int
    {
        if ($this->unprocessedCounter === null) {
            return null;
        }

        try {
            return (int) ($this->unprocessedCounter)();
        } catch (\Throwable) {
            return null;
        }
    }
}
