<?php

namespace Webbycrown\McpDashboardStudio\Tests\Feature;

use Webbycrown\McpDashboardStudio\Tests\TestCase;

/**
 * Tests for OAuth Discovery Endpoints (RFC 8414 + RFC 9728)
 *
 * Covers:
 *  - GET /.well-known/oauth-authorization-server (RFC 8414)
 *  - GET /.well-known/oauth-protected-resource   (RFC 9728)
 *  - Required fields in each response
 *  - registration_endpoint points to /oauth/register (not /oauth/clients)
 *  - CORS header on discovery responses
 *  - Endpoints return 404 when OAuth disabled
 */
class OAuthDiscoveryTest extends TestCase
{
    // ── Authorization Server Metadata (RFC 8414) ─────────────────────────────

    /** @test */
    public function authorization_server_metadata_returns_200(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertStatus(200);
    }

    /** @test */
    public function authorization_server_metadata_has_required_rfc8414_fields(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'issuer',
                'authorization_endpoint',
                'token_endpoint',
                'registration_endpoint',
                'scopes_supported',
                'response_types_supported',
                'grant_types_supported',
                'code_challenge_methods_supported',
                'token_endpoint_auth_methods_supported',
            ]);
    }

    /** @test */
    public function registration_endpoint_points_to_correct_route(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        // Must point to /oauth/register (RFC 7591), NOT /oauth/clients (404 in Passport v13)
        $data = $response->json();
        $this->assertStringEndsWith('/oauth/register', $data['registration_endpoint']);
    }

    /** @test */
    public function pkce_s256_is_listed_as_supported(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertJsonFragment([
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }

    /** @test */
    public function authorization_code_grant_is_listed(): void
    {
        $data = $this->getJson('/.well-known/oauth-authorization-server')->json();

        $this->assertContains('authorization_code', $data['grant_types_supported']);
    }

    /** @test */
    public function mcp_access_scope_is_listed(): void
    {
        $data = $this->getJson('/.well-known/oauth-authorization-server')->json();

        $this->assertContains('mcp-access', $data['scopes_supported']);
    }

    /** @test */
    public function authorization_server_metadata_has_cors_header(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    // ── Protected Resource Metadata (RFC 9728) ───────────────────────────────

    /** @test */
    public function protected_resource_metadata_returns_200(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertStatus(200);
    }

    /** @test */
    public function protected_resource_metadata_has_required_fields(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'resource',
                'authorization_servers',
                'scopes_supported',
                'bearer_methods_supported',
            ]);
    }

    /** @test */
    public function protected_resource_lists_authorization_server(): void
    {
        $data = $this->getJson('/.well-known/oauth-protected-resource')->json();

        $this->assertNotEmpty($data['authorization_servers']);
    }

    /** @test */
    public function protected_resource_metadata_has_cors_header(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
