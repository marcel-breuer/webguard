<?php

declare(strict_types=1);

namespace App\Http\Requests\InternalUi;

use Illuminate\Foundation\Http\FormRequest;

class MonitoringSearchRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['search' => ['required', 'string', 'min:2', 'max:120']];
    }

    public function search(): string
    {
        return (string) $this->validated('search');
    }
}
