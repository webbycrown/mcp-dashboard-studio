<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;
use Illuminate\Support\Facades\Log;

/**
 * Dashboard Validator - Validates dashboard before rendering.
 *
 * Checks for:
 * - Duplicate KPIs, charts, tables, filters
 * - Invalid layouts
 * - Missing titles
 * - Unsupported components
 */
class DashboardValidator
{
    /**
     * Validate a dashboard spec.
     *
     * @param  DashboardSpec  $spec  Dashboard to validate
     * @return array{valid: bool, errors: array, warnings: array}
     */
    public function validate(DashboardSpec $spec): array
    {
        $errors = [];
        $warnings = [];

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('DashboardValidator: Starting validation');
        }

        // Validate basic structure
        $this->validateBasicStructure($spec, $errors, $warnings);

        // Validate components
        $this->validateKPIs($spec, $errors, $warnings);
        $this->validateCharts($spec, $errors, $warnings);
        $this->validateTables($spec, $errors, $warnings);
        $this->validateFilters($spec, $errors, $warnings);

        // Validate layout
        $this->validateLayout($spec, $errors, $warnings);

        $valid = empty($errors);

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('DashboardValidator: Validation complete', [
                'valid' => $valid,
                'errors' => count($errors),
                'warnings' => count($warnings),
            ]);
        }

        return [
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate basic dashboard structure.
     */
    protected function validateBasicStructure(DashboardSpec $spec, array &$errors, array &$warnings): void
    {
        if (empty($spec->title)) {
            $errors[] = 'Dashboard must have a title';
        }

        if (strlen($spec->title) > 100) {
            $warnings[] = 'Dashboard title exceeds 100 characters';
        }

        if (empty($spec->kpis) && empty($spec->charts) && empty($spec->tables)) {
            $errors[] = 'Dashboard must contain at least one component (KPI, chart, or table)';
        }
    }

    /**
     * Validate KPIs.
     */
    protected function validateKPIs(DashboardSpec $spec, array &$errors, array &$warnings): void
    {
        $names = [];

        foreach ($spec->kpis as $index => $kpi) {
            // Check for missing title
            if (empty($kpi['title'] ?? null)) {
                $errors[] = "KPI at index $index missing title";
            }

            // Check for duplicates
            $kpiName = $kpi['title'] ?? '';
            if (in_array($kpiName, $names)) {
                $warnings[] = "Duplicate KPI title: $kpiName";
            }
            $names[] = $kpiName;
        }

        if (count($spec->kpis) > 10) {
            $warnings[] = 'Dashboard contains '.count($spec->kpis).' KPIs (more than 10 is not recommended)';
        }
    }

    /**
     * Validate charts.
     */
    protected function validateCharts(DashboardSpec $spec, array &$errors, array &$warnings): void
    {
        $names = [];
        $supportedTypes = ['line', 'bar', 'pie', 'area', 'scatter', 'column'];

        foreach ($spec->charts as $index => $chart) {
            // Check for missing title
            if (empty($chart['title'] ?? null)) {
                $errors[] = "Chart at index $index missing title";
            }

            // Check chart type
            $supportedTypes = ['line', 'bar', 'pie', 'area', 'scatter', 'column'];

            $chartType = $chart['chartType'] ?? null;

            if ($chartType && ! in_array($chartType, $supportedTypes)) {
                $errors[] = "Chart at index $index has unsupported chart type: $chartType";
            }

            // Check for duplicates
            $chartName = $chart['title'] ?? '';
            if (in_array($chartName, $names)) {
                $warnings[] = "Duplicate chart title: $chartName";
            }
            $names[] = $chartName;
        }
    }

    /**
     * Validate tables.
     */
    protected function validateTables(DashboardSpec $spec, array &$errors, array &$warnings): void
    {
        foreach ($spec->tables as $index => $table) {
            // Check for missing title
            if (empty($table['title'] ?? null)) {
                $errors[] = "Table at index $index missing title";
            }

            // Check for columns definition
            if (empty($table['columns'] ?? null)) {
                $warnings[] = "Table at index $index has no columns defined";
            }
        }
    }

    /**
     * Validate filters.
     */
    protected function validateFilters(DashboardSpec $spec, array &$errors, array &$warnings): void
    {
        foreach ($spec->filters as $index => $filter) {
            // Check for missing title
            if (empty($filter['title'] ?? null)) {
                $errors[] = "Filter at index $index missing title";
            }

            // Check for filter type
            if (empty($filter['type'] ?? null)) {
                $warnings[] = "Filter at index $index has no type defined";
            }
        }
    }

    /**
     * Validate layout configuration.
     */
    protected function validateLayout(DashboardSpec $spec, array &$errors, array &$warnings): void
    {
        $layout = $spec->layout ?? [];

        if (! empty($layout['grid'])) {
            $grid = $layout['grid'];

            if (isset($grid['columns']) && ($grid['columns'] < 1 || $grid['columns'] > 12)) {
                $errors[] = 'Layout grid columns must be between 1 and 12';
            }

            if (isset($grid['rows']) && $grid['rows'] < 1) {
                $errors[] = 'Layout grid rows must be at least 1';
            }
        }
    }
}
