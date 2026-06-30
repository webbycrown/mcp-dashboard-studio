<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Contracts;

interface NlpClientInterface
{
    /**
     * Interpret a natural language prompt and return a structured analysis.
     * The returned array MUST be serializable and contain at least 'analysis' and 'templates'.
     *
     * @param string $prompt
     * @return array
     */
    public function interpret(string $prompt): array;
}
