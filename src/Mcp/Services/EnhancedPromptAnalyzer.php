<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\Services\Database\MetricDiscoveryService;
use Webbycrown\McpDashboardStudio\Mcp\Services\Database\MetricRecommendationEngine;
use Webbycrown\McpDashboardStudio\Mcp\Services\Database\SchemaAnalyzer;
use Webbycrown\McpDashboardStudio\Mcp\Services\Database\SchemaCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enhanced Prompt Analyzer.
 *
 * Schema-driven analysis pipeline — no business domain detection.
 *
 * When DB mode is enabled:
 *   - Discovers live database schema (cached via SchemaCache)
 *   - Analyzes table structure (entity/transaction/lookup)
 *   - Suggests metrics from actual schema columns and relationships
 *
 * When DB mode is disabled:
 *   - Falls back to keyword-based component hint generation
 */
class EnhancedPromptAnalyzer
{
    public function __construct(
        protected PromptAnalyzer $analyzer,
        protected SchemaCache $schemaCache,
        protected SchemaAnalyzer $schemaAnalyzer,
        protected MetricDiscoveryService $metricDiscovery,
        protected MetricRecommendationEngine $recommendationEngine,
    ) {}

    /**
     * Analyze a prompt and return the enriched result.
     *
     * Returns the SAME structure as DefaultNlpClient::interpret():
     *   [
     *       'analysis'       => ['title'=>..., 'summary'=>..., ...],
     *       'componentHints' => [{type:'kpi', ...}, ...],
     *   ]
     *
     * DashboardPlanner reads $result['analysis'] and $result['componentHints']
     * as separate top-level keys — this structure MUST be preserved.
     */
    public function analyze(string $prompt): array
    {
        // NLP returns: { analysis: {...}, componentHints: [...] }
        $result = $this->analyzer->analyze($prompt);

        // Work with the nested 'analysis' sub-array
        $analysis = $result['analysis'] ?? [];
        $analysis['title'] = $analysis['title'] ?? $this->generateTitle($prompt);
        $analysis['summary'] = $analysis['summary'] ?? $this->generateSummary($prompt);
        $analysis['intent'] = $analysis['intent'] ?? 'dashboard-generation';
        $analysis['audience'] = $analysis['audience'] ?? 'stakeholders';

        // When DB mode is enabled, use live schema to drive hints
        if ($this->isDatabaseMode()) {
            $result = $this->enrichFromDatabase($prompt, $result, $analysis);
        } else {
            $result['analysis'] = $analysis;
            $result['componentHints'] = $result['componentHints'] ?? $this->inferComponentHints($prompt);
        }

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('EnhancedPromptAnalyzer: Analysis completed', [
                'hint_count' => count($result['componentHints'] ?? []),
                'db_mode' => $this->isDatabaseMode(),
            ]);
        }

