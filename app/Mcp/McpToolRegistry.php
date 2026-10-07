<?php

namespace App\Mcp;

final class McpToolRegistry
{
    private array $tools = [];

    public function add(array $tools): void
    {
        foreach ($tools as $tool) {
            if (! in_array($tool, $this->tools, true)) {
                $this->tools[] = $tool;
            }
        }
    }

    public function all(): array
    {
        return $this->tools;
    }
}
