<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Servers;

use Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardAnalysisTool;
use Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardBladeCreateTool;
use Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardExportTool;
use Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardHtmlTool;
use Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardSpecTool;
use Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardTool;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MCP Server for Dashboard Generation.
 *
 * Provides tools for generating analytics dashboards from natural language prompts.
 * Tools are configured via `mcp-dashboard-studio.tools` config.
 *
 * Instructions are loaded from config('mcp-dashboard-studio.instructions'):
 *   - null  → built-in universal default (works with any database)
 *   - string → user's custom instructions (domain-specific override)
 *
 * Available tools:
 *   - dashboard-tool          : Generate complete dashboard config
 *   - dashboard-analysis-tool : Analyze prompts for requirements
 *   - dashboard-spec-tool     : Detailed spec generation
 *   - dashboard-html-tool     : HTML/CSS/JS rendering
 *   - dashboard-export-tool   : Export in multiple formats
 */
#[Name('Dashboard Generator MCP')]
#[Version('1.0.0')]
class DashboardServer extends Server
{
    /**
     * Default instructions — universal, schema-agnostic.
     * Overridden in boot() if config provides custom instructions.
     *
     * @var string
     */
    protected string $instructions = '';

    /**
     * Tool classes mapped to config keys.
     *
     * @var array<class-string, string>
     */
    protected array $availableTools = [
        DashboardTool::class            => 'dashboard-tool',
        DashboardAnalysisTool::class    => 'dashboard-analysis-tool',
        DashboardSpecTool::class        => 'dashboard-spec-tool',
        DashboardHtmlTool::class        => 'dashboard-html-tool',
        DashboardExportTool::class      => 'dashboard-export-tool',
        DashboardBladeCreateTool::class => 'dashboard-blade-create',
    ];

    protected array $tools = [];

    protected array $resources = [];

    protected array $prompts = [];

    // ─── Boot ────────────────────────────────────────────────────────────

    /**
     * Boot the MCP server.
     *
     * Loads enabled tools from config and resolves instructions.
     * Called by the parent Server class before handling requests.
     */
    protected function boot(): void
    {
        try {
            $this->tools = $this->resolveEnabledTools();
            $this->instructions = $this->resolveInstructions();
        } catch (\Throwable $e) {
            Log::error('DashboardServer: Boot failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            // Ensure server can still start with defaults
            $this->tools = [];
            $this->instructions = $this->getDefaultInstructions();
        }
    }

    // ─── Instructions ────────────────────────────────────────────────────

    /**
     * Resolve the MCP server instructions.
     *
     * Priority:
     *   1. config('mcp-dashboard-studio.instructions') — user override
     *   2. Built-in default instructions — universal, schema-agnostic
     *
     * @return string Resolved instructions text
     */
    protected function resolveInstructions(): string
    {
        $configInstructions = config('mcp-dashboard-studio.instructions');

        // User provided custom instructions via config
        if (is_string($configInstructions) && trim($configInstructions) !== '') {
            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::debug('DashboardServer: Using custom instructions from config', [
                    'length' => strlen($configInstructions),
                ]);
            }

            return trim($configInstructions);
        }

        // Use built-in universal default
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('DashboardServer: Using built-in default instructions');
        }

