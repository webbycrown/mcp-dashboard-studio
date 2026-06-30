<?php
namespace Webbycrown\McpDashboardStudio\Tests\Feature;
use Webbycrown\McpDashboardStudio\Mcp\DataProviders\DatabaseProvider;
use Webbycrown\McpDashboardStudio\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class DatabaseProviderTest extends TestCase
{
    protected DatabaseProvider $provider;
    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = app(DatabaseProvider::class);
        $this->createTestTable();
    }
    protected function createTestTable(): void
    {
        Schema::create("test_products", function ($table) {
            $table->id();
            $table->string("name");
            $table->decimal("price", 10, 2);
            $table->integer("quantity");
            $table->string("category");
            $table->timestamps();
        });
        DB::table("test_products")->insert([
            ["name" => "Product A", "price" => 100.00, "quantity" => 50, "category" => "Electronics", "created_at" => now(), "updated_at" => now()],
            ["name" => "Product B", "price" => 200.00, "quantity" => 30, "category" => "Electronics", "created_at" => now(), "updated_at" => now()],
            ["name" => "Product C", "price" => 50.00, "quantity" => 100, "category" => "Books", "created_at" => now(), "updated_at" => now()],
        ]);
    }
    public function test_it_resolves_kpi_component(): void
    {
        $component = [
            "type" => "kpi",
            "title" => "Total Products",
            "datasource" => [
                "table" => "test_products",
                "metric" => "COUNT",
                "column" => "*"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("data", $result);
        $this->assertArrayHasKey("value", $result["data"]);
        $this->assertEquals(3, $result["data"]["value"]);
    }
    public function test_it_resolves_sum_aggregate(): void
    {
        $component = [
            "type" => "kpi",
            "title" => "Total Revenue",
            "datasource" => [
                "table" => "test_products",
                "metric" => "SUM",
                "column" => "price"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertEquals(350.00, $result["data"]["value"]);
    }
    public function test_it_resolves_avg_aggregate(): void
    {
        $component = [
            "type" => "kpi",
            "title" => "Average Price",
            "datasource" => [
                "table" => "test_products",
                "metric" => "AVG",
                "column" => "price"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertEquals(116.67, round($result["data"]["value"], 2));
    }
    public function test_it_resolves_min_aggregate(): void
    {
        $component = [
            "type" => "kpi",
            "title" => "Min Price",
            "datasource" => [
                "table" => "test_products",
                "metric" => "MIN",
                "column" => "price"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertEquals(50.00, $result["data"]["value"]);
    }
    public function test_it_resolves_max_aggregate(): void
    {
        $component = [
            "type" => "kpi",
            "title" => "Max Price",
            "datasource" => [
                "table" => "test_products",
                "metric" => "MAX",
                "column" => "price"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertEquals(200.00, $result["data"]["value"]);
    }
    public function test_it_resolves_table_component(): void
    {
        $component = [
            "type" => "table",
            "title" => "Products",
            "datasource" => [
                "table" => "test_products",
                "columns" => ["name", "price", "category"],
                "limit" => 10
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("data", $result);
        $this->assertArrayHasKey("rows", $result["data"]);
        $this->assertCount(3, $result["data"]["rows"]);
    }
    public function test_it_resolves_chart_component(): void
    {
        $component = [
            "type" => "chart",
            "chart_type" => "pie",
            "title" => "Products by Category",
            "datasource" => [
                "table" => "test_products",
                "group_by" => "category",
                "aggregate" => "COUNT"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("data", $result);
        $this->assertArrayHasKey("labels", $result["data"]);
        $this->assertArrayHasKey("datasets", $result["data"]);
    }
    public function test_it_handles_nonexistent_table(): void
    {
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "nonexistent_table",
                "metric" => "COUNT",
                "column" => "*"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
    }
    public function test_it_handles_invalid_column(): void
    {
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "test_products",
                "metric" => "SUM",
                "column" => "invalid_column"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
    }
    public function test_it_respects_query_limit(): void
    {
        $component = [
            "type" => "table",
            "datasource" => [
                "table" => "test_products",
                "columns" => ["name"],
                "limit" => 2
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertCount(2, $result["data"]["rows"]);
    }
    public function test_it_detects_component_kind(): void
    {
        $kpiComponent = ["type" => "kpi", "aggregate" => "COUNT"];
        $chartComponent = ["type" => "line"];
        $tableComponent = ["columns" => ["name"]];
        $filterComponent = ["type" => "select"];
        $this->assertEquals("kpi", $this->provider->detectComponentKind($kpiComponent));
        $this->assertEquals("chart", $this->provider->detectComponentKind($chartComponent));
        $this->assertEquals("table", $this->provider->detectComponentKind($tableComponent));
        $this->assertEquals("filter", $this->provider->detectComponentKind($filterComponent));
    }
}
