<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * MetricRecommendationEngine
 *
 * Filters MetricDiscoveryService output (ALL possible metrics) down to
 * ~10-15 relevant ones for a usable dashboard.
 */
class MetricRecommendationEngine
{
    protected int $maxKpis    = 4;
    protected int $maxCharts  = 3;
    protected int $maxTables  = 2; // Increased to 2 for better default full dashboards
    protected int $maxFilters = 2;

    /**
     * Filter and rank discovered metrics based on prompt + schema context.
     *
     * @param  array  $metrics  Output of MetricDiscoveryService::discover()
     * @param  array  $context  {prompt, schema, schema_tables, schema_profile}
     * @return array  Same structure, filtered to top relevant metrics
     */
    public function recommend(array $metrics, array $context): array
    {
        $prompt        = strtolower($context['prompt'] ?? '');
        $schemaTables  = $context['schema_tables'] ?? [];
        $schemaProfile = $context['schema_profile'] ?? [];
        $schema        = $context['schema'] ?? [];

        // Primary tables = entities + transactions (from SchemaAnalyzer)
        $primaryTables = array_merge(
            $schemaProfile['primary_entities'] ?? [],
            $schemaProfile['transaction_tables'] ?? [],
        );

        // Filter out pivot/junction and empty tables from primary tables defaults unless explicitly requested later
        $primaryTables = array_filter($primaryTables, function ($table) {
            return !$this->isPivotOrJunctionTable($table) && !$this->isEmptyTable($table);
        });

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('MetricRecommendationEngine: Starting recommendation', [
                'input_kpis'      => count($metrics['kpis'] ?? []),
                'input_charts'    => count($metrics['charts'] ?? []),
                'input_tables'    => count($metrics['tables'] ?? []),
                'input_filters'   => count($metrics['filters'] ?? []),
                'primary_tables'  => $primaryTables,
            ]);
        }

        // ── Step 1: Extract explicit requests from the prompt ─────────
        $explicitMetrics = $this->extractExplicitMetrics($prompt, $schemaTables);
        $explicitCharts  = $this->extractExplicitCharts($prompt, $schemaTables);
        $explicitTables  = $this->extractExplicitTables($prompt, $schemaTables);
        $explicitFilters = $this->extractExplicitFilters($prompt, $schemaTables);

