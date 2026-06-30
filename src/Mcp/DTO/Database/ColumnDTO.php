<?php

namespace Webbycrown\McpDashboardStudio\Mcp\DTO\Database;

/**
 * ColumnDTO
 *
 * Represents a single column's metadata within a database table.
 * Hydrated dynamically from Schema::getColumns() output via fromArray().
 */
readonly class ColumnDTO
{
    public function __construct(
        /** Column name as returned by the database driver */
        public string $name,

        /** Full column type string, e.g. "varchar(255)", "bigint", "text" */
        public string $type,

        /** Whether the column accepts NULL values */
        public bool $nullable,

        /** Whether this column is (part of) the primary key */
        public bool $primaryKey,

        /** Base type name without length/precision, e.g. "varchar", "int" */
        public string $typeName = '',

        /** Column default value, or null if none defined */
        public mixed $default = null,

        /** Optional column comment from the database schema */
        public ?string $comment = null,
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Hydrate a ColumnDTO from a raw column array (Schema::getColumns() row).
     *
     * Expected keys: name, type, type_name, nullable, default, auto_increment, comment
     * Maps dynamically — unknown keys are safely ignored.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            type: $data['type']      ?? 'unknown',
            nullable: (bool) ($data['nullable']       ?? false),
            primaryKey: (bool) ($data['auto_increment']  ?? false),
            typeName: $data['type_name'] ?? '',
            default: $data['default']   ?? null,
            comment: $data['comment']   ?? null,
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
            'name'       => $this->name,
            'type'       => $this->type,
            'type_name'  => $this->typeName,
            'nullable'   => $this->nullable,
            'primary_key' => $this->primaryKey,
            'default'    => $this->default,
            'comment'    => $this->comment,
        ];
    }
}
