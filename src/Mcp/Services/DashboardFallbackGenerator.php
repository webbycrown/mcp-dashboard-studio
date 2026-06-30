<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardPlanDTO;
use Illuminate\Support\Facades\Log;

/**
 * Generates fallback dashboard plans when analysis or planning fails.
 */
class DashboardFallbackGenerator
{
    public function generateFallback(string $prompt): DashboardPlanDTO
    {
        Log::warning('DashboardFallbackGenerator: Generating fallback plan', ['prompt' => $prompt]);

        return new DashboardPlanDTO([
            'title'       => 'Dashboard Generation Fallback',
            'description' => 'Unable to confidently generate a dashboard from the provided prompt.',
            'meta' => [
                'intent'   => 'dashboard-generation',
                'audience' => 'general',
                'fallback' => true,
                'reason'   => 'prompt_not_understood',
            ],
            'kpiPlan' => [
                [
                    'type'  => 'kpi',
                    'title' => 'Fallback Activated',
                    'value' => 1,
                    'unit'  => 'status',
                ],
            ],
            'chartPlan'  => [],
            'tablePlan'  => [
                [
                    'type'    => 'table',
                    'title'   => 'Fallback Information',
                    'columns' => ['message'],
                    'rows'    => [
                        ['message' => 'Prompt could not be mapped to available dashboard components.'],
                    ],
                ],
            ],
            'filterPlan' => [],
        ]);
    }
}
