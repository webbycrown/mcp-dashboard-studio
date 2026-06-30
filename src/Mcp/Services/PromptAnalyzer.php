<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\Services\Contracts\NlpClientInterface;
use Illuminate\Support\Facades\Log;

/**
 * Analyzes user prompts to extract dashboard requirements.
 *
 * Delegates NLP analysis to a configured NLP client.
 * Returns structured analysis data: title, summary, intent, objectives, etc.
 */
class PromptAnalyzer
{
    protected NlpClientInterface $nlpClient;

    public function __construct(NlpClientInterface $nlpClient)
    {
        $this->nlpClient = $nlpClient;
    }

    /**
     * Analyze user prompt and return structured requirements.
     *
     * @param  string  $prompt  User's dashboard request
     * @return array  Structured analysis with title, intent, etc.
     */
    public function analyze(string $prompt): array
    {
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('PromptAnalyzer: Analyzing prompt', ['length' => strlen($prompt)]);
        }
        $analysis = $this->nlpClient->interpret($prompt);
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('PromptAnalyzer: Analysis complete', [
                'has_title' => isset($analysis['title']),
            ]);
        }
        return $analysis;
    }
}
