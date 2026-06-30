<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardRenderResult;
use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;

/**
 * HtmlRenderer
 *
 * Pure presentation layer — converts a hydrated DashboardSpec into
 * standalone HTML + CSS ready for browser rendering.
 *
 * RESPONSIBILITIES:
 *   - Render KPI cards, chart containers, data tables, filter controls
 *   - Use LayoutEngine grid positions (x, y, w, h) for CSS Grid placement
 *   - Embed Chart.js <canvas> elements with inline data for charts
 *   - Render actual table rows from hydrated data
 *   - Render filter <select> options from hydrated data
 *   - Return static, reusable CSS (no dynamic generation)
 *
 * NON-RESPONSIBILITIES:
 *   - Never modifies the DashboardSpec
 *   - Never queries the database
 *   - Never adds or removes components
 *   - Never changes component values
 *
 * CONTRACT:
 *   render(DashboardSpec) → DashboardRenderResult { html, css, dashboard }
 */
class HtmlRenderer
{
  /**
   * Render a DashboardSpec into HTML + CSS + raw dashboard data.
   */
  public function render(DashboardSpec $spec): array
  {
    return $this->renderToResult($spec)->toArray();
  }

  /**
   * Render and return a typed DashboardRenderResult DTO.
   */
  public function renderToResult(DashboardSpec $spec): DashboardRenderResult
  {
    $html = $this->renderFullHtml($spec);
    $css  = $this->renderCss();

    return new DashboardRenderResult(
      html: $html,
      css: $css,
      dashboard: $spec->toArray(),
    );
  }

  // =========================================================================
  // Full Page Assembly
  // =========================================================================

  protected function renderFullHtml(DashboardSpec $spec): string
  {
    $title       = $this->esc($spec->title);
    $description = $this->esc($spec->description ?? '');

    // Build a lookup of layout positions keyed by component ID
    $layoutMap = $this->buildLayoutMap($spec->layout);

    // Render all components in layout order: filters → KPIs → charts → tables
    $filterHtml = $this->renderFilterBar($spec->filters, $layoutMap);
    $kpiHtml    = $this->renderKpiRow($spec->kpis, $layoutMap);
    $chartHtml  = $this->renderChartRow($spec->charts, $layoutMap);
    $tableHtml  = $this->renderTableRow($spec->tables, $layoutMap);

    // Chart.js initialization script (only if charts exist)
    $chartScript = ! empty($spec->charts) ? $this->renderChartScript($spec->charts) : '';

    return <<<HTML
                <div class="dashboard-container">
                  <header class="dashboard-header">
                    <h1 class="dashboard-title">{$title}</h1>
                    <p class="dashboard-description">{$description}</p>
                  </header>
                {$filterHtml}
                  <section class="dashboard-grid kpi-grid">
                {$kpiHtml}
                  </section>
                  <section class="dashboard-grid chart-grid">
                {$chartHtml}
                  </section>
                  <section class="dashboard-grid table-grid">
                {$tableHtml}
                  </section>
                </div>
                {$chartScript}
              HTML;
  }

    // =========================================================================
    // Layout Map
    // =========================================================================

  /**
   * Build an ID → layout-position map from LayoutEngine output.
   * Each entry: ['x' => int, 'y' => int, 'w' => int, 'h' => int]
   */
  protected function buildLayoutMap(array $layout): array
  {
    $map = [];
    foreach ($layout as $item) {
      $id = $item['id'] ?? null;
      if ($id) {
        $map[$id] = $item;
      }
    }
    return $map;
  }

  /**
   * Generate inline grid-placement style from layout position.
   */
  protected function gridStyle(string $id, array $layoutMap): string
  {
    $pos = $layoutMap[$id] ?? null;
    if (! $pos) {
      return '';
    }

    $colStart = ($pos['x'] ?? 0) + 1;
    $colEnd   = $colStart + ($pos['w'] ?? 3);
    $rowStart = ($pos['y'] ?? 0) + 1;
    $rowEnd   = $rowStart + ($pos['h'] ?? 2);

    return " style=\"grid-column: {$colStart} / {$colEnd}; grid-row: {$rowStart} / {$rowEnd};\"";
  }

