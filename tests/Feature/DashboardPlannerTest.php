<?php
namespace Webbycrown\McpDashboardStudio\Tests\Feature;
use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardPlanDTO;
use Webbycrown\McpDashboardStudio\Mcp\Services\DashboardPlanner;
use Webbycrown\McpDashboardStudio\Tests\TestCase;
class DashboardPlannerTest extends TestCase
{
    protected DashboardPlanner $planner;
    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = app(DashboardPlanner::class);
    }
    public function test_it_creates_a_plan_from_prompt(): void
    {
        $prompt = "Show me sales overview with revenue and orders";
        $plan = $this->planner->plan($prompt);
        $this->assertInstanceOf(DashboardPlanDTO::class, $plan);
        $this->assertIsString($plan->title);
        $this->assertIsString($plan->description);
        $this->assertIsArray($plan->meta);
        $this->assertIsArray($plan->kpiPlan);
        $this->assertIsArray($plan->chartPlan);
        $this->assertIsArray($plan->tablePlan);
        $this->assertIsArray($plan->filterPlan);
    }
    public function test_it_generates_title_from_prompt(): void
    {
        $prompt = "sales dashboard";
        $plan = $this->planner->plan($prompt);
        $this->assertNotEmpty($plan->title);
        $this->assertStringStartsWith("S", $plan->title);
    }
    public function test_it_generates_description_from_prompt(): void
    {
        $prompt = "Show me sales overview with revenue and orders";
        $plan = $this->planner->plan($prompt);
        $this->assertNotEmpty($plan->description);
        $this->assertStringContainsString("sales", strtolower($plan->description));
    }
    public function test_it_truncates_long_descriptions(): void
    {
        $prompt = str_repeat("a ", 200);
        $plan = $this->planner->plan($prompt);
        $this->assertLessThanOrEqual(140, strlen($plan->description));
    }
    public function test_it_sets_meta_information(): void
    {
        $prompt = "Build a dashboard";
        $plan = $this->planner->plan($prompt);
        $this->assertArrayHasKey("intent", $plan->meta);
        $this->assertArrayHasKey("audience", $plan->meta);
        $this->assertArrayHasKey("objectives", $plan->meta);
        $this->assertArrayHasKey("verb", $plan->meta);
    }
    public function test_it_organizes_component_hints_by_type(): void
    {
        $prompt = "KPIs, charts, and tables";
        $plan = $this->planner->plan($prompt);
        $this->assertIsArray($plan->kpiPlan);
        $this->assertIsArray($plan->chartPlan);
        $this->assertIsArray($plan->tablePlan);
        $this->assertIsArray($plan->filterPlan);
    }
    public function test_it_handles_empty_prompt(): void
    {
        $prompt = "";
        $plan = $this->planner->plan($prompt);
        $this->assertInstanceOf(DashboardPlanDTO::class, $plan);
        $this->assertIsString($plan->title);
    }
    public function test_it_handles_very_long_prompt(): void
    {
        $prompt = str_repeat("word ", 1000);
        $plan = $this->planner->plan($prompt);
        $this->assertInstanceOf(DashboardPlanDTO::class, $plan);
        $this->assertLessThanOrEqual(60, strlen($plan->title));
    }
    public function test_plan_contains_all_required_sections(): void
    {
        $prompt = "Test dashboard";
        $plan = $this->planner->plan($prompt);
        $this->assertArrayHasKey("title", $plan->toArray());
        $this->assertArrayHasKey("description", $plan->toArray());
        $this->assertArrayHasKey("meta", $plan->toArray());
        $this->assertArrayHasKey("kpiPlan", $plan->toArray());
        $this->assertArrayHasKey("chartPlan", $plan->toArray());
        $this->assertArrayHasKey("tablePlan", $plan->toArray());
        $this->assertArrayHasKey("filterPlan", $plan->toArray());
    }
}
