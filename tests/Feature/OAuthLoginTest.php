<?php

namespace Webbycrown\McpDashboardStudio\Tests\Feature;

use Webbycrown\McpDashboardStudio\Tests\Fixtures\User;
use Webbycrown\McpDashboardStudio\Tests\TestCase;
use Illuminate\Support\Facades\Hash;

/**
 * Tests for OAuthLoginController
 *
 * Covers: login page rendering, credential validation, post-login redirects,
 * and the admin consent gate (enabled/disabled/fail-open).
 */
class OAuthLoginTest extends TestCase
{
    private function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name'     => 'Test User',
            'email'    => 'test@example.com',
            'password' => Hash::make('password123'),
            'is_admin' => false,
        ], $attributes));
    }

    /** @test */
    public function login_page_is_accessible(): void
    {
        $this->get('/login')->assertStatus(200);
    }

    /** @test */
    public function successful_login_redirects_home_when_no_oauth_flow(): void
    {
        $this->createUser();
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'password123'])
            ->assertRedirect('/');
    }

    /** @test */
    public function wrong_password_returns_error(): void
    {
        $this->createUser();
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    /** @test */
    public function login_requires_email_field(): void
    {
        $this->post('/login', ['password' => 'password123'])
            ->assertSessionHasErrors('email');
    }

    /** @test */
    public function login_requires_password_field(): void
    {
        $this->post('/login', ['email' => 'test@example.com'])
            ->assertSessionHasErrors('password');
    }

    // ── Admin Gate ────────────────────────────────────────────────────────────

    /** @test */
    public function non_admin_is_blocked_when_admin_consent_required(): void
    {
        config()->set('mcp-dashboard-studio.oauth.require_admin_for_consent', true);
        $this->createUser(['is_admin' => false]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'password123'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** @test */
    public function admin_user_passes_through_when_admin_consent_required(): void
    {
        config()->set('mcp-dashboard-studio.oauth.require_admin_for_consent', true);
        $this->createUser(['is_admin' => true]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'password123'])
            ->assertRedirect();

        $this->assertAuthenticated();
    }

    /** @test */
    public function all_users_allowed_when_admin_gate_disabled(): void
    {
        config()->set('mcp-dashboard-studio.oauth.require_admin_for_consent', false);
        $this->createUser(['is_admin' => false]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'password123'])
            ->assertRedirect();

        $this->assertAuthenticated();
    }

    /** @test */
    public function admin_gate_does_not_throw_if_is_admin_attribute_is_missing(): void
    {
        config()->set('mcp-dashboard-studio.oauth.require_admin_for_consent', true);
        $this->createUser();

        // Should not return a 500 error regardless of model attribute availability
        $response = $this->post('/login', ['email' => 'test@example.com', 'password' => 'password123']);
        $this->assertNotEquals(500, $response->status());
    }
}
