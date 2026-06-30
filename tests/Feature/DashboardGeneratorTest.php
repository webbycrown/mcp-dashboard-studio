<?php
namespace Webbycrown\McpDashboardStudio\Tests\Feature;
use Webbycrown\McpDashboardStudio\Mcp\Services\DashboardGenerator;
use Webbycrown\McpDashboardStudio\Tests\TestCase;
class DashboardGeneratorTest extends TestCase
{
    protected DashboardGenerator $generator;
    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = app(DashboardGenerator::class);
    }
    public function test_it_generates_dashboard_config_from_prompt(): void
    {
        $prompt = "Show me sales overview";
        $config = $this->generator->generateDashboardConfig($prompt);
        $this->assertIsArray($config);
        $this->assertArrayHasKey("title", $config);
        $this->assertArrayHasKey("description", $config);
        $this->assertArrayHasKey("components", $config);
    }
    public function test_it_returns_array_structure(): void
    {
        $prompt = "Test dashboard";
        $config = $this->generator->generateDashboardConfig($prompt);
        $this->assertIsArray($config);
    }
    public function test_it_handles_empty_prompt(): void
    {
        $prompt = "";
        $config = $this->generator->generateDashboardConfig($prompt);
        $this->assertIsArray($config);
    }
    public function test_it_handles_long_prompt(): void
    {
        $prompt = str_repeat("word ", 500);
        $config = $this->generator->generateDashboardConfig($prompt);
        $this->assertIsArray($config);
    }
    public function test_config_contains_required_fields(): void
    {
        $prompt = "Sales dashboard";
        $config = $this->generator->generateDashboardConfig($prompt);
        $this->assertArrayHasKey("title", $config);
        $this->assertArrayHasKey("description", $config);
        $this->assertArrayHasKey("components", $config);
    }
    public function test_it_generates_renderable_dashboard(): void
    {
        $prompt = "Test dashboard";
        $rendered = $this->generator->renderDashboard($prompt);
        $this->assertIsArray($rendered);
    }
}
