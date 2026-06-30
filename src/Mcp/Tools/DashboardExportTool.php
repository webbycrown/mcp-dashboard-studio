<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Tools;

use Webbycrown\McpDashboardStudio\Mcp\Services\DashboardGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP Tool: Export dashboard in multiple formats.
 *
 * Generates dashboard configuration and returns in:
 * - Pretty-printed JSON
 * - Structured array format
 * Ready for storage or further processing.
 */
class DashboardExportTool extends Tool
{
    public function name(): string
    {
        return 'dashboard-export-tool';
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        $prompt = trim((string) $request->get('prompt', ''));
        Log::debug('DashboardExportTool: Received export request', [
            'session' => $request->sessionId(),
            'prompt_length' => strlen($prompt),
        ]);

        if ($prompt === '') {
            Log::warning('DashboardExportTool: Empty prompt received', [
                'session' => $request->sessionId(),
                'uri' => $request->uri(),
            ]);

            return Response::error('A prompt is required to export the dashboard.');
        }

        try {
            Log::info('DashboardExportTool: Generating dashboard for export');
            $dashboard = app(DashboardGenerator::class)->generateDashboardConfig($prompt);

            $payload = [
                'export' => [
                    'json' => json_encode($dashboard, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    'data' => $dashboard,
                ],
            ];
            Log::info('DashboardExportTool: Export completed successfully');

            return Response::structured($payload);
        } catch (\Throwable $exception) {
            Log::error('DashboardExportTool: Export failed', [
                'prompt' => $prompt,
                'error' => $exception->getMessage(),
                'class' => get_class($exception),
                'stack' => $exception->getTraceAsString(),
            ]);

            return Response::error('Unable to export the dashboard content.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->required(),
        ];
    }
}
