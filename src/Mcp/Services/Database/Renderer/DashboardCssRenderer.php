<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database\Renderer;

/**
 * DashboardCssRenderer
 *
 * Returns a complete, reusable static CSS stylesheet for MCP dashboards.
 * No database logic. No dashboard business logic. Pure presentation.
 *
 * The stylesheet is identical for every dashboard — component layout
 * differences come from inline grid-column/grid-row styles set by
 * the LayoutEngine and rendered by DashboardHtmlRenderer.
 */
class DashboardCssRenderer
{
    /**
     * Return the complete dashboard stylesheet.
     */
    public function render(): string
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

                    .dashboard-container *,
                    .dashboard-container *::before,
                    .dashboard-container *::after {
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
                    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06),
                                0 1px 2px rgba(15, 23, 42, 0.04);
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
                    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06),
                                0 1px 2px rgba(15, 23, 42, 0.04);
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
                    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06),
                                0 1px 2px rgba(15, 23, 42, 0.04);
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

                    .filter-select,
                    .filter-input {
                    padding: 8px 12px;
                    border: 1px solid #cbd5e1;
                    border-radius: 8px;
                    font-size: 0.85rem;
                    color: #334155;
                    background: #ffffff;
                    outline: none;
                    transition: border-color 0.15s ease;
                    }

                    .filter-select:focus,
                    .filter-input:focus {
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
                    .kpi-card  { grid-column: span 4; }
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
}
