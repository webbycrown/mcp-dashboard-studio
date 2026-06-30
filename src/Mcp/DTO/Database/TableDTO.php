<?php

namespace Webbycrown\McpDashboardStudio\Mcp\DTO\Database;

/**
 * TableDTO
 *
 * Represents a database table's metadata including its columns.
 * Hydrated dynamically from DatabaseSchemaExplorer output via fromArray().
 */
readonly class TableDTO
{
    public function __construct(
        /** Table name as it exists in the database */
        public string $tableName,

        /** Total number of rows in the table at the time of discovery */
        public int $rowCount,

        /** Ordered list of column descriptors for this table
         *  @var ColumnDTO[]
         */
        public array $columns,
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Hydrate a TableDTO from the raw schema-explorer output for a single table.
     *
     * Expected shape:
     * [
     *   'table_name' => 'orders',
     *   'row_count'  => 1024,
     *   'columns'    => [ [...], [...] ],   // raw column arrays
     * ]
     *
     * @param  string  $tableName  Table name (used as key by the explorer)
     * @param  array   $data       Raw data for this table from DatabaseSchemaExplorer
     */
    public static function fromArray(string $tableName, array $data): self
    {
        $columns = array_map(
            fn (array $col) => ColumnDTO::fromArray($col),
            $data['columns'] ?? []
        );

        return new self(
            tableName: $tableName,
            rowCount : (int) ($data['row_count'] ?? 0),
            columns  : $columns,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Find a column by name. Returns null if not found.
     */
    public function getColumn(string $name): ?ColumnDTO
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Return only the primary key column(s).
     *
     * @return ColumnDTO[]
     */
    public function primaryKeyColumns(): array
    {
        return array_values(
            array_filter($this->columns, fn (ColumnDTO $c) => $c->primaryKey)
        );
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * Serialize to a plain array for JSON output or further processing.
     */
    public function toArray(): array
    {
        return [
            'table_name' => $this->tableName,
            'row_count'  => $this->rowCount,
            'columns'    => array_map(fn (ColumnDTO $c) => $c->toArray(), $this->columns),
        ];
    }
}