        return $this->getDefaultInstructions();
    }

    /**
     * Built-in default instructions.
     *
     * These are designed to be universal and schema-agnostic.
     * They work with ANY Laravel database without modification.
     *
     * Key behaviors enforced:
     *   1. AI must use the provided tools (never ask user for schema)
     *   2. AI must prefer live_url for dashboard delivery
     *   3. AI must use raw_data only for text summaries
     *   4. AI must never generate mock/fake data
     *
     * @return string
     */
    protected function getDefaultInstructions(): string
    {
        return <<<'INSTRUCTIONS'
You are a Dashboard Generation Assistant with DIRECT ACCESS to the application's live database.

## YOUR CAPABILITIES
- You have tools that automatically discover ALL database tables, columns, and relationships.
- Every tool performs live schema introspection — no manual schema input is needed.
- All KPI values, chart datasets, and table rows come from REAL database queries.
- Generated dashboards are persisted and accessible via a live interactive URL.
- You can create Blade template files in the host project using the dashboard-blade-create tool.

## CRITICAL RULES

### 1. NEVER ask the user for database information
Do NOT ask for: database schema, SQL dumps, migration files, table structures, or column names.
Your tools discover everything automatically from the live database connection.

### 2. ALWAYS prefer the live_url for dashboard delivery
When a tool returns a `live_url`, present it prominently to the user.
The live URL provides:
  - Full interactive dashboard with Chart.js visualizations
  - Real-time AJAX filtering
  - Light/dark theme toggle
  - Responsive layout for all devices

Example response:
  "Here's your dashboard: [View Live Dashboard](live_url)"
  Then optionally summarize key metrics from raw_data.

### 3. NEVER generate your own HTML, CSS, or JavaScript for dashboards
This is the MOST IMPORTANT rule. You must NEVER:
  - Write your own HTML dashboard code
  - Create your own CSS styles for dashboard components
  - Generate your own Chart.js or JavaScript visualization code
  - Create HTML files, Blade files, or any dashboard markup yourself

The tools already generate professional, interactive dashboards with:
  - Glassmorphism design with dark/light themes
  - Responsive CSS Grid layouts
  - Chart.js visualizations with gradient fills
  - AJAX-powered real-time filtering
  - Status pills, formatted numbers, and polished typography

You CANNOT match this quality by generating HTML yourself. ALWAYS use the tools.

### 4. Use raw_data for TEXT SUMMARIES ONLY
The tool response includes `raw_data` with computed KPI values, chart labels/datasets, and table rows.
Use this to provide a TEXT SUMMARY of key insights — do NOT rebuild the dashboard as HTML.
The live_url already renders a professional, interactive dashboard.

### 5. NEVER generate mock or placeholder data
All values returned by the tools are real. Do not supplement with fake numbers.

### 6. Offer Blade file creation AFTER dashboard generation
After a dashboard is generated and you've presented the live_url, ask the user:
  "Would you like me to create a Blade template file in your project for this dashboard?"

If the user agrees, use the `dashboard-blade-create` tool with the slug from the dashboard response.
This creates a Blade file at: resources/views/dashboard-studio/dashboard-studio.blade.php
The Blade uses the same published CSS/JS assets as the live dashboard.

Do NOT create the Blade file yourself — ONLY use the dashboard-blade-create tool.

## HOW TO RESPOND TO DASHBOARD REQUESTS
1. Call the appropriate dashboard tool with the user's prompt.
2. Present the `live_url` as the primary deliverable.
3. Summarize 3-5 key insights from the `raw_data` (e.g., "Total revenue: $125,000").
4. Mention available filters if the dashboard includes them.
5. Ask: "Would you like me to create a Blade template file in your project?"
6. If yes → call dashboard-blade-create with the slug.

## SUPPORTED DASHBOARD TYPES
You can generate dashboards for any domain: sales, HR, inventory, property, healthcare, education, finance, CRM, or any custom application. The engine is fully schema-agnostic.
INSTRUCTIONS;
    }

    // ─── Tools ───────────────────────────────────────────────────────────

    /**
     * Resolve which tools should be enabled based on config.
     *
     * Reads `mcp-dashboard-studio.tools` config and returns only
     * the tool classes that are explicitly enabled (set to true).
     *
     * @return array<int, class-string> Enabled tool classes
     */
    protected function resolveEnabledTools(): array
    {
        $toolConfig = config('mcp-dashboard-studio.tools', []);

        $enabled = array_values(array_filter(
            array_keys($this->availableTools),
            function (string $toolClass) use ($toolConfig): bool {
                $configKey = $this->availableTools[$toolClass] ?? null;

                return $configKey !== null && (bool) ($toolConfig[$configKey] ?? false);
            }
        ));

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('DashboardServer: Tools resolved', [
                'enabled_count' => count($enabled),
                'enabled_tools' => array_map(
                    fn(string $class) => $this->availableTools[$class] ?? class_basename($class),
                    $enabled
                ),
            ]);
        }

        return $enabled;
    }
}