  // =========================================================================
  // KPI Cards
  // =========================================================================

  protected function renderKpiRow(array $kpis, array $layoutMap): string
  {
    if (empty($kpis)) {
      return '';
    }

    $html = '';
    foreach ($kpis as $kpi) {
      $html .= $this->renderKpiCard($kpi, $layoutMap);
    }
    return $html;
  }

  protected function renderKpiCard(array $kpi, array $layoutMap): string
  {
    $id     = $this->esc($kpi['id'] ?? '');
    $title  = $this->esc($kpi['title'] ?? 'KPI');
    $value  = $kpi['value'] ?? '--';
    $format = $this->esc($kpi['format'] ?? 'number');
    $style  = $this->gridStyle($kpi['id'] ?? '', $layoutMap);

    // Format display value
    if (is_numeric($value)) {
      $value = number_format((float) $value);
    }

    $formattedValue = $kpi['formatted_value'] ?? $value;
    $formattedValue = $this->esc((string) $formattedValue);

    return <<<HTML
    <div class="kpi-card" id="{$id}" data-type="kpi" data-format="{$format}"{$style}>
      <h3 class="kpi-title">{$title}</h3>
      <span class="kpi-value">{$formattedValue}</span>
    </div>

HTML;
  }

  // =========================================================================
  // Chart Cards
  // =========================================================================

  protected function renderChartRow(array $charts, array $layoutMap): string
  {
    if (empty($charts)) {
      return '';
    }

    $html = '';
    foreach ($charts as $chart) {
      $html .= $this->renderChartCard($chart, $layoutMap);
    }
    return $html;
  }

  protected function renderChartCard(array $chart, array $layoutMap): string
  {
    $id        = $this->esc($chart['id'] ?? 'chart_' . md5(json_encode($chart)));
    $title     = $this->esc($chart['title'] ?? 'Chart');
    $chartType = $this->esc($chart['chartType'] ?? 'bar');
    $canvasId  = $id . '_canvas';
    $style     = $this->gridStyle($chart['id'] ?? '', $layoutMap);

    return <<<HTML
    <div class="chart-card" id="{$id}" data-type="chart" data-chart-type="{$chartType}"{$style}>
      <h3 class="chart-title">{$title}</h3>
      <div class="chart-body">
        <canvas id="{$canvasId}"></canvas>
      </div>
    </div>

HTML;
  }