        return $result;
    }

    // =========================================================================
    // Database-Driven Analysis (schema-driven, no domain)
    // =========================================================================

    /**
     * Enrich using live database schema.
     *
     * Pipeline:
     *   Schema → SchemaAnalyzer → MetricDiscovery → Recommendation → Hints
     */
    protected function enrichFromDatabase(string $prompt, array $result, array $analysis): array
    {
        try {
            // 1. Get schema from cache
            $schema = $this->schemaCache->getSchema();

            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::info('SCHEMA CACHE RESULT', [
                    'count' => count($schema),
                    'keys'  => array_slice(array_keys($schema), 0, 10),
                ]);
            }

            if (empty($schema)) {
                Log::warning('EnhancedPromptAnalyzer: Schema is empty, falling back');
                $result['analysis'] = $analysis;
                $result['componentHints'] = $result['componentHints'] ?? $this->inferComponentHints($prompt);
                return $result;
            }

            // 2. Analyze schema structure (entity/transaction/lookup classification)
            $schemaProfile = $this->schemaAnalyzer->analyze($schema);
            $analysis['schema_tables'] = array_keys($schema);
            $analysis['schema_profile'] = [
                'entities'     => $schemaProfile['primary_entities'],
                'transactions' => $schemaProfile['transaction_tables'],
                'lookups'      => $schemaProfile['lookup_tables'],
            ];
            $analysis['data_source'] = 'database';

            // 3. Discover ALL metrics from schema
            $allMetrics = $this->metricDiscovery->discover($schema);

            $metrics = $this->recommendationEngine->recommend($allMetrics, [
                'prompt'         => $prompt,
                'schema'         => $schema,
                'schema_tables'  => array_keys($schema),
                'schema_profile' => $schemaProfile,
            ]);

            if (! empty($metrics)) {
                $result['componentHints'] = $this->convertMetricsToHints($metrics);
                $analysis['discovered_metrics'] = $metrics;
            } else {
                $result['componentHints'] = $result['componentHints'] ?? $this->inferComponentHints($prompt);
            }

            $result['analysis'] = $analysis;

            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::info('EnhancedPromptAnalyzer: Database enrichment complete', [
                    'tables' => count($schema),
                    'hints'  => count($result['componentHints']),
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('EnhancedPromptAnalyzer: Database enrichment failed, falling back', [
                'error' => $e->getMessage(),
            ]);

            $analysis['data_source'] = 'fallback';
            $result['analysis'] = $analysis;
            $result['componentHints'] = $result['componentHints'] ?? $this->inferComponentHints($prompt);
        }

        return $result;
    }

    /**
     * Convert MetricDiscoveryService output into component hints
     * that the DashboardPlanner can consume.
     */
    protected function convertMetricsToHints(array $metrics): array
    {
        $hints = [];

        foreach ($metrics['kpis'] ?? [] as $kpi) {
            $hints[] = [
                'type'      => 'kpi',
                'title'     => $kpi['title'],
                'table'     => $kpi['table'] ?? null,
                'aggregate' => $kpi['aggregate'] ?? 'COUNT',
                'column'    => $kpi['column'] ?? '*',
                'unit'      => $kpi['unit'] ?? 'count',
                'format'    => $kpi['format'] ?? 'number',
                'value'     => $kpi['value'] ?? null,
            ];
        }

        foreach ($metrics['charts'] ?? [] as $chart) {
            // Preserve the original chart type (pie, bar, line) from MetricDiscoveryService
            // BEFORE overwriting 'type' with 'chart' — the ChartGenerator reads 'chartType'
            $chart['chartType'] = $chart['chartType'] ?? $chart['type'] ?? 'bar';
            $hints[] = array_merge($chart, ['type' => 'chart']);
        }

        foreach ($metrics['tables'] ?? [] as $table) {
            $hints[] = array_merge($table, ['type' => 'table']);
        }

        foreach ($metrics['filters'] ?? [] as $filter) {
            $hints[] = array_merge($filter, [
                'type'       => 'filter',
                'filterType' => $filter['type'] ?? 'select',
            ]);
        }

        return $hints;
    }

    // =========================================================================
    // Config Check
    // =========================================================================

    protected function isDatabaseMode(): bool
    {
        $dataMode  = config('mcp-dashboard-studio.data_mode', 'schema');
        $dbEnabled = config('mcp-dashboard-studio.database.enabled', false);

        return $dbEnabled || in_array($dataMode, ['database', 'hybrid'], true);
    }

    // =========================================================================
    // Keyword-Based Fallback (generic, no domain assumptions)
    // =========================================================================

    protected function inferComponentHints(string $prompt): array
    {
        // Generic schema-agnostic fallback hints
        return [
            ['type' => 'kpi', 'title' => 'Total Records', 'value' => 0, 'unit' => 'count'],
            ['type' => 'chart', 'title' => 'Records Over Time', 'chartType' => 'line'],
            ['type' => 'filter', 'title' => 'Date Range', 'filterType' => 'date_range'],
        ];
    }

    protected function generateTitle(string $prompt): string
    {
        // Strip instruction suffixes
        $text = preg_replace('/\b(use\s+my|don\'?t|please\s+use|and\s+also)\b.*$/i', '', $prompt);

        // First sentence only
        $parts = preg_split('/[\.\?\!]/', $text, 2);
        $text = trim($parts[0] ?? $text);

        // Clean to letters/spaces/commas
        $text = preg_replace('/[^a-zA-Z\s,]/', ' ', $text);

        // Strip action verbs
        $text = preg_replace(
            '/^\s*(generate|create|build|show|make|design|give|display|produce|render|construct|analyze|analyse)\s*/i',
            '', $text
        );
        $text = preg_replace('/^\s*(me|us)\s+/i', '', $text);

        // Strip noise words — comprehensive list to prevent prompt leakage
        $noise = [
            'a','an','the','my','our','its','your','their',
            'comprehensive','analytical','detailed','complete','interactive','dynamic','full',
            'which','that','is','are','was','were','be','been',
            'contain','contains','containing','include','includes','including',
            'has','have','having','with','about','for','from','of','in','on',
            'and','or','those','these','this','data','information','details','list','records',
            'total','count','number','sum','average','avg',
            'dashboard','report','overview','analytics','panel',
            // Additional noise words that were leaking into titles
            'all','every','single','available','use','using','used',
            'database','tables','table','columns','column','schema','db',
            'correct','elegant','elegent','proper','properly','correctly',
            'want','need','should','would','could','can','get',
            'very','most','really','just','also','too','enough',
            'based','driven','powered','real','time','realtime','real-time',
            'section','sections','following','each','type','types',
            'please','like','need','want','should','must',
            'not','only','but','any','some','more','new','old',
            'hrms','hrm','hris','erp','crm','system',
            'completely','entirely','fully','totally','absolutely',
            'necessary','needed','required','important','relevant',
            // Action verbs — regex only strips these at prompt start, so also filter here
            'generate','create','build','show','make','design','give','display',
            'produce','render','construct','analyze','analyse',
            'showing','generating','creating','building','displaying','making',
            'producing','rendering','constructing','analyzing','analysing',
        ];

        $tokens = preg_split('/[\s,]+/', strtolower($text));
        $entities = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            $singular = Str::singular($token);
            if (strlen($token) >= 3 && !in_array($token, $noise, true) && !isset($entities[$singular])) {
                $entities[$singular] = Str::title($token);
            }
        }

        // Try matching entities against actual DB tables for better titles
        if ($this->isDatabaseMode()) {
            try {
                $schema = $this->schemaCache->getSchema();
                $schemaTables = array_keys($schema);
                $matched = $this->matchEntitiesToTables($entities, $schemaTables);
                if (! empty($matched)) {
                    $entities = $matched;
                }
            } catch (\Throwable $e) {
                // Silently fall through — use token entities
            }
        }

        if (empty($entities)) {
            return 'Analytics Dashboard';
        }

        $parts = array_values(array_slice($entities, 0, 4));
        return Str::limit(implode(', ', $parts), 50, '') . ' Dashboard';
    }

    /**
     * Match extracted entities against real DB table names for cleaner titles.
     * E.g. 'employee' matches 'employees' → use "Employees" in the title.
     */
    protected function matchEntitiesToTables(array $entities, array $schemaTables): array
    {
        $matched = [];

        foreach ($schemaTables as $table) {
            $tableNorm = str_replace('_', ' ', strtolower($table));
            $tableSingular = Str::singular($table);

            foreach ($entities as $key => $label) {
                $keyLower = strtolower($key);
                if ($keyLower === $tableSingular || $keyLower === $table || str_contains($tableNorm, $keyLower)) {
                    $matched[$table] = Str::title(str_replace('_', ' ', $table));
                }
            }
        }

        return $matched;
    }

    protected function generateSummary(string $prompt): string
    {
        $summary = trim(preg_replace('/\s+/', ' ', $prompt));

        if ($summary === '') {
            return 'Interactive analytics dashboard with real-time data.';
        }

        return Str::limit($summary, 140, '...');
    }
}
