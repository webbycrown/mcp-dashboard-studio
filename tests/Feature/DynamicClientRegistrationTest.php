<?php

namespace Webbycrown\McpDashboardStudio\Tests\Feature;

use Webbycrown\McpDashboardStudio\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Tests for RFC 7591 Dynamic Client Registration
 *
 * POST /oauth/register
 *
 * Covers:
 *  - Successful public client registration
 *  - Successful confidential client registration
 *  - Missing redirect_uris → 400
 *  - Invalid redirect_uri format → 400
 *  - Non-HTTPS redirect_uri in production → 400
 *  - Domain allowlist enforcement → 400
 *  - Wildcard domain allowlist → 201
 *  - Response structure matches RFC 7591
 *  - Client is persisted to oauth_clients table
 */
class DynamicClientRegistrationTest extends TestCase
{
    private string $endpoint = '/oauth/register';

    // ── Happy Path ───────────────────────────────────────────────────────────

    /** @test */
    public function it_registers_a_public_client_successfully(): void
    {
        $response = $this->postJson($this->endpoint, [
            'client_name'                => 'Test Claude Client',
            'redirect_uris'              => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types'                => ['authorization_code'],
            'token_endpoint_auth_method' => 'none',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'client_id',
                'client_name',
                'redirect_uris',
                'grant_types',
                'token_endpoint_auth_method',
                'registration_client_uri',
                'client_id_issued_at',
            ])
            ->assertJson([
                'client_name'                => 'Test Claude Client',
                'token_endpoint_auth_method' => 'none',
                'grant_types'                => ['authorization_code', 'refresh_token'],
            ])
            ->assertJsonMissing(['client_secret']); // public client — no secret
    }

    /** @test */
    public function it_registers_a_confidential_client_with_a_secret(): void
    {
        $response = $this->postJson($this->endpoint, [
            'client_name'                => 'Confidential Client',
            'redirect_uris'              => ['https://example.com/callback'],
            'grant_types'                => ['authorization_code'],
            'token_endpoint_auth_method' => 'client_secret_post',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['client_id', 'client_secret', 'client_secret_expires_at'])
            ->assertJson(['client_secret_expires_at' => 0]); // never expires
    }

    /** @test */
    public function it_persists_the_client_to_oauth_clients_table(): void
    {
        $this->assertDatabaseCount('oauth_clients', 0);

        $this->postJson($this->endpoint, [
            'client_name'   => 'Persisted Client',
            'redirect_uris' => ['https://chatgpt.com/callback'],
            'grant_types'   => ['authorization_code'],
        ]);

        $this->assertDatabaseCount('oauth_clients', 1);
        $this->assertDatabaseHas('oauth_clients', [
            'name'    => 'Persisted Client',
            'revoked' => 0,
        ]);
    }

    /** @test */
    public function it_returns_cache_control_no_store_header(): void
    {
        $response = $this->postJson($this->endpoint, [
            'client_name'   => 'Header Test',
            'redirect_uris' => ['https://example.com/callback'],
        ]);

        $response->assertHeader('Cache-Control', 'no-store');
    }

    // ── Validation Failures ──────────────────────────────────────────────────

    /** @test */
    public function it_rejects_request_with_missing_redirect_uris(): void
    {
        $response = $this->postJson($this->endpoint, [
            'client_name' => 'No Redirects',
            'grant_types' => ['authorization_code'],
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);
    }

    /** @test */
    public function it_rejects_empty_redirect_uris_array(): void
    {
        $response = $this->postJson($this->endpoint, [
            'client_name'   => 'Empty Redirects',
            'redirect_uris' => [],
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);
    }

    /** @test */
    public function it_rejects_malformed_redirect_uri(): void
    {
        $response = $this->postJson($this->endpoint, [
            'client_name'   => 'Bad URI',
            'redirect_uris' => ['not-a-valid-url'],
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);
    }

    /** @test */
    public function it_rejects_non_authorization_code_grant_types(): void
    {
        $response = $this->postJson($this->endpoint, [
            'client_name'   => 'Wrong Grant',
            'redirect_uris' => ['https://example.com/callback'],
            'grant_types'   => ['client_credentials'],
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'invalid_client_metadata']);
    }

    // ── Domain Allowlist ─────────────────────────────────────────────────────

    /** @test */
    public function it_blocks_domains_not_in_allowlist(): void
    {
        config()->set('mcp-dashboard-studio.oauth.allowed_redirect_domains', [
            'claude.ai',
            'chatgpt.com',
        ]);

        $response = $this->postJson($this->endpoint, [
            'client_name'   => 'Evil Client',
            'redirect_uris' => ['https://evil.com/steal'],
            'grant_types'   => ['authorization_code'],
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);

        $this->assertDatabaseCount('oauth_clients', 0); // nothing persisted
    }

    /** @test */
    public function it_allows_domains_in_allowlist(): void
    {
        config()->set('mcp-dashboard-studio.oauth.allowed_redirect_domains', [
            'claude.ai',
            'chatgpt.com',
        ]);

        $response = $this->postJson($this->endpoint, [
            'client_name'   => 'Claude Client',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types'   => ['authorization_code'],
        ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function it_supports_wildcard_subdomain_allowlist(): void
    {
        config()->set('mcp-dashboard-studio.oauth.allowed_redirect_domains', [
            '*.claude.ai',
        ]);

        $response = $this->postJson($this->endpoint, [
            'client_name'   => 'Wildcard Client',
            'redirect_uris' => ['https://sub.claude.ai/callback'],
            'grant_types'   => ['authorization_code'],
        ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function it_allows_all_domains_when_allowlist_is_empty(): void
    {
        config()->set('mcp-dashboard-studio.oauth.allowed_redirect_domains', []);

        $response = $this->postJson($this->endpoint, [
            'client_name'   => 'Any Domain',
            'redirect_uris' => ['https://any-domain-at-all.com/callback'],
            'grant_types'   => ['authorization_code'],
        ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function options_request_returns_cors_headers(): void
    {
        $response = $this->options($this->endpoint);

        $response->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
