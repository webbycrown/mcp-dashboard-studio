<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardPlanDTO;
use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;
use Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators\ComponentGeneratorInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


/**
 * Builds dashboard specifications from analyzed prompt data.
 *
 * Coordinates component generators to create KPIs, charts, tables, and filters.
 * Creates a DashboardSpec object ready for layout and rendering.
 */
class DashboardSpecBuilder
{
    /**
     * @param  array<int, ComponentGeneratorInterface>  $componentGenerators
     */
    protected array $componentGenerators;

    public function __construct()
    {
        $this->componentGenerators = [
            new \Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators\KpiGenerator(),
            new \Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators\ChartGenerator(),
            new \Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators\TableGenerator(),
            new \Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators\FilterGenerator(),
        ];
    }

    /**
     * Build dashboard specification from analyzed prompt.
     *
     * @param  array  $analysis  Data from PromptAnalyzer
     * @param  string  $prompt  Original user prompt
     * @return DashboardSpec Complete dashboard specification
     */
    public function build(array $analysis, string $prompt): DashboardSpec
    {
        $title = $analysis['title'] ?? $this->generateTitle($prompt);
        $description = $analysis['summary'] ?? $this->generateDescription($prompt);

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('DashboardSpecBuilder: Building spec', [
                'title' => $title,
            ]);
        }

        $spec = new DashboardSpec([
            'title' => $title,
            'description' => $description,
            'meta' => [
                'intent' => $analysis['intent'] ?? 'dashboard-generation',
                'audience' => $analysis['audience'] ?? 'stakeholders',
                'objectives' => $analysis['objectives'] ?? [],
            ],
        ]);

        // Process component hints and delegate to appropriate generators
        $componentCount = 0;
        foreach ($analysis['componentHints'] ?? [] as $hint) {
            foreach ($this->componentGenerators as $generator) {
                if (! $generator->canHandle($hint)) {
                    continue;
                }

                $component = $generator->generate($hint, $analysis);
                $section = $this->sectionName($hint['type'] ?? 'widget');
                $spec->{$section}[] = $component;
                $componentCount++;
                break;
            }
        }

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('DashboardSpecBuilder: Spec built', ['component_count' => $componentCount]);
        }

        return $spec;
    }

    /**
     * Build a DashboardSpec from a prepared DashboardPlanDTO.
     */
    public function buildFromPlan(DashboardPlanDTO $plan, string $prompt): DashboardSpec
    {
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('DashboardSpecBuilder: Building spec from plan', [
                'title' => $plan->title,
                'kpi_count' => count($plan->kpiPlan),
                'chart_count' => count($plan->chartPlan),
            ]);
        }

        $spec = new DashboardSpec([
            'title' => $plan->title ?: $this->generateTitle($prompt),
            'description' => $plan->description ?: $this->generateDescription($prompt),
            'meta' => array_merge([
                'intent' => 'dashboard-generation',
                'audience' => 'stakeholders',
            ], $plan->meta),
        ]);

        $allHints = array_merge(
            $plan->kpiPlan,
            $plan->chartPlan,
            $plan->tablePlan,
            $plan->filterPlan
        );

        foreach ($allHints as $hint) {

            foreach ($this->componentGenerators as $generator) {

                if (! $generator->canHandle($hint)) {
                    continue;
                }

                $component = $generator->generate(
                    $hint,
                    $plan->meta
                );

                $section = $this->sectionName(
                    $hint['type'] ?? 'widget'
                );

                $spec->{$section}[] = $component;

                break;
            }
        }

        return $spec;
    }

    protected function sectionName(string $type): string
    {
        return match ($type) {
            'kpi' => 'kpis',
            'chart' => 'charts',
            'table' => 'tables',
            'filter' => 'filters',
            default => 'charts',
        };
    }

    protected function generateTitle(string $prompt): string
    {
        return Str::title(Str::limit($prompt, 60, '')) ?: 'Dashboard';
    }

    protected function generateDescription(string $prompt): string
    {
        $title = $this->generateTitle($prompt);

        if ($title === 'Dashboard' || $title === '') {
            return 'Interactive analytics dashboard with real-time data from the database.';
        }

        // Remove " Dashboard" suffix for the description
        $subject = preg_replace('/\s*Dashboard$/i', '', $title);

        if (empty($subject)) {
            return 'Interactive analytics dashboard with real-time data from the database.';
        }

        return "Interactive analytics dashboard for {$subject} with real-time data from the database.";
    }
}
