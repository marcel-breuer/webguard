<?php

declare(strict_types=1);

namespace App\Http\Requests\StatusPages;

use Illuminate\Foundation\Http\FormRequest;

class StoreStatusPageAnnouncementRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
            'notify_subscribers' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => mb_trim((string) $this->input('title')),
            'message' => mb_trim((string) $this->input('message')),
        ]);
    }
}
