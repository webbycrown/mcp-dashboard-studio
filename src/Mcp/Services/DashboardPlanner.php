<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardPlanDTO;
use Illuminate\Support\Facades\Log;

/**
 * Dashboard Planner - Separates planning from execution.
 *
 * Takes enhanced analysis output and creates a detailed plan
 * before construction begins. This ensures all component hints
 * are captured and organized properly.
 *
 * Architecture improvement:
 * - Planning happens BEFORE building
 * - Plan is explicit and can be validated
 * - Builder consumes complete plan
 */
class DashboardPlanner
{
    public function __construct(
        protected EnhancedPromptAnalyzer $analyzer,
    ) {}

    /**
     * Create a dashboard plan from user prompt.
     *
     * @param  string  $prompt  User's dashboard request
     * @return DashboardPlanDTO Complete dashboard plan
     */
    public function plan(string $prompt): DashboardPlanDTO
    {
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('DashboardPlanner: Starting planning phase');
        }

        // Analyze prompt with enhanced analyzer
        $analysisResult = $this->analyzer->analyze($prompt);
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('Analyzer Result', [
                'result' => $analysisResult,
            ]);
        }
        $analysis = $analysisResult['analysis'];
        $componentHints = $analysisResult['componentHints'];

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('DashboardPlanner: Analysis complete', [
                'hints_count' => count($componentHints),
            ]);
        }

        // Organize component hints by type
        $plan = new DashboardPlanDTO([
            'title' => $analysis['title'] ?? $this->generateTitle($prompt),

            'description' => $analysis['summary'] ?? $this->generateDescription($prompt),

            'meta' => [
                'intent' => $analysis['intent'] ?? 'dashboard-generation',
                'audience' => $analysis['audience'] ?? 'stakeholders',
                'objectives' => $analysis['objectives'] ?? [],
                'verb' => $analysis['verb'] ?? 'build',
            ],

            'kpiPlan' => $this->filterHintsByType($componentHints, 'kpi'),

            'chartPlan' => $this->filterHintsByType($componentHints, 'chart'),

            'tablePlan' => $this->filterHintsByType($componentHints, 'table'),

            'filterPlan' => $this->filterHintsByType($componentHints, 'filter'),
        ]);
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('DashboardPlanner: Plan created', [
                'kpis' => count($plan->kpiPlan),
                'charts' => count($plan->chartPlan),
                'tables' => count($plan->tablePlan),
                'filters' => count($plan->filterPlan),
            ]);
        }

        return $plan;
    }

    /**
     * Filter hints by type.
     */
    protected function filterHintsByType(array $hints, string $type): array
    {
        return array_filter($hints, fn ($hint) => ($hint['type'] ?? null) === $type);
    }

    protected function generateTitle(string $prompt): string
    {
        return str_pad(strtoupper(substr($prompt, 0, 1)), 1, '').substr($prompt, 1, 59);
    }

    protected function generateDescription(string $prompt): string
    {
        $summary = trim(preg_replace('/\s+/', ' ', $prompt));

        return strlen($summary) > 140 ? substr($summary, 0, 137).'...' : $summary;
    }
}
