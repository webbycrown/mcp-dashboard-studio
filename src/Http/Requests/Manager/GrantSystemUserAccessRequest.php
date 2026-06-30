<?php

namespace Webbycrown\McpDashboardStudio\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates granting a system user access to a private dashboard.
 */
class GrantSystemUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'A user ID is required.',
            'user_id.integer'  => 'User ID must be a valid integer.',
            'user_id.min'      => 'User ID must be a positive integer.',
        ];
    }
}
