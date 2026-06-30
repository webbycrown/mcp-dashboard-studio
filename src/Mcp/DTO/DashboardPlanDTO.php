<?php

namespace Webbycrown\McpDashboardStudio\Mcp\DTO;

class DashboardPlanDTO
{
    public string $title;
    public string $description;
    public array $meta = [];
    public array $kpiPlan = [];
    public array $chartPlan = [];
    public array $tablePlan = [];
    public array $filterPlan = [];

    public function __construct(array $data = [])
    {
        $this->title = $data['title'] ?? 'Dashboard';
        $this->description = $data['description'] ?? 'A generated dashboard for business users.';
        $this->meta = $data['meta'] ?? [];
        $this->kpiPlan = $data['kpiPlan'] ?? [];
        $this->chartPlan = $data['chartPlan'] ?? [];
        $this->tablePlan = $data['tablePlan'] ?? [];
        $this->filterPlan = $data['filterPlan'] ?? [];
    }
}
