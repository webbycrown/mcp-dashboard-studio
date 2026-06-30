<?php

namespace Webbycrown\McpDashboardStudio\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:8', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'prompt.required' => 'The prompt is required.',
            'prompt.string' => 'The prompt must be a string.',
            'prompt.min' => 'The prompt must be at least 8 characters.',
            'prompt.max' => 'The prompt may not be greater than 1000 characters.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $response = response()->json([
            'success' => false,
            'status' => 422,
            'message' => 'Validation failed',
            'errors' => $validator->errors()->messages(),
        ], 422);

        throw new HttpResponseException($response);
    }
}
