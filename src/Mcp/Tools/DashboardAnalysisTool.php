<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Tools;

use Webbycrown\McpDashboardStudio\Mcp\Services\PromptAnalyzer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP Tool: Analyze dashboard prompt for requirements.
 *
 * Performs NLP analysis on user prompt to extract:
 * - Dashboard title and description
 * - Intent and objectives
 * - Component hints (KPIs, charts, tables, filters)
 */
class DashboardAnalysisTool extends Tool
{
    public function name(): string
    {
        return 'dashboard-analysis-tool';
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        $prompt = trim((string) $request->get('prompt', ''));
        Log::debug('DashboardAnalysisTool: Received request', [
            'session' => $request->sessionId(),
            'prompt_length' => strlen($prompt),
        ]);

        if ($prompt === '') {
            Log::warning('DashboardAnalysisTool: Empty prompt received', [
                'session' => $request->sessionId(),
                'uri' => $request->uri(),
            ]);

            return Response::error('A prompt is required to perform dashboard analysis.');
        }

        try {
            Log::info('DashboardAnalysisTool: Analyzing prompt');
            $analysis = app(PromptAnalyzer::class)->analyze($prompt);
            Log::info('DashboardAnalysisTool: Analysis complete');

            return Response::structured([
                'analysis' => $analysis,
            ]);
        } catch (\Throwable $exception) {
            Log::error('DashboardAnalysisTool: Analysis failed', [
                'prompt' => $prompt,
                'error' => $exception->getMessage(),
                'class' => get_class($exception),
                'stack' => $exception->getTraceAsString(),
            ]);

            return Response::error('Unable to analyze the dashboard prompt.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->required(),
        ];
    }
}
