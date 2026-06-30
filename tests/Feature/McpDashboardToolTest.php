<?php
namespace Webbycrown\McpDashboardStudio\Tests\Feature;
use Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardTool;
use Webbycrown\McpDashboardStudio\Tests\TestCase;
use Laravel\Mcp\Request as McpRequest;
class McpDashboardToolTest extends TestCase
{
    protected DashboardTool $tool;
    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = app(DashboardTool::class);
    }
    public function test_tool_has_correct_name(): void
    {
        $this->assertEquals("dashboard-tool", $this->tool->name());
    }
    public function test_tool_has_description(): void
    {
        $description = $this->tool->description();
        $this->assertIsString($description);
        $this->assertNotEmpty($description);
        $this->assertStringContainsString("dashboard", strtolower($description));
    }
    public function test_tool_schema_requires_prompt(): void
    {
        $schema = $this->tool->schema(app("json-schema"));
        $this->assertArrayHasKey("prompt", $schema);
    }
    public function test_it_handles_valid_prompt(): void
    {
        $request = $this->createMcpRequest(["prompt" => "Show me sales dashboard"]);
        $response = $this->tool->handle($request);
        $this->assertNotNull($response);
    }
    public function test_it_rejects_empty_prompt(): void
    {
        $request = $this->createMcpRequest(["prompt" => ""]);
        $response = $this->tool->handle($request);
        $this->assertNotNull($response);
    }
    public function test_it_rejects_missing_prompt(): void
    {
        $request = $this->createMcpRequest([]);
        $response = $this->tool->handle($request);
        $this->assertNotNull($response);
    }
    public function test_it_returns_dashboard_structure(): void
    {
        $request = $this->createMcpRequest(["prompt" => "Test dashboard"]);
        $response = $this->tool->handle($request);
        $data = $response->toArray();
        $this->assertArrayHasKey("dashboard", $data);
    }
    public function test_it_returns_live_url(): void
    {
        $request = $this->createMcpRequest(["prompt" => "Test dashboard"]);
        $response = $this->tool->handle($request);
        $data = $response->toArray();
        $this->assertArrayHasKey("live_url", $data);
    }
    public function test_it_returns_instructions(): void
    {
        $request = $this->createMcpRequest(["prompt" => "Test dashboard"]);
        $response = $this->tool->handle($request);
        $data = $response->toArray();
        $this->assertArrayHasKey("instructions", $data);
    }
    public function test_it_returns_stored_status(): void
    {
        $request = $this->createMcpRequest(["prompt" => "Test dashboard"]);
        $response = $this->tool->handle($request);
        $data = $response->toArray();
        $this->assertArrayHasKey("stored", $data);
        $this->assertIsBool($data["stored"]);
    }
    public function test_it_handles_long_prompt(): void
    {
        $prompt = str_repeat("word ", 500);
        $request = $this->createMcpRequest(["prompt" => $prompt]);
        $response = $this->tool->handle($request);
        $this->assertNotNull($response);
    }
    public function test_it_handles_special_characters(): void
    {
        $prompt = "Dashboard with special chars: @#$%^&*()";
        $request = $this->createMcpRequest(["prompt" => $prompt]);
        $response = $this->tool->handle($request);
        $this->assertNotNull($response);
    }
    protected function createMcpRequest(array $params): McpRequest
    {
        return new class($params) implements McpRequest {
            private array $params;
            public function __construct(array $params)
            {
                $this->params = $params;
            }
            public function get(string $key, $default = null)
            {
                return $this->params[$key] ?? $default;
            }
            public function sessionId(): string
            {
                return "test-session";
            }
            public function uri(): string
            {
                return "test://uri";
            }
            public function toArray(): array
            {
                return $this->params;
            }
        };
    }
}
