<?php

namespace Webbycrown\McpDashboardStudio\Mcp\DTO\Database;

/**
 * DatabaseSchemaDTO
 *
 * Top-level DTO representing the full schema of a discovered database.
 * Contains all tables (as TableDTOs), detected relationships, and the database name.
 *
 * Hydrated dynamically from DatabaseSchemaExplorer::explore() output.
 */
readonly class DatabaseSchemaDTO
{
    public function __construct(
        /** Name of the database (schema) that was explored */
        public string $databaseName,

        /** All discovered tables keyed by table name
         *  @var TableDTO[]
         */
        public array $tables,

        /** Detected relationships between tables (from RelationshipDetector)
         *  Each entry: ['from_table', 'from_column', 'to_table', 'to_column', 'type']
         *  @var array[]
         */
        public array $relationships,
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Build a DatabaseSchemaDTO from the raw output of DatabaseSchemaExplorer::explore()
     * combined with RelationshipDetector output and optional row counts.
     *
     * @param  string   $databaseName   DB name (e.g. from DB::connection()->getDatabaseName())
     * @param  array    $explorerOutput Raw output: ['table' => ['columns'=>[...], 'indexes'=>[...], 'foreign_keys'=>[...]]]
     * @param  array    $rowCounts      Optional map of table → row count: ['orders' => 1024]
     * @param  array    $relationships  Detected relationships from RelationshipDetector
     */
    public static function fromExplorerOutput(
        string $databaseName,
        array  $explorerOutput,
        array  $rowCounts    = [],
        array  $relationships = [],
    ): self {
        $tables = [];

        foreach ($explorerOutput as $tableName => $tableData) {
            // Merge in the row count if provided
            $tableData['row_count'] = $rowCounts[$tableName] ?? 0;

            $tables[$tableName] = TableDTO::fromArray($tableName, $tableData);
        }

        return new self(
            databaseName : $databaseName,
            tables       : $tables,
            relationships: $relationships,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return a single TableDTO by name. Returns null if table not found.
     */
    public function getTable(string $tableName): ?TableDTO
    {
        return $this->tables[$tableName] ?? null;
    }

    /**
     * Return all table names in this schema.
     *
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Return the total number of tables in the schema.
     */
    public function tableCount(): int
    {
        return count($this->tables);
    }

    /**
     * Return relationships that involve a given table (either side of the relation).
     *
     * @return array[]
     */
    public function relationshipsFor(string $tableName): array
    {
        return array_values(
            array_filter(
                $this->relationships,
                fn (array $rel) => $rel['from_table'] === $tableName
                    || $rel['to_table'] === $tableName
            )
        );
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * Serialize to a plain array for JSON output or MCP tool responses.
     */
    public function toArray(): array
    {
        return [
            'database_name' => $this->databaseName,
            'table_count'   => $this->tableCount(),
            'tables'        => array_map(
                fn (TableDTO $t) => $t->toArray(),
                $this->tables
            ),
            'relationships' => $this->relationships,
        ];
    }
}
