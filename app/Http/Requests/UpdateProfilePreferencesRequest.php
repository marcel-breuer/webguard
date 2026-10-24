<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SupportedFormatLocale;
use App\Enums\SupportedLanguage;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfilePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'format_locale' => ['present', 'nullable', 'string', Rule::in(SupportedFormatLocale::values())],
        ];
    }
}
