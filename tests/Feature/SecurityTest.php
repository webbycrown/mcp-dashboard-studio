<?php
namespace Webbycrown\McpDashboardStudio\Tests\Feature;
use Webbycrown\McpDashboardStudio\Mcp\DataProviders\DatabaseProvider;
use Webbycrown\McpDashboardStudio\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class SecurityTest extends TestCase
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
        Schema::create("security_test_users", function ($table) {
            $table->id();
            $table->string("username");
            $table->string("email");
            $table->timestamps();
        });
        DB::table("security_test_users")->insert([
            ["username" => "admin", "email" => "admin@example.com", "created_at" => now(), "updated_at" => now()],
            ["username" => "user", "email" => "user@example.com", "created_at" => now(), "updated_at" => now()],
        ]);
    }
    public function test_sql_injection_in_table_name_is_prevented(): void
    {
        $maliciousTable = "security_test_users; DROP TABLE security_test_users; --";
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => $maliciousTable,
                "metric" => "COUNT",
                "column" => "*"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
        $this->assertNotNull($result["_error"]);
        Schema::hasTable("security_test_users");
    }
    public function test_sql_injection_in_column_name_is_prevented(): void
    {
        $maliciousColumn = "id; DROP TABLE security_test_users; --";
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "security_test_users",
                "metric" => "SUM",
                "column" => $maliciousColumn
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
        Schema::hasTable("security_test_users");
    }
    public function test_union_based_injection_is_prevented(): void
    {
        $maliciousInput = "id UNION SELECT username FROM security_test_users --";
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "security_test_users",
                "metric" => "COUNT",
                "column" => $maliciousInput
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
    }
    public function test_comment_based_injection_is_prevented(): void
    {
        $maliciousInput = "id/* comment */";
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "security_test_users",
                "metric" => "COUNT",
                "column" => $maliciousInput
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
    }
    public function test_boolean_based_injection_is_prevented(): void
    {
        $maliciousInput = "id OR 1=1";
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "security_test_users",
                "metric" => "COUNT",
                "column" => $maliciousInput
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
    }
    public function test_time_based_injection_is_prevented(): void
    {
        $maliciousInput = "id; WAITFOR DELAY \"0:0:5\" --";
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "security_test_users",
                "metric" => "COUNT",
                "column" => $maliciousInput
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
    }
    public function test_xss_in_column_values_is_escaped(): void
    {
        DB::table("security_test_users")->insert([
            ["username" => "<script>alert(\"XSS\")</script>", "email" => "xss@example.com", "created_at" => now(), "updated_at" => now()],
        ]);
        $component = [
            "type" => "table",
            "datasource" => [
                "table" => "security_test_users",
                "columns" => ["username"],
                "limit" => 10
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("data", $result);
        $this->assertArrayHasKey("rows", $result["data"]);
    }
    public function test_unauthorized_access_to_nonexistent_table(): void
    {
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "users",
                "metric" => "COUNT",
                "column" => "*"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
    }
    public function test_query_limit_is_respected(): void
    {
        for ($i = 0; $i < 100; $i++) {
            DB::table("security_test_users")->insert([
                ["username" => "user" . $i, "email" => "user" . $i . "@example.com", "created_at" => now(), "updated_at" => now()],
            ]);
        }
        $component = [
            "type" => "table",
            "datasource" => [
                "table" => "security_test_users",
                "columns" => ["username"],
                "limit" => 10
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertCount(10, $result["data"]["rows"]);
    }
    public function test_aggregate_functions_are_validated(): void
    {
        $invalidAggregate = "DANGEROUS_FUNCTION";
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "security_test_users",
                "metric" => $invalidAggregate,
                "column" => "id"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
    }
    public function test_special_characters_in_table_name_are_handled(): void
    {
        $specialCharsTable = "security_test_users OR 1=1";
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => $specialCharsTable,
                "metric" => "COUNT",
                "column" => "*"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("_error", $result);
    }
    public function test_filter_injection_is_prevented(): void
    {
        $maliciousFilter = ["username" => "admin OR 1=1"];
        $component = [
            "type" => "kpi",
            "datasource" => [
                "table" => "security_test_users",
                "metric" => "COUNT",
                "column" => "*"
            ]
        ];
        $result = $this->provider->resolve($component);
        $this->assertArrayHasKey("data", $result);
    }
}
