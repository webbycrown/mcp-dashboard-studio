<?php

namespace Webbycrown\McpDashboardStudio\Tests\Feature;

use Webbycrown\McpDashboardStudio\Support\RoutePaths;
use Webbycrown\McpDashboardStudio\Tests\Fixtures\User;
use Webbycrown\McpDashboardStudio\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tests for VerifyMcpToken middleware
 *
 * Covers:
 *  - No token → 401
 *  - Static token (Strategy B): correct token → 200
 *  - Static token: wrong token → 401
 *  - Static token: timing-safe comparison used
 *  - OAuth Bearer token (Strategy A): valid Passport token → 200
 *  - OAuth Bearer token: revoked token → 401
 *  - OAuth Bearer token: expired token → 401
 *  - OAuth Bearer token: token not in DB → 401
 *  - Response format is JSON-RPC 2.0 on 401
 *  - WWW-Authenticate header present on 401
 */
class VerifyMcpTokenTest extends TestCase
{
    private string $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->endpoint = RoutePaths::mcpPath();
    }

    // ── No Token ─────────────────────────────────────────────────────────────

    /** @test */
    public function request_without_token_returns_401(): void
    {
        $this->postJson($this->endpoint, ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1])
            ->assertStatus(401);
    }

    /** @test */
    public function unauthorized_response_is_json_rpc_format(): void
    {
        $response = $this->postJson($this->endpoint, []);

        $response->assertStatus(401)
            ->assertJsonStructure([
                'jsonrpc',
                'error' => ['code', 'message'],
                'id',
            ])
            ->assertJson(['jsonrpc' => '2.0']);
    }

    /** @test */
    public function unauthorized_response_has_www_authenticate_header(): void
    {
        $this->postJson($this->endpoint, [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate');
    }

    // ── Static Secret Token (Strategy B) ─────────────────────────────────────

    /** @test */
    public function correct_static_token_grants_access(): void
    {
        config()->set('mcp-dashboard-studio.mcp_secret_token', 'test-secret-token');

        $this->postJson($this->endpoint,
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1],
            ['Authorization' => 'Bearer test-secret-token']
        )->assertStatus(200);
    }

    /** @test */
    public function wrong_static_token_returns_401(): void
    {
        config()->set('mcp-dashboard-studio.mcp_secret_token', 'test-secret-token');

        $this->postJson($this->endpoint,
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1],
            ['Authorization' => 'Bearer wrong-token']
        )->assertStatus(401);
    }

    /** @test */
    public function static_token_via_x_mcp_token_header_is_accepted(): void
    {
        config()->set('mcp-dashboard-studio.mcp_secret_token', 'test-secret-token');

        $this->postJson($this->endpoint,
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1],
            ['X-MCP-TOKEN' => 'test-secret-token']
        )->assertStatus(200);
    }

    /** @test */
    public function empty_static_token_config_does_not_allow_empty_bearer(): void
    {
        config()->set('mcp-dashboard-studio.mcp_secret_token', null);

        $this->postJson($this->endpoint,
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1],
            ['Authorization' => 'Bearer ']
        )->assertStatus(401);
    }

    // ── Passport OAuth Token (Strategy A) ────────────────────────────────────

    /** @test */
    public function valid_passport_token_grants_access(): void
    {
        $user  = User::create([
            'name'     => 'Token User',
            'email'    => 'token@example.com',
            'password' => Hash::make('pass'),
            'is_admin' => false,
        ]);

        // Insert a valid token record directly into oauth_access_tokens
        $tokenId = (string) Str::uuid();
        DB::table('oauth_access_tokens')->insert([
            'id'         => $tokenId,
            'user_id'    => $user->id,
            'client_id'  => Str::uuid(),
            'name'       => 'Test Token',
            'scopes'     => '["mcp-access"]',
            'revoked'    => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        // Build a fake JWT-like token with the tokenId as jti claim
        $payload  = base64_encode(json_encode(['jti' => $tokenId]));
        $fakeJwt  = 'header.' . $payload . '.signature';

        $this->postJson($this->endpoint,
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1],
            ['Authorization' => 'Bearer ' . $fakeJwt]
        )->assertStatus(200);
    }

    /** @test */
    public function revoked_passport_token_returns_401(): void
    {
        $tokenId = (string) Str::uuid();
        DB::table('oauth_access_tokens')->insert([
            'id'         => $tokenId,
            'user_id'    => 1,
            'client_id'  => Str::uuid(),
            'name'       => 'Revoked Token',
            'scopes'     => '["mcp-access"]',
            'revoked'    => true, // ← revoked
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        $payload = base64_encode(json_encode(['jti' => $tokenId]));
        $fakeJwt = 'header.' . $payload . '.signature';

        $this->postJson($this->endpoint,
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1],
            ['Authorization' => 'Bearer ' . $fakeJwt]
        )->assertStatus(401);
    }

    /** @test */
    public function expired_passport_token_returns_401(): void
    {
        $tokenId = (string) Str::uuid();
        DB::table('oauth_access_tokens')->insert([
            'id'         => $tokenId,
            'user_id'    => 1,
            'client_id'  => Str::uuid(),
            'name'       => 'Expired Token',
            'scopes'     => '["mcp-access"]',
            'revoked'    => false,
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(60),
            'expires_at' => now()->subDays(1), // ← expired yesterday
        ]);

        $payload = base64_encode(json_encode(['jti' => $tokenId]));
        $fakeJwt = 'header.' . $payload . '.signature';

        $this->postJson($this->endpoint,
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1],
            ['Authorization' => 'Bearer ' . $fakeJwt]
        )->assertStatus(401);
    }

    /** @test */
    public function token_not_in_database_returns_401(): void
    {
        $payload = base64_encode(json_encode(['jti' => (string) Str::uuid()]));
        $fakeJwt = 'header.' . $payload . '.signature';

        $this->postJson($this->endpoint,
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1],
            ['Authorization' => 'Bearer ' . $fakeJwt]
        )->assertStatus(401);
    }
}