  /**
   * Generate a <script> block that initializes Chart.js for each chart.
   * Only emits when chart data (labels + datasets) is present.
   */
  protected function renderChartScript(array $charts): string
  {
    $inits = '';

    foreach ($charts as $chart) {
      $id       = $chart['id'] ?? null;
      $data     = $chart['data'] ?? null;
      $type     = $chart['chartType'] ?? 'bar';
      $canvasId = $id . '_canvas';

      if (! $id || ! $data || empty($data['labels'])) {
        continue;
      }

      $jsonData = json_encode([
        'type' => $type,
        'data' => [
          'labels'   => $data['labels'] ?? [],
          'datasets' => $data['datasets'] ?? [],
        ],
        'options' => [
          'responsive'          => true,
          'maintainAspectRatio' => false,
          'plugins'             => ['legend' => ['display' => true]],
        ],
      ], JSON_UNESCAPED_UNICODE);

      $inits .= "  new Chart(document.getElementById('{$canvasId}'), {$jsonData});\n";
    }

    if ($inits === '') {
      return '';
    }

    return <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
{$inits}});
</script>
HTML;
  }

  // =========================================================================
  // Data Tables
  // =========================================================================

  protected function renderTableRow(array $tables, array $layoutMap): string
  {
    if (empty($tables)) {
      return '';
    }

    $html = '';
    foreach ($tables as $table) {
      $html .= $this->renderTableCard($table, $layoutMap);
    }
    return $html;
  }

  protected function renderTableCard(array $component, array $layoutMap): string
  {
    $id    = $this->esc($component['id'] ?? '');
    $title = $this->esc($component['title'] ?? 'Table');
    $style = $this->gridStyle($component['id'] ?? '', $layoutMap);

    // Determine headers: prefer hydrated 'headers', fall back to 'columns'
    $headers = $component['headers'] ?? $component['columns'] ?? [];
    $rows    = $component['rows'] ?? [];

    $thead = $this->renderTableHead($headers);
    $tbody = $this->renderTableBody($rows, $headers);

    return <<<HTML
    <div class="table-card" id="{$id}" data-type="table"{$style}>
      <h3 class="table-title">{$title}</h3>
      <div class="table-scroll">
        <table class="data-table">
          {$thead}
          {$tbody}
        </table>
      </div>
    </div>

HTML;
  }

  protected function renderTableHead(array $headers): string
  {
    if (empty($headers)) {
      return '';
    }

    $cells = '';
    foreach ($headers as $header) {
      $label = is_array($header) ? ($header['label'] ?? $header['key'] ?? '') : (string) $header;
      $cells .= '<th>' . $this->esc($label) . '</th>';
    }

    return "<thead><tr>{$cells}</tr></thead>";
  }

  protected function renderTableBody(array $rows, array $headers): string
  {
    if (empty($rows)) {
      $colspan = max(1, count($headers));
      return "<tbody><tr><td colspan=\"{$colspan}\" class=\"table-empty\">No data available.</td></tr></tbody>";
    }

    $body = '';
    foreach ($rows as $row) {
      $cells = '';

      if (! empty($headers)) {
        // Render cells in header order
        foreach ($headers as $header) {
          $key   = is_array($header) ? ($header['key'] ?? $header['name'] ?? '') : (string) $header;
          $value = $row[$key] ?? '';
          $cells .= '<td>' . $this->esc((string) $value) . '</td>';
        }
      } else {
        // No headers — render all values
        foreach ($row as $value) {
          $cells .= '<td>' . $this->esc((string) $value) . '</td>';
        }
      }

      $body .= "<tr>{$cells}</tr>";
    }

    return "<tbody>{$body}</tbody>";
  }

  // =========================================================================
  // Filters
  // =========================================================================

  protected function renderFilterBar(array $filters, array $layoutMap): string
  {
    if (empty($filters)) {
      return '';
    }

    $items = '';
    foreach ($filters as $filter) {
      $items .= $this->renderFilterControl($filter, $layoutMap);
    }

    return <<<HTML
  <section class="filter-bar">
{$items}
  </section>

HTML;
  }

  protected function renderFilterControl(array $filter, array $layoutMap): string
  {
    $id      = $this->esc($filter['id'] ?? '');
    $title   = $this->esc($filter['title'] ?? 'Filter');
    $field   = $this->esc($filter['field'] ?? $filter['column'] ?? 'filter');
    $control = $filter['control'] ?? $filter['type'] ?? 'select';
    $options = $filter['options'] ?? [];

    if ($control === 'date_range') {
      return $this->renderDateRangeFilter($id, $title, $field);
    }

    return $this->renderSelectFilter($id, $title, $field, $options);
  }

  protected function renderSelectFilter(string $id, string $title, string $field, array $options): string
  {
    $optionHtml = '<option value="">All</option>';
    foreach ($options as $option) {
      $val = $this->esc((string) $option);
      $optionHtml .= "<option value=\"{$val}\">{$val}</option>";
    }

    return <<<HTML
    <div class="filter-control" id="{$id}" data-type="filter">
      <label class="filter-label" for="{$field}">{$title}</label>
      <select class="filter-select" id="{$field}" name="{$field}">
        {$optionHtml}
      </select>
    </div>

HTML;
  }

  protected function renderDateRangeFilter(string $id, string $title, string $field): string
  {
    return <<<HTML
    <div class="filter-control filter-date-range" id="{$id}" data-type="filter">
      <label class="filter-label">{$title}</label>
      <div class="filter-date-inputs">
        <input type="date" class="filter-input" name="{$field}_from" placeholder="From">
        <span class="filter-date-sep">–</span>
        <input type="date" class="filter-input" name="{$field}_to" placeholder="To">
      </div>
    </div>

HTML;
  }

  // =========================================================================
  // Static CSS
  // =========================================================================

  protected function renderCss(): string
  {
    return <<<'CSS'
/* =========================================================================
   MCP Dashboard Stylesheet
   Pure presentation — no dynamic values. Reusable across all dashboards.
   ========================================================================= */

/* --- Reset & Base --- */
.dashboard-container {
  font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
  color: #1e293b;
  background: #f1f5f9;
  min-height: 100vh;
  padding: 24px;
  box-sizing: border-box;
}

.dashboard-container *, .dashboard-container *::before, .dashboard-container *::after {
  box-sizing: border-box;
}

/* --- Header --- */
.dashboard-header {
  margin-bottom: 28px;
}

.dashboard-title {
  font-size: 1.75rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0 0 6px;
  letter-spacing: -0.025em;
}

.dashboard-description {
  font-size: 0.9rem;
  color: #64748b;
  margin: 0;
}

/* --- Grid Sections --- */
.dashboard-grid {
  display: grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 20px;
  margin-bottom: 24px;
}

/* --- KPI Cards --- */
.kpi-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 22px 24px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
  transition: box-shadow 0.2s ease, transform 0.2s ease;
  grid-column: span 3;
}