        $promptTables    = $this->extractPromptTables($prompt, $schemaTables);
        $intent          = $this->detectIntent($prompt);

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::debug('MetricRecommendationEngine: Parsed intent', [
                'intent'           => $intent,
                'explicit_metrics' => $explicitMetrics,
                'explicit_charts'  => $explicitCharts,
                'explicit_tables'  => $explicitTables,
                'explicit_filters' => $explicitFilters,
                'prompt_tables'    => $promptTables,
            ]);
        }

        // ── Step 2: Determine relevant tables ─────────────────────────
        if (! empty($promptTables)) {
            $relevantTables = $promptTables;
        } elseif (! empty($primaryTables)) {
            $relevantTables = array_values(array_unique(array_merge($primaryTables, $promptTables)));
        } else {
            $relevantTables = ! empty($promptTables)
                ? $promptTables
                : array_slice($schemaTables, 0, 5);
        }

        // ── Step 3: Select components independently based on type ─────

        // Detect if the prompt requests a grouped aggregation (e.g. "per city", "by category", "on each type")
        $groupByColumn = $this->extractGroupByColumn($prompt, $schema, $relevantTables);
        $isGroupedQuery = $groupByColumn !== null;

        // Budget limits per intent type
        $kpiBudget    = match ($intent) {
            'specific_metric', 'grouped_metric' => 1,
            'focused_dashboard'                 => 3,
            default                             => $this->maxKpis,
        };
        $chartBudget  = match ($intent) {
            'specific_metric'  => 0,
            'grouped_metric'   => 2,
            'focused_dashboard' => 2,
            default            => $this->maxCharts,
        };
        $tableBudget  = match ($intent) {
            'specific_metric', 'grouped_metric' => 0,
            'focused_dashboard'                 => 1,
            default                             => $this->maxTables,
        };
        $filterBudget = match ($intent) {
            'specific_metric', 'grouped_metric' => 0,
            'focused_dashboard'                 => 1,
            default                             => $this->maxFilters,
        };

        // ── Scale budgets to match explicit requests ──
        // If user explicitly asks for N items, expand budget to fit ALL of them
        if (! empty($explicitMetrics)) {
            $kpiBudget = max($kpiBudget, count($explicitMetrics));
        }
        if (! empty($explicitCharts)) {
            $chartBudget = max($chartBudget, count($explicitCharts));
        }
        if (! empty($explicitTables)) {
            $tableBudget = max($tableBudget, count($explicitTables));
        }
        if (! empty($explicitFilters)) {
            $filterBudget = max($filterBudget, count($explicitFilters));
        }

        // ── KPIs ──
        if (! empty($explicitMetrics)) {
            $kpis = array_slice($this->matchExplicitKpis($metrics['kpis'] ?? [], $explicitMetrics, $relevantTables), 0, $kpiBudget);
        }
        if (empty($kpis ?? [])) {
            // Fallback: auto-rank KPIs from relevant tables
            $kpis = array_slice($this->filterAndRank($metrics['kpis'] ?? [], $relevantTables, $prompt, 'kpi'), 0, $kpiBudget);
        }

        // ── Charts ──
        if (! empty($explicitCharts)) {
            $charts = array_slice($this->matchExplicitByTitle($metrics['charts'] ?? [], $explicitCharts, $relevantTables), 0, $chartBudget);
        }
        if (empty($charts ?? []) && ($intent === 'grouped_metric' || $isGroupedQuery)) {
            // Grouped query: find distribution chart for the group_by column
            $charts = array_slice($this->findGroupedCharts($metrics['charts'] ?? [], $relevantTables, $groupByColumn ?? ['table' => $relevantTables[0] ?? '', 'column' => ''], $prompt), 0, $chartBudget);
        }
        if (empty($charts ?? []) && $chartBudget > 0) {
            // Fallback: auto-rank charts from relevant tables
            $charts = array_slice($this->filterAndRank($metrics['charts'] ?? [], $relevantTables, $prompt, 'chart'), 0, $chartBudget);
        }
        $charts = $charts ?? [];

        // ── Tables ──
        if (! empty($explicitTables)) {
            $tables = array_slice($this->matchExplicitByTitle($metrics['tables'] ?? [], $explicitTables, $relevantTables), 0, $tableBudget ?: 2);
        }
        if (empty($tables ?? []) && $tableBudget > 0) {
            // Fallback: auto-rank tables from relevant tables
            $tables = array_slice($this->filterAndRank($metrics['tables'] ?? [], $relevantTables, $prompt, 'table'), 0, $tableBudget);
        }
        $tables = $tables ?? [];

        // ── Filters ──
        if (! empty($explicitFilters)) {
            $filters = array_slice($this->matchExplicitByTitle($metrics['filters'] ?? [], $explicitFilters, $relevantTables), 0, $filterBudget ?: 2);
        }
        if (empty($filters ?? []) && $filterBudget > 0) {
            // Fallback: auto-rank filters from relevant tables
            $filters = array_slice($this->filterAndRank($metrics['filters'] ?? [], $relevantTables, $prompt, 'filter'), 0, $filterBudget);
        }
        $filters = $filters ?? [];

        // Apply custom columns override if requested in the prompt
        $hasExplicitTables = ! empty($explicitTables);
        foreach ($tables as &$t) {
            $tableName = $t['table'] ?? '';
            if ($tableName && isset($schema[$tableName]['columns'])) {
                $allCols = array_column($schema[$tableName]['columns'], 'name');

                $sourceText = null;
                if (isset($t['original_request'])) {
                    $sourceText = $t['original_request'];
                } elseif (! $hasExplicitTables) {
                    $sourceText = $prompt;
                }

                if ($sourceText) {
                    $customCols = $this->extractCustomColumns($tableName, $allCols, $sourceText);
                    if (! empty($customCols)) {
                        $t['columns'] = $customCols;
                    }
                }
            }
        }
        unset($t);

        $result = [
            'kpis'    => $kpis,
            'charts'  => $charts,
            'tables'  => $tables,
            'filters' => $filters,
        ];

        $total = count($kpis) + count($charts) + count($tables) + count($filters);

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('MetricRecommendationEngine: Recommendation complete', [
                'output_kpis'    => count($kpis),
                'output_charts'  => count($charts),
                'output_tables'  => count($tables),
                'output_filters' => count($filters),
                'total'          => $total,
            ]);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Filtering & Scoring
    // -------------------------------------------------------------------------

    protected function filterAndRank(array $items, array $relevantTables, string $prompt, string $type): array
    {
        $scored = [];

        foreach ($items as $item) {
            $table = $item['table'] ?? null;

            if ($table && ! in_array($table, $relevantTables, true)) {
                continue;
            }

            $score = $this->score($item, $prompt, $type);
            $scored[] = ['item' => $item, 'score' => $score];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $seen  = [];
        $final = [];

        foreach ($scored as $entry) {
            $title = strtolower($entry['item']['title'] ?? '');

            if (isset($seen[$title])) {
                continue;
            }

            $seen[$title] = true;
            $final[]      = $entry['item'];
        }

        return $final;
    }

    protected function score(array $item, string $prompt, string $type): int
    {
        $score = 0;
        $title = strtolower($item['title'] ?? '');
        $table = strtolower($item['table'] ?? '');

        // ── Direct prompt mention ──
        if ($table && str_contains($prompt, $table)) {
            $score += 100;
        }

        if ($title && str_contains($prompt, $title)) {
            $score += 80;
        }

        $titleWords = array_filter(explode(' ', $title), fn ($w) => strlen($w) > 3);
        foreach ($titleWords as $word) {
            if (str_contains($prompt, $word)) {
                $score += 30;
            }
        }

        // Aggregate priority
        $aggregate = strtoupper($item['aggregate'] ?? '');
        $score += match ($aggregate) {
            'COUNT' => 20,
            'SUM'   => 15,
            'AVG'   => 10,
            'MIN'   => 5,
            'MAX'   => 5,
            default => 0,
        };

        // Type-specific scoring
        if ($type === 'chart') {
            $chartType = $item['chartType'] ?? $item['type'] ?? '';
            $score += match ($chartType) {
                'line'    => 15,
                'bar'     => 10,
                'pie'     => 5,
                default   => 0,
            };
        }

        // Apply Pivot and Empty penalties dynamically
        if ($table) {
            if ($this->isPivotOrJunctionTable($table)) {
                $score -= 50;
            }
            if ($this->isEmptyTable($table)) {
                $score -= 60;
            }
        }

        return $score;
    }

    // -------------------------------------------------------------------------
    // Prompt → Table Matching (Levenshtein dynamically)
    // -------------------------------------------------------------------------

    protected function extractPromptTables(string $prompt, array $schemaTables): array
    {
        $matches = [];
        $promptNormalized = str_replace('_', ' ', $prompt);
        $promptWords = $this->extractSignificantWords($promptNormalized);

        foreach ($schemaTables as $table) {
            $tableLower = strtolower($table);
            $tableClean = str_replace('_', ' ', $tableLower);

            // 1. Direct contains check
            if (str_contains($promptNormalized, $tableLower) || str_contains($promptNormalized, $tableClean)) {
                $matches[] = $table;
                continue;
            }

            // 2. Singular/plural contains check
            $singular = Str::singular($tableLower);
            $plural = Str::plural($tableLower);
            if (($singular !== $tableLower && str_contains($promptNormalized, $singular)) || ($plural !== $tableLower && str_contains($promptNormalized, $plural))) {
                $matches[] = $table;
                continue;
            }

            // 3. Dynamic Levenshtein Distance for Spelling / Transliteration Correction
            // Checks each prompt word against table names dynamically at runtime
            $matched = false;
            foreach ($promptWords as $pWord) {
                $pWordLen = strlen($pWord);
                if ($pWordLen < 4) {
                    continue;
                }
                // Determine allowed distance based on word length
                // Longer words allow more distance for transliteration (e.g. articles→artikels)
                $maxDist = match (true) {
                    $pWordLen >= 8 => 3,
                    $pWordLen >= 6 => 2,
                    default        => 1,
                };

                if (levenshtein($pWord, $tableLower) <= $maxDist
                    || levenshtein($pWord, $singular) <= $maxDist
                    || levenshtein($pWord, $tableClean) <= $maxDist) {
                    $matches[] = $table;
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            // 4. Stem/prefix cross-matching for non-English table names
            // e.g. "articles" shares stem "artic" with "artikels",
            //      "comments" shares stem "com" with "komentars" (weak, caught by segment check)
            foreach ($promptWords as $pWord) {
                $pWordLen = strlen($pWord);
                if ($pWordLen < 4) {
                    continue;
                }

                // Compare first N chars (stem) — minimum 4 chars for reliability
                $stemLen = max(4, (int) floor(min($pWordLen, strlen($tableLower)) * 0.6));

                $pStem     = substr($pWord, 0, $stemLen);
                $tStem     = substr($tableLower, 0, $stemLen);
                $sStem     = substr($singular, 0, $stemLen);

                if ($pStem === $tStem || $pStem === $sStem) {
                    $matches[] = $table;
                    $matched = true;
                    break;
                }

                // Also check if prompt word starts with table stem or vice versa
                if (str_starts_with($tableLower, substr($pWord, 0, 4))
                    || str_starts_with($singular, substr($pWord, 0, 4))
                    || str_starts_with($pWord, substr($tableLower, 0, 4))
                    || str_starts_with($pWord, substr($singular, 0, 4))) {
                    $matches[] = $table;
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            // 5. Word segments check
            $tableSegments = preg_split('/[_\s]+/', $tableLower);
            foreach ($tableSegments as $segment) {
                if (strlen($segment) < 3) {
                    continue;
                }
                $segSingular = Str::singular($segment);
                $segPlural   = Str::plural($segment);
                if (in_array($segSingular, $promptWords, true) || in_array($segPlural, $promptWords, true)) {
                    $matches[] = $table;
                    break;
                }
            }
        }

        return array_values(array_unique($matches));
    }

    // -------------------------------------------------------------------------
    // Helper checks
    // -------------------------------------------------------------------------

    protected function isPivotOrJunctionTable(string $table): bool
    {
        $table = strtolower($table);

        // Only flag tables with explicit pivot/junction naming patterns
        $pivotPatterns = ['/_has_/', '/_pivot_/', '/_to_/', '/_with_/'];
        foreach ($pivotPatterns as $pattern) {
            if (preg_match($pattern, $table)) {
                return true;
            }
        }

        // Do NOT flag tables with 3+ segments (e.g. order_line_items, user_login_logs)
        // Those are legitimate business tables, not pivot/junction tables.

        return false;
    }

    protected function isEmptyTable(string $table): bool
    {
        try {
            $connection = config('mcp-dashboard-studio.database.connection') ?? config('database.default');
            return ! DB::connection($connection)->table($table)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Section extraction
    // -------------------------------------------------------------------------

    protected function extractSectionItems(string $prompt, string $sectionName, array $schemaTables = []): array
    {
        $items = [];
        $sectionNamePattern = '/(?:' . preg_quote($sectionName, '/') . 's?|' . preg_quote(strtolower($sectionName), '/') . 's?)(?:\:|\-|\b)(.*?)(?:\n\n|\r\r|KPIs|Charts|Filters|Tables|$)/is';
        if (preg_match($sectionNamePattern, $prompt, $matches)) {
            $sectionContent = $matches[1];
            // Normalize separators: convert - or * to newlines
            $normalized = preg_replace('/[\-\*\•]\s+/', "\n", $sectionContent);

            // If table names are concatenated (e.g. stockbrands list:), split them!
            if ($sectionName === 'table' && ! empty($schemaTables)) {
                foreach ($schemaTables as $tName) {
                    $normalized = preg_replace('/(?<!\b)(' . preg_quote($tName, '/') . '\s+list\b)/i', "\n$1", $normalized);
                    $normalized = preg_replace('/(?<=\b)(' . preg_quote($tName, '/') . '\s+list\b)/i', "\n$1", $normalized);
                }
            }

            $lines = preg_split('/[\n\r]+/', $normalized);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // If it contains column definitions, do not split by comma
                if (preg_match('/(columns|cols|\bcols\b|=)/i', $line)) {
                    $subLines = [$line];
                } else {
                    $subLines = preg_split('/[,;]+/', $line);
                }
                foreach ($subLines as $subLine) {
                    $subLine = trim(preg_replace('/^[\-\*\d\.\s]+/i', '', trim($subLine)));
                    if ($subLine !== '') {
                        $parts = explode(':', $subLine, 2);
                        if (count($parts) === 2 && ! preg_match('/(columns|cols|=)/i', $parts[1])) {
                            $subLine = trim($parts[0]);
                        }
                        $items[] = strtolower($subLine);
                    }
                }
            }
        }
        return array_values(array_unique($items));
    }

    // -------------------------------------------------------------------------
    // Explicit Component Extraction
    // -------------------------------------------------------------------------

    protected function extractExplicitMetrics(string $prompt, array $schemaTables = []): array
    {
        $promptNormalized = str_replace('_', ' ', $prompt);
        $metrics = $this->extractSectionItems($promptNormalized, 'kpi', $schemaTables);
        $metrics = array_merge($metrics, $this->extractSectionItems($promptNormalized, 'metric', $schemaTables));

        if (preg_match_all('/\b(total|count|sum|avg|average|number of)\s+(?:of\s+)?([a-z_\s]+?)(?:[,\.\;]|\s+and\s+|\s+with\s+|\s+for\s+|\s+by\s+|\s+in\s+|$)/i', $promptNormalized, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $aggregate = strtolower(trim($match[1]));
                $entity    = strtolower(trim($match[2]));

                $entity = preg_replace('/\s+(showing|from|where|that|which)$/i', '', $entity);
                $entity = trim($entity);

                if ($entity !== '') {
                    $metrics[] = $aggregate . ' ' . $entity;
                }
            }
        }

        return array_values(array_unique(array_filter($metrics)));
    }

    protected function extractExplicitCharts(string $prompt, array $schemaTables = []): array
    {
        $promptNormalized = str_replace('_', ' ', $prompt);
        $charts = $this->extractSectionItems($promptNormalized, 'chart', $schemaTables);

        // ── Parse "[type] chart for [entity] by [column]" patterns ──
        // Handles: "a pie chart for employees by gender, a bar chart for add jobs by department"
        // Uses lookahead for boundaries to avoid greedy capture
        if (preg_match_all(
            '/\b(?:pie|bar|line|doughnut|donut|area)\s+chart\s+(?:for|of|showing)\s+([a-z_ ]+?)(?:\s+by\s+([a-z_ ]+?))?(?=[,;.]|\s+and\s+a\s+|\s+add\s+|\s+include|\s*$)/i',
            $promptNormalized, $m, PREG_SET_ORDER
        )) {
            foreach ($m as $match) {
                $entity  = strtolower(trim($match[1]));
                $groupBy = isset($match[2]) ? strtolower(trim($match[2])) : null;
                if ($groupBy) {
                    // Only add "entity by column" — don't add bare "entity" which
                    // would match "Over Time" charts and create duplicates
                    $charts[] = $entity . ' by ' . $groupBy;
                } else {
                    $charts[] = $entity;
                }
            }
        }

        // "chart of X" / "chart for X" — stop at clause boundaries, NOT end of string
        if (preg_match_all('/\b(?:chart|graph|trend|plot)\s+(?:of|for|showing)\s+([a-z_ ]+?)(?=[,;.]|\s+and\s+|\s+with\s+|\s+add\s+|\s+include|\s*$)/i', $promptNormalized, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $candidate = strtolower(trim($match[1]));
                if (strlen($candidate) < 50) { // Sanity check: reject overly long captures
                    $charts[] = $candidate;
                }
            }
        }

        // "X chart" / "X graph" — exclude chart type words
        if (preg_match_all('/\b([a-z_ ]+?)\s+(?:chart|graph|trend|plot)\b/i', $promptNormalized, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $candidate = strtolower(trim($match[1]));
                if (! in_array($candidate, ['a', 'the', 'my', 'our', 'this', 'bar', 'line', 'pie', 'doughnut', 'donut', 'area', 'include', 'add'], true)) {
                    $charts[] = $candidate;
                }
            }
        }

        return array_values(array_unique(array_filter($charts)));
    }

    protected function extractExplicitTables(string $prompt, array $schemaTables = []): array
    {
        $promptNormalized = str_replace('_', ' ', $prompt);
        $tables = $this->extractSectionItems($promptNormalized, 'table', $schemaTables);

        // ── NEW: Pre-split compound table requests ──
        // Splits "a data table of employees with columns X. Add a data table of leaves with columns Y"
        // into separate segments before parsing each independently
        $tableSegments = preg_split(
            '/(?:[;\.]\s*)(?=(?:a\s+)?(?:data\s+)?table\b)|(?:\s+and\s+a\s+(?:data\s+)?table\b)|(?:\.\s*Add\s+a\s+(?:data\s+)?table\b)/i',
            $promptNormalized
        );

        foreach ($tableSegments as $segment) {
            // Match "table of X with columns ..." or "table listing X"
            if (preg_match('/\b(?:table|list|listing|grid)\s+(?:of|for|showing|listing)\s+([a-z_\s]+?)(?:\s+with\s+columns?\s+|[,\.\;]|\s+and\s+|$)/i', $segment, $m)) {
                $candidate = strtolower(trim($m[1]));
                // Remove trailing noise like "all" from "listing all employees"
                $candidate = preg_replace('/^all\s+/i', '', $candidate);
                if (! in_array($candidate, ['a', 'the', 'my', 'our', 'this', 'data', 'generate', 'create', 'show'], true)) {
                    $tables[] = $candidate;
                }
            }

            // Match "X table" pattern
            if (preg_match('/\b([a-z_\s]+?)\s+(?:table|list|listing|grid)\b/i', $segment, $m)) {
                $candidate = strtolower(trim($m[1]));
                if (! in_array($candidate, ['a', 'the', 'my', 'our', 'this', 'data', 'generate', 'create', 'show', 'add'], true)) {
                    $tables[] = $candidate;
                }
            }
        }

        return array_values(array_unique(array_filter($tables)));
    }

    protected function extractExplicitFilters(string $prompt, array $schemaTables = []): array
    {
        $promptNormalized = str_replace('_', ' ', $prompt);
        $filters = $this->extractSectionItems($promptNormalized, 'filter', $schemaTables);

        if (preg_match_all('/\bfilter\s+(?:by|for|on|—|:)\s+([a-z_\s]+?)(?:[,\.\;]|$)/i', $promptNormalized, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $filters[] = strtolower(trim($match[1]));
            }
        }

        // Match: "X filter", "X filters" (e.g. "order_status filter", "payment method filters")
        if (preg_match_all('/\b([a-z_\s]+?)\s+filter(?:s)?\b/i', $promptNormalized, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $candidate = strtolower(trim($match[1]));
                if (!in_array($candidate, ['a', 'the', 'my', 'our', 'this', 'with', 'add', 'include', 'create', 'generate', 'show', 'and', 'or', 'no'])) {
                    $filters[] = $candidate;
                }
            }
        }

        return array_values(array_unique(array_filter($filters)));
    }

    // -------------------------------------------------------------------------
    // Explicit Matching
    // -------------------------------------------------------------------------

    protected function matchExplicitKpis(array $discoveredKpis, array $explicitMetrics, array $relevantTables): array
    {
        $matched = [];

        foreach ($explicitMetrics as $request) {
            $bestMatch = null;
            $bestScore = 0;

            $requestWords = array_filter(explode(' ', $request), fn ($w) => strlen($w) > 2);
            $requestAggregate = 'COUNT';
            foreach (['count' => 'COUNT', 'sum' => 'SUM', 'avg' => 'AVG', 'average' => 'AVG', 'total' => 'COUNT'] as $kw => $agg) {
                if (str_contains($request, $kw)) {
                    $requestAggregate = $agg;
                    break;
                }
            }

            foreach ($discoveredKpis as $kpi) {
                $title = strtolower($kpi['title'] ?? '');
                $table = strtolower($kpi['table'] ?? '');

                if ($table && ! in_array($table, array_map('strtolower', $relevantTables), true)) {
                    continue;
                }

                $score = 0;

                if (str_contains($title, $request) || str_contains($request, $title)) {
                    $score += 100;
                }

                $kpiAggregate = strtoupper($kpi['aggregate'] ?? 'COUNT');
                if ($requestAggregate === $kpiAggregate) {
                    $score += 20;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $kpi;
                }
            }

            if ($bestMatch && $bestScore >= 40) {
                $key = ($bestMatch['table'] ?? '') . ':' . ($bestMatch['aggregate'] ?? '') . ':' . ($bestMatch['column'] ?? '');
                if (! isset($matched[$key])) {
                    $matched[$key] = $bestMatch;
                }
            }
        }

        return array_values($matched);
    }

    protected function matchExplicitByTitle(array $discovered, array $explicitNames, array $relevantTables): array
    {
        $matched = [];

        foreach ($explicitNames as $request) {
            $request = trim($request, " \t\n\r\0\x0B.,;:-*•");
            if ($request === '') {
                continue;
            }
            $bestMatch = null;
            $bestScore = 0;

            $words = explode(' ', $request);
            $requestWords = [];
            foreach ($words as $w) {
                $wClean = trim($w, " \t\n\r\0\x0B.,;:-*•");
                if (strlen($wClean) > 2) {
                    $requestWords[] = $wClean;
                }
            }

            foreach ($discovered as $item) {
                $title = strtolower($item['title'] ?? '');
                $table = strtolower($item['table'] ?? '');

                if ($table && ! in_array($table, array_map('strtolower', $relevantTables), true)) {
                    continue;
                }

                $score = 0;
                $itemGroupBy = strtolower($item['group_by'] ?? '');

                // 1. Direct title match
                if (str_contains($title, $request) || str_contains($request, $title)) {
                    $score += 100;
                }

                // 1b. "by [column]" match — strongly prefer charts with matching group_by
                if (preg_match('/by\s+(\w+)/', $request, $byMatch)) {
                    $requestedGroupBy = strtolower(trim($byMatch[1]));
                    if ($itemGroupBy === $requestedGroupBy) {
                        $score += 200;  // Highest priority: exact group_by match
                    } elseif (str_contains($itemGroupBy, $requestedGroupBy) || str_contains($requestedGroupBy, $itemGroupBy)) {
                        $score += 120;
                    }
                }

                // 2. Exact table name match
                if ($table === $request) {
                    $score += 90;
                }

                // 3. Fuzzy title match: singular/plural of full request
                $singular = Str::singular($request);
                $plural   = Str::plural($request);
                if (str_contains($title, $singular) || str_contains($title, $plural)) {
                    $score += 50;
                }

                // 4. Table name segment matching
                $tableScore = 0;
                foreach ($requestWords as $word) {
                    $wordSingular = Str::singular($word);
                    $wordPlural   = Str::plural($word);

                    if ($table === $wordSingular || $table === $wordPlural) {
                        $tableScore = 70;
                        break;
                    } elseif (str_contains($table, $wordSingular) || str_contains($table, $wordPlural)) {
                        $tableScore = 40;
                    }
                }

                $score += $tableScore;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $item;
                }
            }

            if ($bestMatch && $bestScore >= 40) {
                // Clone the item to customize title and query properties per explicit request
                $customItem = $bestMatch;
                $customItem['original_request'] = $request;

                // Clean and format the title — strip noise words for professional display
                $customItem['title'] = $this->cleanComponentTitle($request, $bestMatch);

                // Parse limit dynamically (e.g. "trending top 4 products" -> limit = 4)
                if (preg_match('/\b(top|limit)\s*(\d+)/i', $request, $m)) {
                    $customItem['data']['limit'] = (int) $m[2];
                } elseif (preg_match('/\b(\d+)\s*(products|brands|items|users|customers)/i', $request, $m)) {
                    $customItem['data']['limit'] = (int) $m[1];
                }

                // Apply default sorting for trending/top requests (schema-agnostic)
                if (str_contains(strtolower($request), 'trending') || str_contains(strtolower($request), 'top')) {
                    $customItem['data']['sort'] = $customItem['data']['sort'] ?? 'created_at';
                    $customItem['data']['order'] = 'desc';
                }

                // Deduplicate by table+group_by — allow multiple charts per table
                // e.g. "Employees by Gender" and "Employees Over Time" are different
                $groupByKey = strtolower($customItem['group_by'] ?? 'default');
                $tableKey = strtolower($customItem['table'] ?? $customItem['title']) . ':' . $groupByKey;
                if (!isset($matched[$tableKey])) {
                    $matched[$tableKey] = $customItem;
                }
            }
        }

        return array_values($matched);
    }

    // -------------------------------------------------------------------------
    // Custom Columns Extraction
    // -------------------------------------------------------------------------

    protected function extractCustomColumns(string $table, array $allColumns, string $prompt): ?array
    {
        $tableLower = strtolower($table);
        $singular = Str::singular($tableLower);
        $plural = Str::plural($tableLower);

        $pos = -1;
        foreach ([$tableLower . ' list', $singular . ' list', $tableLower, $singular, $plural] as $term) {
            $p = strpos($prompt, $term);
            if ($p !== false) {
                $pos = $p;
                break;
            }
        }

        if ($pos === -1) {
            return null;
        }

        $segment = substr($prompt, $pos);
        if (preg_match('/^(.*?)(?:\n|\r|KPIs:|Tables:|Charts:|- |\* |$)/i', $segment, $matches)) {
            $segmentText = strtolower($matches[1]);
        } else {
            $segmentText = strtolower($segment);
        }

        if (! str_contains($segmentText, 'column') && ! str_contains($segmentText, 'col') && ! str_contains($segmentText, ':') && ! str_contains($segmentText, '=')) {
            return null;
        }

        $tokens = array_filter(preg_split('/[^a-z0-9_]+/', $segmentText));

        $matchedColumns = [];

        // Normalize token names
        $cleanTokens = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if (strlen($token) < 2 || in_array($token, ['column', 'columns', 'list', 'table', 'cols'], true)) {
                continue;
            }
            $cleanTokens[] = $token;
        }

        // Schema-agnostic virtual relationship column detection:
        // If a token matches a FK column pattern (e.g. 'brand' → 'brand_id' exists),
        // add it as a virtual column that DataSourceResolver will auto-join.
        foreach ($cleanTokens as $token) {
            $fkCandidate = $token . '_id';
            if (in_array($fkCandidate, array_map('strtolower', $allColumns), true)) {
                // This is a FK reference — add as virtual column for join resolution
                if (! in_array($token, $matchedColumns, true)) {
                    $matchedColumns[] = $token;
                }
            }

            // Support computed count columns (e.g. 'product_count')
            if (str_ends_with($token, '_count') && ! in_array($token, array_map('strtolower', $allColumns), true)) {
                $matchedColumns[] = $token;
            }
        }

        // Match database columns strictly
        foreach ($cleanTokens as $token) {

            // Check exact match first
            $exactMatched = false;
            foreach ($allColumns as $col) {
                if (strtolower($col) === $token) {
                    $matchedColumns[] = $col;
                    $exactMatched = true;
                    break;
                }
            }
            if ($exactMatched) {
                continue;
            }

            // Synonyms/Prefix matches
            if ($token === 'stock' || $token === 'quantity' || $token === 'qty') {
                foreach ($allColumns as $col) {
                    if (str_contains(strtolower($col), 'stock') || str_contains(strtolower($col), 'quantity')) {
                        $matchedColumns[] = $col;
                        break;
                    }
                }
                continue;
            }

            // Fallback to starts_with or contains only if no exact match is found for that token
            foreach ($allColumns as $col) {
                $colLower = strtolower($col);
                if (str_starts_with($colLower, $token)) {
                    $matchedColumns[] = $col;
                    break;
                }
            }
        }

        return ! empty($matchedColumns) ? array_values(array_unique($matchedColumns)) : null;
    }

    // -------------------------------------------------------------------------
    // Intent Detection
    // -------------------------------------------------------------------------

    protected function detectIntent(string $prompt): string
    {
        $hasMetricKeyword   = (bool) preg_match('/\b(total|count|sum|average|avg|number of|how many)\b/i', $prompt);
        $hasDashboardWord   = (bool) preg_match('/\b(dashboard|report|overview|analytics|panel)\b/i', $prompt);
        $hasGroupByPattern  = (bool) preg_match('/\b(grouped by|group by|per|by each|on each|for each|by\s+\w+|breakdown|distribution|split by)\b/i', $prompt);

        // Schema-agnostic entity detection: if the prompt contains significant
        // non-noise words beyond dashboard/metric keywords, treat as entity-specific.
        $significantWords = $this->extractSignificantWords($prompt);
        $hasSpecificEntity = count($significantWords) > 0;

        // Grouped metric: "how many X per Y" / "count of X by Y" → needs a chart, not raw table
        // Also catches "X grouped by Y" (grouping inherently implies aggregation)
        if ($hasGroupByPattern && ! $hasDashboardWord && ($hasMetricKeyword || $hasSpecificEntity)) {
            return 'grouped_metric';
        }

        if ($hasMetricKeyword && ! $hasDashboardWord) {
            return 'specific_metric';
        }

        if ($hasDashboardWord && ($hasMetricKeyword || $hasSpecificEntity)) {
            return 'focused_dashboard';
        }

        return 'full_dashboard';
    }

    // -------------------------------------------------------------------------
    // Budgets
    // -------------------------------------------------------------------------

    public function setBudgets(int $kpis = 4, int $charts = 3, int $tables = 2, int $filters = 2): self
    {
        $this->maxKpis    = $kpis;
        $this->maxCharts  = $charts;
        $this->maxTables  = $tables;
        $this->maxFilters = $filters;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function extractSignificantWords(string $text): array
    {
        $noise = ['the', 'and', 'for', 'from', 'with', 'that', 'this', 'how', 'many', 'much',
                  'show', 'generate', 'create', 'build', 'make', 'get', 'open', 'all', 'total',
                  'count', 'sum', 'avg', 'average', 'number', 'give', 'list', 'display', 'view',
                  'html', 'css', 'blade', 'json', 'page', 'full', 'complete', 'entire',
                  'system', 'data', 'using', 'please', 'want', 'need', 'render', 'design',
                  'dashboard', 'report', 'overview', 'analytics', 'panel', 'card', 'widget',
                  'kpi', 'chart', 'graph', 'table', 'filter', 'metric', 'stat', 'stats'];

        $words = preg_split('/[\s_,]+/', strtolower($text));

        return array_values(array_filter($words, function (string $w) use ($noise): bool {
            return strlen($w) >= 3 && ! in_array($w, $noise, true);
        }));
    }

    /**
     * Clean a component title from raw prompt text.
     * Strips noise words like 'kpi', 'table with', 'and', 'include', etc.
     * Falls back to the discovered metric title if cleaning empties everything.
     */
    protected function cleanComponentTitle(string $rawRequest, array $discoveredItem): string
    {
        // ── Schema-based title: if we have table + group_by, generate a clean title ──
        // This is ALWAYS better than cleaning prompt text (e.g. "Employees by Gender")
        $table   = $discoveredItem['table'] ?? null;
        $groupBy = $discoveredItem['group_by'] ?? null;
        if ($table && $groupBy) {
            return Str::title(str_replace('_', ' ', $table))
                . ' by '
                . Str::title(str_replace('_', ' ', $groupBy));
        }

        // ── For items without group_by, try the discovered title first ──
        // MetricDiscoveryService generates clean titles like "Total Employees"
        $discoveredTitle = $discoveredItem['title'] ?? null;
        if ($discoveredTitle && strlen($discoveredTitle) >= 3) {
            return $discoveredTitle;
        }

        // ── Fallback: clean the raw prompt text ──
        $title = $rawRequest;

        // Strip colon suffixes
        if (str_contains($title, ':')) {
            $title = trim(explode(':', $title)[0]);
        }

        // Strip component-type noise words
        $noisePatterns = [
            '/\b(kpi|kpis|chart|charts|table|tables|filter|filters|widget|widgets)\b/i',
            '/\b(with|showing|containing|including|that|which|include|display)\b/i',
            '/\b(and|or|for|from|the|a|an|of|in|on|by|to|is|are)\b/i',
            '/\b(total|count|number|sum|average|avg)\b/i',
            '/\b(generate|create|build|show|make|give|list|details|data)\b/i',
            // Chart type words that should NEVER appear in titles
            '/\b(bar|pie|doughnut|donut|line|area|scatter|radar|horizontal|vertical)\b/i',
            // Additional column/prompt noise
            '/\b(name|type|types|column|columns|field|fields|section|sections)\b/i',
            '/\b(all|every|each|available|use|using|elegent|elegant|correct|properly|completely)\b/i',
            '/\b(database|db|schema|sql|query|comprehensive|interactive|dynamic)\b/i',
            '/[\.\,\;]+/',
        ];

        foreach ($noisePatterns as $pattern) {
            $title = preg_replace($pattern, ' ', $title);
        }

        $title = trim(preg_replace('/\s+/', ' ', $title));

        // If cleaning removed everything, fall back to the discovered metric's title
        if (strlen($title) < 3) {
            return $discoveredItem['title'] ?? 'Component';
        }

        return Str::title($title);
    }

    // -------------------------------------------------------------------------
    // Grouped Aggregation Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the "group by" column from the prompt by matching against actual schema columns.
     *
     * Detects patterns like:
     *   - "per city", "by city", "on each city", "for each city"
     *   - "grouped by status", "group by type"
     *   - "breakdown by category", "split by region"
     *
     * Returns ['table' => 'properties', 'column' => 'city'] or null if no match.
     *
     * @param  string  $prompt
     * @param  array   $schema         Full schema from DatabaseSchemaExplorer
     * @param  array   $relevantTables Tables relevant to this prompt
     * @return array{table: string, column: string}|null
     */
    protected function extractGroupByColumn(string $prompt, array $schema, array $relevantTables): ?array
    {
        // Pattern: "by <word>", "per <word>", "on each <word>", "for each <word>", "grouped by <word>"
        $patterns = [
            '/\b(?:grouped\s+by|group\s+by|split\s+by|breakdown\s+by)\s+([a-z_]+)/i',
            '/\b(?:per|by\s+each|on\s+each|for\s+each)\s+([a-z_]+)/i',
            '/\b(?:per)\s+([a-z_]+)/i',
            // "how many X on each Y" or "count X by Y"
            '/\b(?:by)\s+([a-z_]+)\s*$/i',
            '/\b(?:by)\s+([a-z_]+)(?:\s+from|\s+in|\s+on|\s+where|\s*[,\.\;])/i',
        ];

        $candidateWords = [];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $m)) {
                $word = strtolower(trim($m[1]));
                if (strlen($word) >= 2 && ! in_array($word, ['the', 'all', 'each', 'every', 'this', 'that'], true)) {
                    $candidateWords[] = $word;
                }
            }
        }

        if (empty($candidateWords)) {
            return null;
        }

        // Match candidate words against actual schema columns in relevant tables
        foreach ($candidateWords as $word) {
            $singular = Str::singular($word);
            $plural   = Str::plural($word);

            foreach ($relevantTables as $table) {
                $columns = $schema[$table]['columns'] ?? [];
                foreach ($columns as $col) {
                    $colLower = strtolower($col['name']);
                    if ($colLower === $word || $colLower === $singular || $colLower === $plural
                        || str_contains($colLower, $word) || str_contains($colLower, $singular)) {
                        return ['table' => $table, 'column' => $col['name']];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find or build a distribution chart for a grouped aggregation query.
     *
     * First tries to find an existing discovered chart that matches the group_by column.
     * If none found, synthesizes a new pie/bar chart candidate.
     *
     * @param  array   $discoveredCharts  All charts from MetricDiscoveryService
     * @param  array   $relevantTables    Tables relevant to this prompt
     * @param  array   $groupByColumn     ['table' => '...', 'column' => '...']
     * @param  string  $prompt            Original prompt for title generation
     * @return array   Matched or synthesized chart candidates
     */
    protected function findGroupedCharts(array $discoveredCharts, array $relevantTables, array $groupByColumn, string $prompt): array
    {
        $targetTable  = $groupByColumn['table'];
        $targetColumn = $groupByColumn['column'];
        $matched = [];

        // Try to find an existing distribution chart that matches
        foreach ($discoveredCharts as $chart) {
            $chartTable   = $chart['table'] ?? '';
            $chartGroupBy = $chart['group_by'] ?? '';
            $chartType    = $chart['type'] ?? '';

            if (strtolower($chartTable) === strtolower($targetTable)
                && strtolower($chartGroupBy) === strtolower($targetColumn)
                && in_array($chartType, ['pie', 'donut', 'bar'], true)) {
                $matched[] = $chart;
            }
        }

        // If no exact match found, synthesize a distribution chart
        if (empty($matched)) {
            $humanTable  = Str::title(str_replace('_', ' ', $targetTable));
            $humanColumn = Str::title(str_replace('_', ' ', $targetColumn));

            $matched[] = [
                'title'     => "{$humanTable} by {$humanColumn}",
                'type'      => 'pie',
                'table'     => $targetTable,
                'group_by'  => $targetColumn,
                'aggregate' => 'COUNT',
            ];

            // Also add a bar chart variant for better visualization
            $matched[] = [
                'title'     => "Count of {$humanTable} per {$humanColumn}",
                'type'      => 'bar',
                'table'     => $targetTable,
                'group_by'  => $targetColumn,
                'aggregate' => 'COUNT',
            ];
        }

        return $matched;
    }
}
