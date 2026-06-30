<?php

namespace Webbycrown\McpDashboardStudio\Mcp\DTO;

use JsonSerializable;

class DashboardSpec implements JsonSerializable
{
    public string $title;
    public string $description;
    public array $kpis = [];
    public array $charts = [];
    public array $tables = [];
    public array $filters = [];
    public array $layout = [];
    public array $meta = [];

    public function __construct(array $data = [])
    {
        $this->title = $data['title'] ?? 'Dashboard';
        $this->description = $data['description'] ?? 'A generated dashboard for the requested business needs.';
        $this->kpis = $data['kpis'] ?? [];
        $this->charts = $data['charts'] ?? [];
        $this->tables = $data['tables'] ?? [];
        $this->filters = $data['filters'] ?? [];
        $this->layout = $data['layout'] ?? [];
        $this->meta = $data['meta'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'kpis' => $this->kpis,
            'charts' => $this->charts,
            'tables' => $this->tables,
            'filters' => $this->filters,
            'layout' => $this->layout,
            'meta' => $this->meta,
        ];
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | $options);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
