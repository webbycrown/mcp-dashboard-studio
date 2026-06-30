<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;
use Webbycrown\McpDashboardStudio\Mcp\Services\DataSourceResolver;
use Illuminate\Support\Facades\Log;

/**
 * Main orchestrator for dashboard generation workflow.
 *
 * ARCHITECTURE:
 * - Prompt → EnhancedPromptAnalyzer → Analysis + ComponentHints
 * - Analysis → DashboardPlanner → Explicit Plan (planning phase)
 * - Plan → DashboardSpecBuilder → Spec (consumes complete plan)
 * - Spec → DataSourceResolver::hydrate() → Spec with live DB values (NEW)
 * - Hydrated Spec → DashboardValidator → Validated
 * - Validated Spec → LayoutEngine → Layout applied
 * - Layout → HtmlRenderer → HTML output
 *
 * Data Flow:
 * - When DB mode is enabled, the resolver populates KPI values, chart data,
 *   table rows, and filter options from the configured database connection.
 * - When DB mode is disabled, the resolver is skipped (spec passes through unchanged).
 */
class DashboardGenerator
{
    public function __construct(
        protected DashboardPlanner $planner,
        protected DashboardSpecBuilder $builder,
        protected DataSourceResolver $resolver,
        protected DashboardFallbackGenerator $fallbackGenerator,
        protected DashboardValidator $validator,
        protected LayoutEngine $layoutEngine,
        protected HtmlRenderer $renderer,
    ) {
    }

    /**
     * Generate dashboard configuration from user prompt.
     *
     * @param  string  $prompt  User's dashboard request
     * @return array  Dashboard configuration as array
     */
    public function generateDashboardConfig(string $prompt): array
    {
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('DashboardGenerator: Starting config generation');
        }
        $spec = $this->buildDashboardSpec($prompt);

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('DashboardGenerator: Converting spec to array');
        }
        return $spec->toArray();
    }

    /**
     * Generate and render dashboard as HTML.
     *
     * @param  string  $prompt  User's dashboard request
     * @return array  Rendered dashboard output
     */
    public function renderDashboard(string $prompt): array
    {
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('DashboardGenerator: Starting dashboard rendering');
        }
        $spec = $this->buildDashboardSpec($prompt);

        return $this->renderer->render($spec);
    }

    /**
     * Internal: Build dashboard spec through full pipeline.
     */
    protected function buildDashboardSpec(string $prompt): DashboardSpec
    {
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('DashboardGenerator: Starting planning phase');
        }

        try {
            // Phase 1: Planning - Creates explicit plan with all component hints
            $plan = $this->planner->plan($prompt);

            // Check if plan has components
            $hasComponents = ! empty($plan->kpiPlan)
                || ! empty($plan->chartPlan)
                || ! empty($plan->tablePlan)
                || ! empty($plan->filterPlan);

            if (! $hasComponents) {
                Log::warning('DashboardGenerator: Plan has no components, using fallback');
                $plan = $this->fallbackGenerator->generateFallback($prompt);
            }

            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::debug('DashboardGenerator: Building spec from plan');
            }
            // Phase 2: Building - Consumes complete plan
            $spec = $this->builder->buildFromPlan($plan, $prompt);

            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::debug('DashboardGenerator: Hydrating spec with live data');
            }
            // Phase 3: Data Hydration - Resolve live values from database
            $spec = $this->resolver->hydrate($spec);

            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::debug('DashboardGenerator: Validating spec');
            }
            // Phase 4: Validation - Checks for issues before rendering
            $validation = $this->validator->validate($spec);

            if (! $validation['valid']) {
                Log::warning('DashboardGenerator: Validation errors', [
                    'errors' => $validation['errors'],
                ]);
            }

            if (! empty($validation['warnings'])) {
                if (config('mcp-dashboard-studio.logging_enabled', false)) {
                    Log::info('DashboardGenerator: Validation warnings', [
                        'warnings' => $validation['warnings'],
                    ]);
                }
            }

            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::debug('DashboardGenerator: Applying layout');
            }
            // Phase 5: Layout - Apply layout rules
            $spec = $this->layoutEngine->applyLayout($spec);

            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::info('DashboardGenerator: Spec pipeline complete');
            }
            return $spec;

        } catch (\Throwable $e)  {
            Log::error('DashboardGenerator: Error in pipeline', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            // Fallback to minimal dashboard on error
            Log::warning('DashboardGenerator: Using fallback due to error');
            $plan = $this->fallbackGenerator->generateFallback($prompt);
            $spec = $this->builder->buildFromPlan($plan, $prompt);
            return $this->layoutEngine->applyLayout($spec);
        }
    }
}
