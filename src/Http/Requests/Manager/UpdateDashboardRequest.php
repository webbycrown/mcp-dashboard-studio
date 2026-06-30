<?php

namespace Webbycrown\McpDashboardStudio\Http\Requests\Manager;

use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the PATCH /mcp-manager/dashboards/{uuid} payload.
 */
class UpdateDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by RequireManagerAccess middleware
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status'      => ['required', 'string', 'in:' . implode(',', [
                McpDashboardDefinition::STATUS_PUBLIC,
                McpDashboardDefinition::STATUS_PRIVATE,
            ])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'   => 'Dashboard name is required.',
            'name.max'        => 'Dashboard name may not exceed 191 characters.',
            'status.required' => 'Status is required.',
            'status.in'       => 'Status must be either "public" or "private".',
        ];
    }
}
