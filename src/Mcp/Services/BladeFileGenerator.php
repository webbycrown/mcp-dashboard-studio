<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BladeFileGenerator
 *
 * Builds a complete, standalone Blade template from a persisted dashboard's
 * layout_json and writes it to the host project's filesystem.
 *
 * Output path: resources/views/dashboard-studio/dashboard-studio.blade.php
 *
 * The generated Blade uses the SAME published CSS/JS assets:
 *   - /mcp-dashboard-studio/assets/css/style.css
 *   - /mcp-dashboard-studio/assets/js/app.js
 *   - Chart.js CDN (same as existing layouts/app.blade.php)
 *
 * The Blade is static HTML — data is baked in at generation time.
 * This makes it fully standalone and editable by the host developer.
 */
class BladeFileGenerator
{
    /**
     * Target directory inside the host project's resources/views.
     */
    protected string $viewDirectory = 'dashboard-studio';

    /**
     * Target filename for the generated Blade.
     */
    protected string $viewFilename = 'dashboard-studio.blade.php';

    /**
     * Generate and write the Blade file to the host project.
     *
     * @param  array   $layoutJson  The layout_json from McpDashboardDefinition
     * @param  string  $slug        Dashboard slug (for AJAX filter support)
     * @return array{success: bool, file_path: string, view_name: string, route_suggestion: string}
     *
     * @throws \RuntimeException  If directory creation or file write fails
     */
    public function generate(array $layoutJson, string $slug): array
    {
        $directory = resource_path("views/{$this->viewDirectory}");
        $filePath  = "{$directory}/{$this->viewFilename}";

        // ── Ensure directory exists ──
        if (! File::isDirectory($directory)) {
            try {
                File::makeDirectory($directory, 0755, true);
            } catch (\Throwable $e) {
                Log::error('BladeFileGenerator: Failed to create directory', [
                    'directory' => $directory,
                    'error'     => $e->getMessage(),
                ]);
                throw new \RuntimeException(
                    "Failed to create directory: {$directory} — " . $e->getMessage(),
                    500,
                    $e
                );
            }
        }

        // ── Extract chart data for JS initialization ──
        $charts = $this->extractChartsForJs($layoutJson['components'] ?? []);

        // ── Build the complete Blade content ──
        $bladeContent = $this->buildBladeContent($layoutJson, $slug, $charts);

        // ── Write the file ──
        try {
            File::put($filePath, $bladeContent);
        } catch (\Throwable $e) {
            Log::error('BladeFileGenerator: Failed to write Blade file', [
                'file_path' => $filePath,
                'error'     => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                "Failed to create Blade file: {$filePath} — " . $e->getMessage(),
                500,
                $e
            );
        }

        $viewName        = "{$this->viewDirectory}.dashboard-studio";
        $routeSuggestion = "Route::get('/my-dashboard', fn() => view('{$viewName}'));";

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('BladeFileGenerator: Blade file created', [
                'file_path' => $filePath,
                'view_name' => $viewName,
                'slug'      => $slug,
            ]);
        }

        return [
            'success'          => true,
            'file_path'        => "resources/views/{$this->viewDirectory}/{$this->viewFilename}",
            'absolute_path'    => $filePath,
            'view_name'        => $viewName,
            'route_suggestion' => $routeSuggestion,
        ];
    }

    // ─── Blade Builder ──────────────────────────────────────────────────

