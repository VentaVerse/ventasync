<?php

return [

    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_REDIRECT_DOMAINS',
        'https://claude.ai/,https://claude.com/,https://chatgpt.com/,https://chat.openai.com/,http://localhost,http://127.0.0.1'
    ))))),

    'custom_schemes' => [
    ],

    'authorization_server' => null,

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

];