.kpi-card:hover {
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.1);
  transform: translateY(-2px);
}

.kpi-title {
  font-size: 0.8rem;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin: 0 0 10px;
}

.kpi-value {
  font-size: 2rem;
  font-weight: 800;
  color: #0f172a;
  line-height: 1.1;
  display: block;
}

/* --- Chart Cards --- */
.chart-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 22px 24px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
  grid-column: span 6;
}

.chart-title {
  font-size: 0.95rem;
  font-weight: 600;
  color: #334155;
  margin: 0 0 16px;
}

.chart-body {
  position: relative;
  width: 100%;
  min-height: 260px;
}

.chart-body canvas {
  width: 100% !important;
  height: 100% !important;
}

/* --- Table Cards --- */
.table-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 22px 24px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
  grid-column: span 12;
}

.table-title {
  font-size: 0.95rem;
  font-weight: 600;
  color: #334155;
  margin: 0 0 16px;
}

.table-scroll {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.data-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.875rem;
}

.data-table th {
  background: #f8fafc;
  color: #475569;
  font-weight: 600;
  text-align: left;
  padding: 10px 14px;
  border-bottom: 2px solid #e2e8f0;
  white-space: nowrap;
  text-transform: capitalize;
}

.data-table td {
  padding: 10px 14px;
  border-bottom: 1px solid #f1f5f9;
  color: #334155;
}

.data-table tbody tr:hover {
  background: #f8fafc;
}

.data-table .table-empty {
  text-align: center;
  color: #94a3b8;
  padding: 32px 14px;
  font-style: italic;
}

/* --- Filter Bar --- */
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: flex-end;
  margin-bottom: 24px;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}

.filter-control {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 160px;
}

.filter-label {
  font-size: 0.75rem;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.filter-select, .filter-input {
  padding: 8px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 0.85rem;
  color: #334155;
  background: #ffffff;
  outline: none;
  transition: border-color 0.15s ease;
}

.filter-select:focus, .filter-input:focus {
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

.filter-date-inputs {
  display: flex;
  align-items: center;
  gap: 6px;
}

.filter-date-sep {
  color: #94a3b8;
}

/* --- Responsive --- */
@media (max-width: 1024px) {
  .kpi-card { grid-column: span 4; }
  .chart-card { grid-column: span 12; }
}

@media (max-width: 640px) {
  .dashboard-container { padding: 12px; }
  .dashboard-grid { gap: 12px; }
  .kpi-card { grid-column: span 6; }
  .kpi-value { font-size: 1.5rem; }
  .filter-bar { flex-direction: column; }
}
CSS;
  }

    // =========================================================================
    // Helpers
    // =========================================================================

  /**
   * HTML-escape a string.
   */
  protected function esc(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
