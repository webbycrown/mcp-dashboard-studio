<?php

namespace Webbycrown\McpDashboardStudio\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP Auth Middleware — OAuth 2.1 + Static Token
 *
 * Accepts EITHER:
 *   (a) A Passport Bearer token (OAuth 2.1 + PKCE — Claude, ChatGPT, any MCP client)
 *         - JWT format   → decodes payload → extracts 'jti' → looks up in oauth_access_tokens
 *         - Opaque format → looks up directly by id in oauth_access_tokens
 *   (b) The static MCP_SECRET_TOKEN (Postman / simple API clients)
 *
 * On failure: returns proper JSON-RPC 2.0 error + HTTP 401.
 * On internal error: logs to Laravel log and returns 401 (never crashes the app).
 */
class VerifyMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // ── Strategy A: Passport OAuth Bearer token ────────────────────────
        if ($this->validatePassportToken($request)) {
            return $next($request);
        }

        // ── Strategy B: Static MCP secret token ───────────────────────────
        if ($this->validateStaticToken($request)) {
            return $next($request);
        }

        Log::debug('[MCP] Unauthorized request', [
            'ip'     => $request->ip(),
            'path'   => $request->path(),
            'method' => $request->method(),
        ]);

        return $this->unauthorizedResponse();
    }

    // ──────────────────────────────────────────────────────────────────────
    //  OAuth Passport Token Validation
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Validate a Passport Bearer token against the oauth_access_tokens table.
     *
     * Supports:
     *   - JWT tokens  (eyJ...) → extracts jti claim from payload
     *   - Opaque tokens (hex)  → uses the string directly as the DB primary key
     */
    private function validatePassportToken(Request $request): bool
    {
        // Passport integration requires the Token model to exist
        if (! class_exists(\Laravel\Passport\Token::class)) {
            return false;
        }

        $bearer = $this->bearerToken($request);
        if (! $bearer) {
            return false;
        }

        try {
            $tokenId = $this->extractTokenId($bearer);

            if (! $tokenId) {
                Log::debug('[MCP] Could not extract token id from bearer');
                return false;
            }

            /** @var \Laravel\Passport\Token|null $dbToken */
            $dbToken = \Laravel\Passport\Token::find($tokenId);

            if (! $dbToken) {
                Log::debug('[MCP] Token not found in DB', ['prefix' => substr($tokenId, 0, 16)]);
                return false;
            }

            if ($dbToken->revoked) {
                Log::debug('[MCP] Token is revoked');
                return false;
            }

            if ($dbToken->expires_at && now()->gt($dbToken->expires_at)) {
                Log::debug('[MCP] Token is expired', ['expired_at' => $dbToken->expires_at]);
                return false;
            }

            Log::debug('[MCP] OAuth token accepted', ['user_id' => $dbToken->user_id]);

            // Make the authenticated user available downstream
            $userModel = config('auth.providers.users.model', \App\Models\User::class);
            $user = $userModel::find($dbToken->user_id);

            if ($user) {
                Auth::setUser($user);
            }

            return true;

        } catch (\Throwable $e) {
            Log::error('[MCP] OAuth token validation error', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * Extract the Passport token ID from the bearer string.
     *
     * JWT (3 dot-separated segments): base64url-decode the payload → read 'jti' claim.
     * Opaque (no dots): use the string directly as the oauth_access_tokens primary key.
     */
    private function extractTokenId(string $bearer): ?string
    {
        $parts = explode('.', $bearer);

        if (count($parts) === 3) {
            // JWT — decode middle (payload) segment
            $json = base64_decode(
                str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)
            );
            $payload = json_decode($json, true);
            return $payload['jti'] ?? null;
        }

        // Opaque token — use as-is
        return $bearer ?: null;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Static Secret Token Validation
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Validate the static MCP_SECRET_TOKEN from config.
     * Supports: Authorization: Bearer, X-MCP-TOKEN header, mcp-token header, ?token= param.
     */
    private function validateStaticToken(Request $request): bool
    {
        $expected = config('mcp-dashboard-studio.mcp_secret_token');

        if (! $expected) {
            return false;
        }

        $provided = $this->bearerToken($request)
            ?? $request->header('X-MCP-TOKEN')
            ?? $request->header('mcp-token')
            ?? $request->input('token');

        if (! $provided) {
            return false;
        }

        $valid = hash_equals($expected, $provided);

        if ($valid) {
            Log::debug('[MCP] Static token accepted');
        }

        return $valid;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    private function unauthorizedResponse(): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'error'   => [
                'code'    => -32001,
                'message' => 'Unauthorized: Valid Bearer token required.',
            ],
            'id'      => null,
        ], Response::HTTP_UNAUTHORIZED)->withHeaders([
            'WWW-Authenticate' => 'Bearer realm="MCP Dashboard Studio", scope="mcp-access"',
        ]);
    }
}
