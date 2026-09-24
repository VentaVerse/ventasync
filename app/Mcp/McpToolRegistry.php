<?php

namespace App\Mcp;

/**
 * The assistant tools an enabled extension brings. Core lists its own tools
 * on the server; a module hands its tools here from its provider, so the
 * server offers them while the module is enabled and never names them.
 */
final class McpToolRegistry
{
    /** @var list<class-string> */
    private array $tools = [];

    /** @param list<class-string> $tools */
    public function add(array $tools): void
    {
        foreach ($tools as $tool) {
            if (! in_array($tool, $this->tools, true)) {
                $this->tools[] = $tool;
            }
        }
    }

    /** @return list<class-string> */
    public function all(): array
    {
        return $this->tools;
    }
}
