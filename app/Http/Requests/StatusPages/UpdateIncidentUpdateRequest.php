<?php

declare(strict_types=1);

namespace App\Http\Requests\StatusPages;

use App\Enums\IncidentUpdateStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIncidentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(IncidentUpdateStatus::values())],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => mb_strtolower((string) $this->input('status')),
            'message' => mb_trim((string) $this->input('message')),
        ]);
    }
}
