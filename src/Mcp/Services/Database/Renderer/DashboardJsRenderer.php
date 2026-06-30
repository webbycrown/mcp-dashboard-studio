<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database\Renderer;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;

/**
 * DashboardJsRenderer
 *
 * Generates plain vanilla JavaScript for dashboard interactivity.
 * No TypeScript. No framework dependencies.
 *
 * Handles:
 *   - Chart.js initialization from chart data
 *   - Filter change event listeners
 *   - Table sort interactions (optional)
 *
 * The JS is self-contained and can be pasted directly into a <script> tag.
 * Chart.js v4 CDN must be loaded before this script executes.
 */
class DashboardJsRenderer
{
    /**
     * Generate the complete JavaScript for a dashboard.
     */
    public function render(DashboardSpec $spec): string
    {
        $parts = [];

        // Chart initialization
        if (! empty($spec->charts)) {
            $parts[] = $this->renderChartInit($spec->charts);
        }

        // Table search listeners
        if (! empty($spec->tables)) {
            $parts[] = $this->renderTableSearchListeners();
        }

        // Filter event listeners
        if (! empty($spec->filters)) {
            $parts[] = $this->renderFilterListeners();
        }

        if (empty($parts)) {
            return "// No interactive components to initialize.\n";
        }

        $body = implode("\n\n", $parts);

        return <<<JS
document.addEventListener('DOMContentLoaded', function() {
{$body}
});
JS;
    }

    // =========================================================================
    // Table Search
    // =========================================================================

    protected function renderTableSearchListeners(): string
    {
        return <<<'JS'
  // --- Table Search ---
  document.querySelectorAll('.table-search-input').forEach(function(input) {
    input.addEventListener('input', function() {
      var searchId = this.id;
      var query = this.value.toLowerCase().trim();
      var table = document.querySelector('table[data-search-id="' + searchId + '"]');
      if (!table) return;

      var rows = table.querySelectorAll('tbody tr');
      var hasMatch = false;

      rows.forEach(function(row) {
        var text = row.textContent.toLowerCase();
        if (query === '' || text.includes(query)) {
          row.style.display = '';
          hasMatch = true;
        } else {
          row.style.display = 'none';
        }
      });

      // Toggle empty state
      var emptyRow = table.querySelector('.table-empty');
      if (emptyRow) {
        emptyRow.style.display = hasMatch ? 'none' : '';
      }
    });
  });
JS;
    }

    // =========================================================================
    // Chart.js Initialization
    // =========================================================================

    protected function renderChartInit(array $charts): string
    {
        // Strip backend-only properties — only send what Chart.js needs
        $clientCharts = array_map(function (array $chart): array {
            return [
                'id'        => $chart['id'] ?? '',
                'chartType' => $chart['chartType'] ?? 'bar',
                'title'     => $chart['title'] ?? '',
                'data'      => [
                    'labels'   => $chart['data']['labels'] ?? [],
                    'datasets' => $chart['data']['datasets'] ?? [],
                ],
            ];
        }, $charts);

        $chartDataJson = json_encode($clientCharts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<JS
  // --- Chart.js Initialization ---
  var mcpCharts = {$chartDataJson};

  var defaultColors = [
    'rgba(99, 102, 241, 0.8)',
    'rgba(16, 185, 129, 0.8)',
    'rgba(245, 158, 11, 0.8)',
    'rgba(239, 68, 68, 0.8)',
    'rgba(139, 92, 246, 0.8)',
    'rgba(236, 72, 153, 0.8)'
  ];

  var defaultBorderColors = [
    'rgba(99, 102, 241, 1)',
    'rgba(16, 185, 129, 1)',
    'rgba(245, 158, 11, 1)',
    'rgba(239, 68, 68, 1)',
    'rgba(139, 92, 246, 1)',
    'rgba(236, 72, 153, 1)'
  ];

  mcpCharts.forEach(function(chart) {
    var canvasId = chart.id + '_canvas';
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;

    var chartData = chart.data || {};
    var labels = chartData.labels || [];
    var datasets = chartData.datasets || [];
    if (labels.length === 0) return;

    datasets.forEach(function(ds, i) {
      if (!ds.backgroundColor) {
        var type = chart.chartType || 'bar';
        if (type === 'pie' || type === 'doughnut') {
          ds.backgroundColor = defaultColors;
          ds.borderColor = defaultBorderColors;
        } else {
          ds.backgroundColor = defaultColors[i % defaultColors.length];
          ds.borderColor = defaultBorderColors[i % defaultBorderColors.length];
        }
      }
      if (!ds.borderWidth) ds.borderWidth = 2;
    });

    new Chart(canvas, {
      type: chart.chartType || 'bar',
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: datasets.length > 1 } },
        scales: {
          y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
          x: { grid: { display: false } }
        }
      }
    });
  });
JS;
    }

    // =========================================================================
    // Filter Event Listeners
    // =========================================================================

    protected function renderFilterListeners(): string
    {
        return <<<'JS'
  // --- Filter Event Listeners ---
  var filters = document.querySelectorAll('.filter-select, .filter-input');
  filters.forEach(function(el) {
    el.addEventListener('change', function() {
      var filterName = el.name || el.id;
      var filterValue = el.value;
      console.log('MCP Filter changed:', filterName, '=', filterValue);

      // Dispatch custom event for external consumers
      document.dispatchEvent(new CustomEvent('mcp:filter-change', {
        detail: { name: filterName, value: filterValue }
      }));
    });
  });
JS;
    }
}
