<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Nlp;

use Webbycrown\McpDashboardStudio\Mcp\Services\Contracts\NlpClientInterface;
use Illuminate\Support\Str;

class DefaultNlpClient implements NlpClientInterface
{
    public function interpret(string $prompt): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($prompt)));
        $summary = $this->summarize($clean);
        $componentHints = $this->generateComponentHints($clean);

        return [
            'analysis' => [
                'intent' => $this->extractIntent($clean),
                'audience' => $this->extractAudience($clean),
                'objectives' => $this->extractObjectives($clean),
                'title' => $this->suggestTitle($clean),
                'summary' => $summary,
                'tokenCount' => str_word_count($clean),
            ],
            'componentHints' => $componentHints,
        ];
    }

    protected function summarize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'A dynamic dashboard generated from user requirements.';
        }

        $entities = $this->extractEntityNouns($text);
        if (empty($entities)) {
            return 'Interactive analytics dashboard with real-time data from the database.';
        }

        $formatted = array_map(fn($e) => Str::title($e), array_slice($entities, 0, 5));
        $last = array_pop($formatted);
        $list = empty($formatted) ? $last : implode(', ', $formatted) . ' and ' . $last;

        return "Interactive analytics dashboard covering {$list} with real-time data from the database.";
    }

    protected function suggestTitle(string $text): string
    {
        $entities = $this->extractEntityNouns($text);

        if (empty($entities)) {
            return 'Analytics Dashboard';
        }

        // Deduplicate singular/plural variants
        $unique = [];
        foreach ($entities as $entity) {
            $singular = Str::singular(strtolower($entity));
            if (!isset($unique[$singular])) {
                $unique[$singular] = Str::title($entity);
            }
        }

        $parts = array_values(array_slice($unique, 0, 4));
        $title = implode(', ', $parts);

        return Str::limit($title, 50, '') . ' Dashboard';
    }

    /**
     * Extract meaningful entity nouns from prompt text.
     * Strips verbs, fillers, instructions, and metric keywords.
     */
    protected function extractEntityNouns(string $text): array
    {
        // First sentence only
        $parts = preg_split('/[\.\?\!]/', $text, 2);
        $candidate = trim($parts[0] ?? $text);

        // Strip instruction suffixes
        $candidate = preg_replace('/\b(use\s+my|don\'?t|please\s+use|also\s+use|and\s+also)\b.*$/i', '', $candidate);

        // Clean to letters/spaces/commas
        $candidate = preg_replace('/[^a-zA-Z\s,]/', ' ', $candidate);

        // Strip leading action verbs
        $candidate = preg_replace(
            '/^\s*(generate|create|build|show|make|design|give|display|produce|render|construct|analyze|analyse)\s*/i',
            '', $candidate
        );
        $candidate = preg_replace('/^\s*(me|us)\s+/i', '', $candidate);

        // Comprehensive noise words to prevent prompt leakage into titles
        $noise = [
            'a','an','the','my','our','its','your','their',
            'comprehensive','analytical','detailed','complete','interactive','dynamic','full',
            'nice','good','great','beautiful','professional','modern','advanced','simple',
            'which','that','is','are','was','were','be','been',
            'contain','contains','containing','include','includes','including',
            'has','have','having','with','about','for','from','of','in','on',
            'and','or','those','these','this','data','information','details','list','records',
            'total','count','number','sum','average','avg',
            'dashboard','report','overview','analytics','panel',
            // Extended noise — prevents generic prompt words from becoming titles
            'all','every','single','available','use','using','used',
            'database','tables','table','columns','column','schema','db',
            'correct','elegant','elegent','proper','properly','correctly',
            'want','need','should','would','could','can','get',
            'very','most','really','just','also','too','enough',
            'based','driven','powered','real','time','realtime','real-time',
            'section','sections','following','each','type','types',
            'please','like','must',
            'not','only','but','any','some','more','new','old',
            'hrms','hrm','hris','erp','crm','system',
            'completely','entirely','fully','totally','absolutely',
            'necessary','needed','required','important','relevant',
            'generate','create','build','show','make','design','give','display',
            'produce','render','construct','analyze','analyse',
            'showing','generating','creating','building','displaying','making',
            'producing','rendering','constructing','analyzing','analysing',
        ];

        $tokens = preg_split('/[\s,]+/', strtolower($candidate));
        $entities = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if (strlen($token) >= 3 && !in_array($token, $noise, true)) {
                $entities[] = $token;
            }
        }

        return array_values(array_unique($entities));
    }

    protected function extractAudience(string $text): string
    {
        if (preg_match('/for\s+([a-zA-Z\s]+?)(?:\.|,|\s|$)/i', $text, $matches)) {
            return trim($matches[1]);
        }

        return 'business stakeholders';
    }

    protected function extractIntent(string $text): string
    {
        if (preg_match('/\b(?:track|monitor|analyze|visualize|compare|measure|evaluate|optimize)\b/i', $text, $matches)) {
            return strtolower($matches[0]);
        }

        return 'analyze';
    }

    protected function extractObjectives(string $text): array
    {
        $clauses = preg_split('/[\.\?\!]/', $text);
        $objectives = [];

        foreach ($clauses as $clause) {
            $clause = trim($clause);

            if ($clause === '') {
                continue;
            }

            $objectives[] = Str::limit($clause, 120, '');
        }

        return array_values(array_filter($objectives));
    }



    protected function generateComponentHints(string $text): array
    {
        $tokens = explode(' ', trim($text));
        $total = max(1, count($tokens));

        $kpiCount = max(1, (int) round($total / 15));
        $chartCount = max(1, (int) round($total / 24));
        $tableCount = max(0, (int) round($total / 30));
        $filterCount = max(0, (int) round($total / 28));

        $phrases = $this->extractKeyPhrases($text);
        $defaults = array_slice($phrases, 0, max($kpiCount + $chartCount + $tableCount + $filterCount, 4));

        $hints = [];
        $cursor = 0;

        for ($i = 0; $i < $kpiCount; $i++) {
            $label = $defaults[$cursor++] ?? 'Performance';
            $hints[] = [
                'type' => 'kpi',
                'label' => $label,
                'format' => 'number',
                'meta' => ['source' => 'dynamic'],
            ];
        }

        for ($i = 0; $i < $chartCount; $i++) {
            $label = $defaults[$cursor++] ?? 'Trend';
            $hints[] = [
                'type' => 'chart',
                'label' => $label,
                'chartType' => $this->inferChartType($text),
                'options' => ['responsive' => true],
                'meta' => ['source' => 'dynamic'],
            ];
        }

        for ($i = 0; $i < $tableCount; $i++) {
            $label = $defaults[$cursor++] ?? 'Data Table';
            $hints[] = [
                'type' => 'table',
                'label' => $label,
                'columns' => $this->buildColumnHints($text),
                'meta' => ['source' => 'dynamic'],
            ];
        }

        for ($i = 0; $i < $filterCount; $i++) {
            $label = $defaults[$cursor++] ?? 'Filter';
            $hints[] = [
                'type' => 'filter',
                'label' => $label,
                'field' => Str::snake($label),
                'control' => 'select',
                'options' => $this->sampleFilterOptions($text),
                'meta' => ['source' => 'dynamic'],
            ];
        }

        return $hints;
    }

    protected function extractKeyPhrases(string $text): array
    {
        $text = strtolower($text);
        $stopwords = explode(' ', 'for the and of to with from by on at in about into across through between among during before after while against without within along');
        $words = array_values(array_filter(array_map('trim', preg_split('/[^a-z0-9]+/', $text))));
        $phrases = [];
        $chunk = [];

        foreach ($words as $word) {
            if (in_array($word, $stopwords, true)) {
                if ($chunk !== []) {
                    $phrases[] = implode(' ', $chunk);
                    $chunk = [];
                }

                continue;
            }

            $chunk[] = $word;

            if (count($chunk) === 2) {
                $phrases[] = implode(' ', $chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $phrases[] = implode(' ', $chunk);
        }

        return array_values(array_unique(array_filter($phrases)));
    }

    protected function inferChartType(string $text): string
    {
        if (preg_match('/(trend|growth|change|over time)/i', $text)) {
            return 'line';
        }

        if (preg_match('/(share|breakdown|distribution|composition)/i', $text)) {
            return 'pie';
        }

        return 'bar';
    }

    protected function buildColumnHints(string $text): array
    {
        $phrases = $this->extractKeyPhrases($text);
        $columns = [];

        foreach (array_slice($phrases, 0, 5) as $phrase) {
            $columns[] = [
                'key' => Str::snake($phrase),
                'label' => Str::title($phrase),
            ];
        }

        if ($columns === []) {
            $columns = [
                ['key' => 'metric', 'label' => 'Metric'],
                ['key' => 'value', 'label' => 'Value'],
            ];
        }

        return $columns;
    }

    protected function sampleFilterOptions(string $text): array
    {
        $phrases = $this->extractKeyPhrases($text);

        return array_slice(array_map(fn ($phrase) => Str::title($phrase), $phrases), 0, 4) ?: ['Default'];
    }
}
