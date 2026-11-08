<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringType;
use App\Http\Requests\Monitoring\DnsRecordRules;
use App\Http\Requests\Monitoring\HttpCheckRules;
use App\Http\Requests\Monitoring\MonitoringInputNormalizer;
use App\Http\Requests\Monitoring\MonitoringTypeContext;
use App\Http\Requests\Monitoring\ServerHealthRules;
use App\Http\Requests\Monitoring\TargetRules;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Class MonitoringRequest
 *
 * Handles validation logic for creating or updating a monitoring configuration.
 * Composes the per-type rule sets in `App\Http\Requests\Monitoring` depending on the selected monitoring type.
 */
class MonitoringRequest extends FormRequest
{
    private ?MonitoringInputNormalizer $monitoringInputNormalizer = null;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool True if the user is authorized, false otherwise.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Applies conditional validation based on the selected monitoring type.
     *
     * @return array<string, ValidationRule|array|string> The validation rules.
     */
    public function rules(): array
    {
        $monitoringTypeContext = new MonitoringTypeContext($this);
        $httpCheckRules = new HttpCheckRules($monitoringTypeContext, $this->inputNormalizer());
        $targetRules = new TargetRules($monitoringTypeContext);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(MonitoringType::class)],
            'port' => ['nullable', 'required_if:type,port', 'integer', 'min:1', 'max:65535'],
            'keyword' => ['nullable', 'required_if:type,keyword', 'string', 'max:255'],
            ...new DnsRecordRules($monitoringTypeContext, $this->inputNormalizer())->rules(),
            'status' => ['required', Rule::enum(MonitoringLifecycleStatus::class)],
            'heartbeat_interval_minutes' => ['nullable', 'required_if:type,heartbeat', 'integer', 'min:1', 'max:10080'],
            'heartbeat_grace_minutes' => ['nullable', 'required_if:type,heartbeat', 'integer', 'min:0', 'max:1440'],
            ...$httpCheckRules->intervalRules(),
            ...new ServerHealthRules($monitoringTypeContext)->rules(),
            ...$httpCheckRules->requestRules(),
            'preferred_location' => ['required', 'string', Rule::exists('server_instances', 'code')->where('is_active', true)],
            'preferred_locations' => ['required', 'array', 'min:1'],
            'preferred_locations.*' => ['required', 'string', 'distinct', Rule::exists('server_instances', 'code')->where('is_active', true)],
            'public_label_enabled' => ['boolean'],
            'notification_on_failure' => ['boolean'],
            'notification_channels' => ['nullable', 'array'],
            'notification_channels.*' => [
                'string',
                Rule::in($this->notificationChannelUser()?->enabledNotificationChannelKeys() ?? []),
            ],
            'team_id' => [
                'nullable',
                'string',
                Rule::exists('teams', 'id'),
                function ($attribute, $value, $fail): void {
                    if (blank($value)) {
                        return;
                    }

                    $user = $this->user();

                    if (! $user || ! Team::query()->administeredBy($user)->whereKey((string) $value)->exists()) {
                        $fail(__('team.validation.not_admin'));
                    }
                },
            ],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => [
                'string',
                Rule::exists('monitoring_groups', 'id')->where('user_id', $this->user()?->id),
            ],
            'failure_confirmation_threshold' => ['required', 'integer', 'min:1', 'max:10'],
            ...$httpCheckRules->responseTimeRules(),
            'ssl_expiry_warning_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];

        if ($this->isMethod('post')) {
            $rules['target'] = $targetRules->rules();
        } elseif ($this->isMethod('patch') || $this->isMethod('put')) {
            $rules['target'] = ['sometimes', ...$targetRules->rules()];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_interval_minutes.in' => __('monitoring.validation.check_interval_invalid_value'),
        ];
    }

    protected function notificationChannelUser(): ?User
    {
        return $this->user();
    }

    /**
     * Prepare the data for validation.
     *
     * Ensures the 'type' field is consistently lowercase before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge($this->inputNormalizer()->normalize());
    }

    private function inputNormalizer(): MonitoringInputNormalizer
    {
        return $this->monitoringInputNormalizer ??= new MonitoringInputNormalizer($this);
    }
}