    /**
     * Build the complete Blade template string.
     *
     * Mirrors the structure of layouts/app.blade.php + index.blade.php
     * but as a single standalone file with embedded data.
     *
     * @param  array   $layoutJson  Layout JSON from DB
     * @param  string  $slug        Dashboard slug
     * @param  array   $charts      Extracted chart data for JS
     * @return string  Complete Blade content
     */
    protected function buildBladeContent(array $layoutJson, string $slug, array $charts): string
    {
        $title       = e($layoutJson['title'] ?? 'Dashboard');
        $description = e($layoutJson['description'] ?? '');
        $components  = $layoutJson['components'] ?? [];

        // ── Separate components by type ──
        $filterComponents = array_values(array_filter($components, fn($c) => ($c['type'] ?? '') === 'filter'));
        $kpiComponents    = array_values(array_filter($components, fn($c) => ($c['type'] ?? '') === 'kpi'));
        $gridComponents   = array_values(array_filter($components, fn($c) => !in_array($c['type'] ?? '', ['filter', 'kpi'])));

        // ── Build sections ──
        $filtersHtml = $this->renderFilters($filterComponents);
        $kpisHtml    = $this->renderKpis($kpiComponents);
        $gridHtml    = $this->renderGridComponents($gridComponents);
        $chartsJson  = json_encode($charts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // ── Assemble the full template ──
        return <<<BLADE
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f1117">
    <title>{$title}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    {{-- Same published CSS asset used by the package --}}
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/style.css">

    {{-- Prevent FOUC: apply saved theme before paint --}}
    <script>
        (function() {
            var saved = localStorage.getItem('dashboard-studio-theme');
            if (saved) document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
</head>
<body>
    <div class="dashboard-wrapper">
        <header class="top-nav">
            <div class="nav-brand">
                <span class="brand-accent">dashboard-studio</span>Dashboard
            </div>
            <div class="nav-actions">
                <span class="badge badge-active">Live Connection</span>
                <button class="theme-toggle" id="theme-toggle" title="Toggle Light/Dark Mode" aria-label="Toggle theme">
                    🌙
                </button>
            </div>
        </header>

        <main class="dashboard-main-content">
            <header class="dashboard-header">
                <h1 class="dashboard-title">{$title}</h1>
                {$this->renderDescription($description)}
            </header>

            {$filtersHtml}
            {$kpisHtml}

            <div class="dashboard-grid">
                {$gridHtml}
            </div>
        </main>
    </div>

    {{-- Chart.js CDN — same as package layout --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

    {{-- Same published JS asset used by the package --}}
    <script src="/mcp-dashboard-studio/assets/js/app.js"></script>

    <script>
        // Chart components data for Chart.js initialization
        window.mcpCharts = {$chartsJson};
        // Dashboard slug for AJAX filter requests
        window.mcpDashboardSlug = '{$slug}';
    </script>
</body>
</html>
BLADE;
    }

    // ─── Component Renderers ────────────────────────────────────────────

    /**
     * Render description paragraph (or empty string if no description).
     */
    protected function renderDescription(string $description): string
    {
        if ($description === '') {
            return '';
        }

        return "<p class=\"dashboard-description\">{$description}</p>";
    }

    /**
     * Render filter components as a control bar.
     * Mirrors: components/filter.blade.php
     */
    protected function renderFilters(array $filters): string
    {
        if (empty($filters)) {
            return '';
        }

        $html = '<div class="dashboard-filters" id="dashboard-filters">' . "\n";

        foreach ($filters as $component) {
            $filterData = $component['data'] ?? [];
            $datasource = $component['datasource'] ?? [];
            $title      = e($filterData['title'] ?? $filterData['label'] ?? 'Filter');
            $control    = $filterData['control'] ?? $filterData['filterType'] ?? $filterData['type'] ?? 'select';
            $options    = $filterData['options'] ?? [];
            $column     = e($datasource['column'] ?? $filterData['column'] ?? $filterData['field'] ?? '');
            $table      = e($datasource['table'] ?? $filterData['table'] ?? '');

            $html .= '<div class="filter-control">' . "\n";
            $html .= "    <label class=\"filter-label\">{$title}</label>\n";

            if ($control === 'date_range') {
                $html .= '    <div class="filter-date-inputs">' . "\n";
                $html .= "        <input type=\"date\" class=\"filter-input mcp-filter-input\" data-filter-column=\"{$column}\" data-filter-table=\"{$table}\" data-filter-type=\"date_from\">\n";
                $html .= '        <span class="filter-date-sep">to</span>' . "\n";
                $html .= "        <input type=\"date\" class=\"filter-input mcp-filter-input\" data-filter-column=\"{$column}\" data-filter-table=\"{$table}\" data-filter-type=\"date_to\">\n";
                $html .= '    </div>' . "\n";
            } else {
                $html .= "    <select class=\"filter-select mcp-filter-select\" data-filter-column=\"{$column}\" data-filter-table=\"{$table}\">\n";
                $html .= '        <option value="">All</option>' . "\n";
                foreach ($options as $opt) {
                    $val   = e(is_array($opt) ? ($opt['value'] ?? '') : $opt);
                    $label = e(is_array($opt) ? ($opt['label'] ?? $opt['value'] ?? '') : ucwords(str_replace('_', ' ', $opt)));
                    $html .= "        <option value=\"{$val}\">{$label}</option>\n";
                }
                $html .= '    </select>' . "\n";
            }

            $html .= '</div>' . "\n";
        }

        $html .= '</div>' . "\n";

        return $html;
    }

    /**
     * Render KPI components in an auto-fit grid.
     * Mirrors: components/kpi.blade.php
     */
    protected function renderKpis(array $kpis): string
    {
        if (empty($kpis)) {
            return '';
        }

        $html = '<div class="kpi-grid">' . "\n";

        foreach ($kpis as $component) {
            $data   = $component['data'] ?? [];
            $title  = e($data['title'] ?? 'KPI');
            $value  = $data['value'] ?? '0';
            $format = $data['format'] ?? 'number';
            $unit   = $data['unit'] ?? null;

            // Format value for display
            if ($format === 'currency' && is_numeric($value)) {
                $value = '$' . number_format((float) $value, 2);
            } elseif (is_numeric($value)) {
                $num = (float) $value;
                $value = number_format($num, (floor($num) == $num) ? 0 : 2);
            }
            $value = e($value);

            $html .= '<div class="dashboard-card kpi-card">' . "\n";
            $html .= "    <h3 class=\"kpi-title\">{$title}</h3>\n";
            $html .= "    <p class=\"kpi-value\">{$value}</p>\n";

            if (! empty($unit) && $unit !== 'count') {
                $html .= "    <span class=\"kpi-sub\">" . e($unit) . "</span>\n";
            }

            $html .= '</div>' . "\n";
        }

        $html .= '</div>' . "\n";

        return $html;
    }

    /**
     * Render chart and table components in the 12-col grid.
     * Mirrors: components/chart.blade.php, components/table.blade.php
     */
    protected function renderGridComponents(array $components): string
    {
        $html = '';

        foreach ($components as $component) {
            $type = $component['type'] ?? '';

            if (str_contains($type, 'chart')) {
                $html .= $this->renderChart($component);
            } elseif ($type === 'table') {
                $html .= $this->renderTable($component);
            } else {
                // Fallback: generic card
                $html .= $this->renderGenericCard($component);
            }
        }

        return $html;
    }

    /**
     * Render a single chart component.
     * Mirrors: components/chart.blade.php
     */
    protected function renderChart(array $component): string
    {
        $id    = e($component['id'] ?? 'chart_' . Str::random(8));
        $title = e($component['data']['title'] ?? 'Chart');

        $html  = "<div class=\"dashboard-card chart-card\" id=\"{$id}_wrapper\">\n";
        $html .= "    <h3 class=\"chart-title\">{$title}</h3>\n";
        $html .= '    <div class="chart-body">' . "\n";
        $html .= "        <canvas id=\"{$id}_canvas\"></canvas>\n";
        $html .= '    </div>' . "\n";
        $html .= '</div>' . "\n";

        return $html;
    }

    /**
     * Render a single table component.
     * Mirrors: components/table.blade.php
     */
    protected function renderTable(array $component): string
    {
        $data     = $component['data'] ?? [];
        $title    = e($data['title'] ?? 'Data List');
        $columns  = $data['columns'] ?? [];
        $headers  = $data['headers'] ?? [];
        $rows     = $data['rows'] ?? [];
        $rowCount = count($rows);

        // Use headers if columns are empty
        if (empty($columns) && ! empty($headers)) {
            $columns = $headers;
        }

        $html  = '<div class="dashboard-card table-card-wrapper">' . "\n";
        $html .= '    <div class="table-title">' . "\n";
        $html .= "        <span>{$title}</span>\n";

        if ($rowCount > 0) {
            $recordWord = $rowCount === 1 ? 'record' : 'records';
            $html .= "        <span class=\"table-badge\">{$rowCount} {$recordWord}</span>\n";
        }

        $html .= '    </div>' . "\n";
        $html .= '    <div class="table-scroll">' . "\n";
        $html .= '        <table class="data-table">' . "\n";

        // ── Table header ──
        $html .= '            <thead><tr>' . "\n";
        foreach ($columns as $col) {
            $colLabel = is_array($col)
                ? e($col['title'] ?? $col['name'] ?? '')
                : e(ucwords(str_replace('_', ' ', $col)));
            $html .= "                <th>{$colLabel}</th>\n";
        }
        $html .= '            </tr></thead>' . "\n";

        // ── Table body ──
        $html .= '            <tbody>' . "\n";

        if (empty($rows)) {
            $colSpan = max(count($columns), 1);
            $html .= "            <tr><td colspan=\"{$colSpan}\" class=\"table-empty\">No records found.</td></tr>\n";
        } else {
            foreach ($rows as $row) {
                $html .= '            <tr>' . "\n";

                foreach ($columns as $col) {
                    $colKey  = is_array($col) ? ($col['name'] ?? '') : $col;
                    $cellVal = is_array($row) ? ($row[$colKey] ?? '') : (is_object($row) ? ($row->{$colKey} ?? '') : '');

                    // Format numeric values
                    if (is_numeric($cellVal) && strlen((string) $cellVal) > 0) {
                        $num = (float) $cellVal;
                        if ($num == floor($num) && abs($num) < 1000000) {
                            $cellVal = number_format($num, 0);
                        } elseif (abs($num) < 1000000) {
                            $cellVal = number_format($num, 2);
                        }
                    }

                    // Truncate long text
                    if (is_string($cellVal) && strlen($cellVal) > 60) {
                        $cellVal = substr($cellVal, 0, 57) . '...';
                    }

                    // Format status values
                    $isStatus = str_contains(strtolower($colKey), 'status')
                             || str_contains(strtolower($colKey), 'active')
                             || str_contains(strtolower($colKey), 'state');

                    if ($isStatus && is_string($cellVal)) {
                        $statusClass = $this->resolveStatusClass($cellVal);
                        if ($statusClass) {
                            $displayVal = e(str_replace('_', ' ', $cellVal));
                            $html .= "                <td><span class=\"status-pill {$statusClass}\">{$displayVal}</span></td>\n";
                        } else {
                            $html .= '                <td>' . e(str_replace('_', ' ', $cellVal)) . "</td>\n";
                        }
                    } else {
                        $html .= '                <td>' . e($cellVal) . "</td>\n";
                    }
                }

                $html .= '            </tr>' . "\n";
            }
        }

        $html .= '            </tbody>' . "\n";
        $html .= '        </table>' . "\n";
        $html .= '    </div>' . "\n";
        $html .= '</div>' . "\n";

        return $html;
    }

    /**
     * Render a generic card component (fallback).
     */
    protected function renderGenericCard(array $component): string
    {
        $data  = $component['data'] ?? [];
        $title = e($data['title'] ?? $data['label'] ?? 'Component');
        $value = e($data['value'] ?? $data['content'] ?? '');

        $html  = '<div class="dashboard-card">' . "\n";
        $html .= "    <h3>{$title}</h3>\n";

        if ($value !== '') {
            $html .= "    <p>{$value}</p>\n";
        }

        $html .= '</div>' . "\n";

        return $html;
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Extract chart components into the format Chart.js (app.js) expects.
     * Same logic as DashboardStudioController::extractChartsForJs().
     *
     * @param  array  $components  All components from layout_json
     * @return array  Chart data array for window.mcpCharts
     */
    protected function extractChartsForJs(array $components): array
    {
        $charts = [];

        foreach ($components as $component) {
            $type = $component['type'] ?? '';

            if (! str_contains($type, 'chart')) {
                continue;
            }

            try {
                $componentData = $component['data'] ?? [];

                $charts[] = [
                    'id'        => $component['id'] ?? '',
                    'chartType' => $componentData['chartType'] ?? str_replace('_chart', '', $type),
                    'title'     => $componentData['title'] ?? 'Chart',
                    'data'      => $componentData['data'] ?? ['labels' => [], 'datasets' => []],
                ];
            } catch (\Throwable $e) {
                Log::warning('BladeFileGenerator: Chart extraction failed', [
                    'component_id' => $component['id'] ?? 'unknown',
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return $charts;
    }

    /**
     * Resolve CSS class for status pill based on value.
     * Same logic as table.blade.php.
     */
    protected function resolveStatusClass(string $value): string
    {
        $normalized = strtolower(str_replace(['_', '-'], '', $value));

        return match ($normalized) {
            'instock', 'active', 'completed', 'approved', '1', 'yes' => 'sp-success',
            'outofstock', 'inactive', 'cancelled', 'rejected', '0', 'no' => 'sp-danger',
            'pending', 'processing', 'onhold' => 'sp-warning',
            default => '',
        };
    }
}
