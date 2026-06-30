<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webbycrown\McpDashboardStudio\Support\RoutePaths;
use Illuminate\Support\Facades\Log;

/**
 * OAuth 2.0 Discovery Endpoints
 *
 * RFC 8414 — Authorization Server Metadata
 * RFC 9728 — Protected Resource Metadata
 *
 * Claude and all MCP-compliant AI clients hit these before initiating
 * the OAuth handshake to discover endpoint URLs and supported features.
 *
 * If Laravel Passport is NOT installed, both endpoints return HTTP 503
 * with a clear JSON error — no silent 404 or misleading metadata.
 */
class OAuthDiscoveryController extends Controller
{
    /**
     * GET /.well-known/oauth-authorization-server  (RFC 8414)
     */
    public function authorizationServer(Request $request): JsonResponse
    {
        if (! $this->passportAvailable()) {
            return $this->passportMissingResponse('oauth-authorization-server');
        }

        $base = rtrim(config('app.url'), '/');

        return response()->json([
            'issuer'                                => $base,
            'authorization_endpoint'                => $base . '/oauth/authorize',
            'token_endpoint'                        => $base . '/oauth/token',
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'revocation_endpoint'                   => $base . '/oauth/tokens/revoke',
            'response_types_supported'              => ['code'],
            'response_modes_supported'              => ['query'],
            'grant_types_supported'                 => ['authorization_code', 'client_credentials', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'scopes_supported'                      => array_keys(
                config('mcp-dashboard-studio.oauth.scopes', ['mcp-access' => 'Access MCP Dashboard API'])
            ),
            'registration_endpoint' => $base . '/oauth/register',
        ])->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * GET /.well-known/oauth-protected-resource  (RFC 9728)
     *
     * Always returns resource metadata regardless of Passport status,
     * because this describes the MCP resource, not the auth server.
     */
    public function protectedResource(Request $request): JsonResponse
    {
        $response = [
            'resource'                 => RoutePaths::mcpUrl(),
            'bearer_methods_supported' => ['header'],
            'scopes_supported'         => array_keys(
                config('mcp-dashboard-studio.oauth.scopes', ['mcp-access' => 'Access MCP Dashboard API'])
            ),
        ];

        // Only advertise authorization servers if Passport is available
        if ($this->passportAvailable()) {
            $response['authorization_servers'] = [$base];
        } else {
            $response['authorization_servers'] = [];
            $response['_note'] = 'OAuth not available. Use static MCP_SECRET_TOKEN.';
        }

        return response()->json($response)
            ->header('Access-Control-Allow-Origin', '*');
    }

    // ──────────────────────────────────────────────────────────────────────

    private function passportAvailable(): bool
    {
        return class_exists(\Laravel\Passport\Passport::class);
    }

    /**
     * Return a clear 503 response when Passport is not installed,
     * instead of 404 or misleading metadata.
     */
    private function passportMissingResponse(string $endpoint): JsonResponse
    {
        Log::warning('[MCP] OAuth discovery endpoint hit but Laravel Passport is not installed.', [
            'endpoint'   => $endpoint,
            'suggestion' => 'Run: composer require laravel/passport && php artisan migrate && php artisan passport:install --uuids',
        ]);

        return response()->json([
            'error'             => 'oauth_not_available',
            'error_description' => 'OAuth 2.1 is not configured on this server. '
                . 'Laravel Passport is not installed. '
                . 'Use the static MCP_SECRET_TOKEN for authentication instead.',
            'documentation'     => 'https://github.com/webbycrown/mcp-dashboard-studio#installation',
        ], 503)->header('Access-Control-Allow-Origin', '*');
    }
}
