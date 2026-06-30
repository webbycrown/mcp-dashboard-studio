<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database\Renderer;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;

/**
 * DashboardHtmlRenderer
 *
 * Converts DashboardSpec components into HTML markup.
 * Uses LayoutEngine grid positions (x, y, w, h) for CSS Grid placement.
 *
 * RESPONSIBILITIES:
 *   - Render KPI cards with titles and hydrated values
 *   - Render chart containers with <canvas> elements
 *   - Render data tables with headers and rows
 *   - Render filter controls (select, date-range)
 *
 * NON-RESPONSIBILITIES:
 *   - Never queries the database
 *   - Never modifies the DashboardSpec
 *   - Never adds or removes components
 */
class DashboardHtmlRenderer
{
    /**
     * Render the complete dashboard HTML from a DashboardSpec.
     */
    public function render(DashboardSpec $spec): string
    {
        $title       = $this->esc($spec->title);
        $description = $this->esc($spec->description ?? '');
        $layoutMap   = $this->buildLayoutMap($spec->layout);

        $filterHtml = $this->renderFilterBar($spec->filters, $layoutMap);
        $kpiHtml    = $this->renderSection('kpi-grid', $spec->kpis, 'kpi', $layoutMap);
        $chartHtml  = $this->renderSection('chart-grid', $spec->charts, 'chart', $layoutMap);
        $tableHtml  = $this->renderSection('table-grid', $spec->tables, 'table', $layoutMap);

        return <<<HTML
<div class="dashboard-container">
  <header class="dashboard-header">
    <h1 class="dashboard-title">{$title}</h1>
    <p class="dashboard-description">{$description}</p>
  </header>
{$filterHtml}{$kpiHtml}{$chartHtml}{$tableHtml}</div>
HTML;
    }

    // =========================================================================
    // Sections
    // =========================================================================

    protected function renderSection(string $gridClass, array $items, string $type, array $layoutMap): string
    {
        if (empty($items)) {
            return '';
        }

        $html = '';
        foreach ($items as $item) {
            $html .= match ($type) {
                'kpi'   => $this->renderKpiCard($item, $layoutMap),
                'chart' => $this->renderChartCard($item, $layoutMap),
                'table' => $this->renderTableCard($item, $layoutMap),
                default => '',
            };
        }

        return "  <section class=\"dashboard-grid {$gridClass}\">\n{$html}  </section>\n";
    }

    // =========================================================================
    // KPI Cards
    // =========================================================================

    protected function renderKpiCard(array $kpi, array $layoutMap): string
    {
        $id     = $this->esc($kpi['id'] ?? '');
        $title  = $this->esc($kpi['title'] ?? 'KPI');
        $value  = $kpi['value'] ?? '--';
        $format = $this->esc($kpi['format'] ?? 'number');
        $style  = $this->gridStyle($kpi['id'] ?? '', $layoutMap);

        if (is_numeric($value)) {
            $value = number_format((float) $value);
        }

        $display = $this->esc((string) ($kpi['formatted_value'] ?? $value));

        return <<<HTML
    <div class="kpi-card" id="{$id}" data-type="kpi" data-format="{$format}"{$style}>
      <h3 class="kpi-title">{$title}</h3>
      <span class="kpi-value">{$display}</span>
    </div>

HTML;
    }

    // =========================================================================
    // Chart Cards
    // =========================================================================

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

    // =========================================================================
    // Data Tables
    // =========================================================================

    protected function renderTableCard(array $component, array $layoutMap): string
    {
        $id    = $this->esc($component['id'] ?? 'table_' . md5(json_encode($component)));
        $title = $this->esc($component['title'] ?? 'Table');
        $style = $this->gridStyle($component['id'] ?? '', $layoutMap);

        $headers = $component['headers'] ?? $component['columns'] ?? [];
        $rows    = $component['rows'] ?? [];

        $thead = $this->renderTableHead($headers);
        $tbody = $this->renderTableBody($rows, $headers);
        $searchId = $id . '_search';

        return <<<HTML
    <div class="table-card" id="{$id}" data-type="table"{$style}>
      <h3 class="table-title">{$title}</h3>
      <div class="table-search">
        <input type="text" id="{$searchId}" class="table-search-input" placeholder="Search this table…" autocomplete="off">
      </div>
      <div class="table-scroll">
        <table class="data-table" data-search-id="{$searchId}">
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
            $label = is_array($header)
                ? ($header['label'] ?? $header['key'] ?? '')
                : (string) $header;
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
                foreach ($headers as $header) {
                    $key   = is_array($header) ? ($header['key'] ?? $header['name'] ?? '') : (string) $header;
                    $value = $row[$key] ?? '';

                    // Guard against arrays/objects — render as text, not raw JSON
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value);
                    }

                    $cells .= '<td>' . $this->esc((string) $value) . '</td>';
                }
            } else {
                foreach ($row as $value) {
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value);
                    }
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
            $items .= $this->renderFilterControl($filter);
        }

        return "  <section class=\"filter-bar\">\n{$items}  </section>\n";
    }

    protected function renderFilterControl(array $filter): string
    {
        $id      = $this->esc($filter['id'] ?? '');
        $title   = $this->esc($filter['title'] ?? 'Filter');
        $field   = $this->esc($filter['field'] ?? $filter['column'] ?? 'filter');
        $control = $filter['control'] ?? $filter['type'] ?? 'select';
        $options = $filter['options'] ?? [];

        if ($control === 'date_range') {
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

    // =========================================================================
    // Layout Helpers
    // =========================================================================

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

    protected function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
