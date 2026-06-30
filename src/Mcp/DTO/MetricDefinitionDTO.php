<?php

namespace Webbycrown\McpDashboardStudio\Mcp\DTO;

class MetricDefinitionDTO
{
    public function __construct(
        public string $title,
        public string $table,
        public string $aggregate,
        public string $column = '*',
        public string $unit = 'number',
        public string $format = 'number',
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'title'     => $this->title,
            'table'     => $this->table,
            'aggregate' => $this->aggregate,
            'column'    => $this->column,
            'unit'      => $this->unit,
            'format'    => $this->format,
            'meta'      => $this->meta,
        ];
    }
}
