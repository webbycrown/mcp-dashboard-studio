<?php

namespace Webbycrown\McpDashboardStudio\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates inviting an external (custom) user to access a private dashboard.
 */
class GrantCustomUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:191'],
            'email'    => ['required', 'email', 'max:191'],
            'password' => ['required', 'string', 'min:6', 'max:191'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'Invited user name is required.',
            'name.max'          => 'Name may not exceed 191 characters.',
            'email.required'    => 'Email address is required.',
            'email.email'       => 'Please provide a valid email address.',
            'email.max'         => 'Email may not exceed 191 characters.',
            'password.required' => 'A password is required for the invite.',
            'password.min'      => 'Password must be at least 6 characters.',
        ];
    }
}
