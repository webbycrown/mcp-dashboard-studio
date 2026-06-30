<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RFC 7591 — OAuth 2.0 Dynamic Client Registration
 *
 * Allows AI tools (ChatGPT, Cursor, Windsurf, etc.) to self-register
 * and receive a client_id without any manual setup.
 *
 * Security:
 *   - Redirect URI domain is validated against a configurable allowlist.
 *   - HTTPS is enforced on all redirect URIs in non-local environments.
 *   - Only 'authorization_code' grant is permitted.
 *   - Supports both public (PKCE-only) and confidential clients.
 *   - Rate limiting should be applied at the route level (throttle middleware).
 */
class DynamicClientRegistrationController extends Controller
{
    /**
     * POST /oauth/register  — RFC 7591 registration endpoint
     */
    public function register(Request $request): JsonResponse
    {
        // ── 1. Passport must be installed ────────────────────────────────────
        if (! class_exists(\Laravel\Passport\Passport::class)) {
            return $this->error(
                'server_error',
                'OAuth is not configured on this server. Install Laravel Passport.',
                503
            );
        }

        // ── 2. Parse & validate required fields ──────────────────────────────
        $redirectUris = $request->input('redirect_uris', []);

        if (empty($redirectUris) || ! is_array($redirectUris)) {
            return $this->error(
                'invalid_redirect_uri',
                'redirect_uris is required and must be a non-empty array.'
            );
        }

        // ── 3. Validate redirect URIs ─────────────────────────────────────────
        foreach ($redirectUris as $uri) {
            $validationError = $this->validateRedirectUri($uri);
            if ($validationError) {
                return $this->error('invalid_redirect_uri', $validationError);
            }
        }

        // ── 4. Determine client type ──────────────────────────────────────────
        // 'none' = public client (PKCE only, no secret) — used by Claude, ChatGPT
        // anything else = confidential client (with secret)
        $authMethod   = $request->input('token_endpoint_auth_method', 'client_secret_basic');
        $isPublic     = ($authMethod === 'none');
        $clientName   = $request->input('client_name', 'MCP AI Client');
        $grantTypes   = $request->input('grant_types', ['authorization_code']);

        // Only authorization_code grant is supported via dynamic registration
        if (! in_array('authorization_code', $grantTypes)) {
            return $this->error(
                'invalid_client_metadata',
                'Only authorization_code grant type is supported.'
            );
        }

        // ── 5. Create the Passport client in the DB ───────────────────────────
        try {
            $clientId     = (string) Str::orderedUuid();
            $clientSecret = $isPublic ? null : Str::random(40);
            $hashedSecret = $clientSecret ? bcrypt($clientSecret) : null;

            DB::table('oauth_clients')->insert([
                'id'            => $clientId,
                'name'          => substr($clientName, 0, 191),
                'secret'        => $hashedSecret,
                'redirect_uris' => json_encode($redirectUris),
                'grant_types'   => json_encode(['authorization_code', 'refresh_token']),
                'revoked'       => false,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            Log::info('[MCP] Dynamic client registered', [
                'client_id'   => $clientId,
                'client_name' => $clientName,
                'is_public'   => $isPublic,
                'redirects'   => $redirectUris,
            ]);

        } catch (\Throwable $e) {
            Log::error('[MCP] Dynamic client registration failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->error('server_error', 'Failed to create client. Please try again.', 500);
        }

        // ── 6. RFC 7591 compliant response ────────────────────────────────────
        $base     = rtrim(config('app.url'), '/');
        $response = [
            'client_id'                => $clientId,
            'client_name'              => $clientName,
            'redirect_uris'            => $redirectUris,
            'grant_types'              => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => $isPublic ? 'none' : 'client_secret_post',
            'registration_client_uri'  => $base . '/oauth/register/' . $clientId,
            'client_id_issued_at'      => now()->timestamp,
        ];

        // Only include secret for confidential clients
        if (! $isPublic && $clientSecret) {
            $response['client_secret']            = $clientSecret;
            $response['client_secret_expires_at'] = 0; // never expires
        }

        return response()->json($response, 201)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * OPTIONS /oauth/register — CORS preflight for browser-based MCP clients
     */
    public function options(): JsonResponse
    {
        return response()->json([], 204)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Validation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate a redirect URI against security rules.
     *
     * Returns an error string if invalid, null if valid.
     */
    private function validateRedirectUri(string $uri): ?string
    {
        if (! filter_var($uri, FILTER_VALIDATE_URL)) {
            return "Invalid redirect URI: {$uri}";
        }

        $parsed = parse_url($uri);
        $scheme = $parsed['scheme'] ?? '';
        $host   = $parsed['host'] ?? '';

        // Enforce HTTPS in non-local environments
        if (! app()->isLocal() && $scheme !== 'https') {
            return "Redirect URI must use HTTPS in production: {$uri}";
        }

        // Check against allowlist if configured
        $allowedDomains = config('mcp-dashboard-studio.oauth.allowed_redirect_domains', []);

        if (! empty($allowedDomains)) {
            $domainAllowed = false;
            foreach ($allowedDomains as $allowedDomain) {
                // Support wildcard subdomains: *.claude.ai
                if (str_starts_with($allowedDomain, '*.')) {
                    $baseDomain = substr($allowedDomain, 2);
                    if ($host === $baseDomain || str_ends_with($host, '.' . $baseDomain)) {
                        $domainAllowed = true;
                        break;
                    }
                } elseif ($host === $allowedDomain) {
                    $domainAllowed = true;
                    break;
                }
            }

            if (! $domainAllowed) {
                Log::warning('[MCP] Dynamic registration blocked: domain not in allowlist', [
                    'host'    => $host,
                    'allowed' => $allowedDomains,
                ]);
                return "Redirect URI domain '{$host}' is not in the allowed domains list.";
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Response Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function error(string $code, string $description, int $status = 400): JsonResponse
    {
        return response()->json([
            'error'             => $code,
            'error_description' => $description,
        ], $status)->header('Cache-Control', 'no-store');
    }
}
