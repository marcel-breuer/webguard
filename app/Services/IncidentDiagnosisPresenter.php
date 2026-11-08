<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IncidentDiagnosisCategory;
use App\Enums\IncidentDiagnosisConfidence;
use App\Models\Incident;
use App\Models\Monitoring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Turns a stored diagnosis snapshot into the localized API representation
 * shared by the UI, the external API, and the MCP tools.
 */
final class IncidentDiagnosisPresenter
{
    /**
     * @return array{id: string, monitoring_id: string, monitoring_name: string|null, status: string, down_at: string, up_at: string|null}
     */
    public function incident(Incident $incident): array
    {
        return [
            'id' => (string) $incident->getKey(),
            'monitoring_id' => (string) $incident->monitoring_id,
            'monitoring_name' => $this->loadedMonitoring($incident)?->name,
            'status' => $incident->up_at === null ? 'open' : 'resolved',
            'down_at' => Date::parse($incident->down_at)->toIso8601String(),
            'up_at' => $incident->up_at !== null ? Date::parse($incident->up_at)->toIso8601String() : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    public function present(?array $snapshot, bool $persisted = true): ?array
    {
        if (! is_array($snapshot) || ! is_array($snapshot['hypotheses'] ?? null)) {
            return null;
        }

        $hypotheses = array_values(array_filter(array_map(
            fn (mixed $hypothesis): ?array => is_array($hypothesis) ? $this->hypothesis($hypothesis) : null,
            $snapshot['hypotheses'],
        )));

        return [
            'version' => (int) ($snapshot['version'] ?? IncidentDiagnosisService::SNAPSHOT_VERSION),
            'generated_at' => $snapshot['generated_at'] ?? null,
            'reference_at' => $snapshot['reference_at'] ?? null,
            'trigger' => $snapshot['trigger'] ?? null,
            'persisted' => $persisted,
            'primary' => $hypotheses[0] ?? null,
            'hypotheses' => $hypotheses,
            'signals' => is_array($snapshot['signals'] ?? null) ? $snapshot['signals'] : null,
        ];
    }

    /**
     * Compact form used in incident lists.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array{category: string, label: string, confidence: int, confidence_level: string, summary: string}|null
     */
    public function primary(?array $snapshot): ?array
    {
        $primary = $this->present($snapshot)['primary'] ?? null;

        if (! is_array($primary)) {
            return null;
        }

        return [
            'category' => $primary['category'],
            'label' => $primary['label'],
            'confidence' => $primary['confidence'],
            'confidence_level' => $primary['confidence_level'],
            'summary' => $primary['summary'],
        ];
    }

    /**
     * @param  array<mixed>  $hypothesis
     * @return array{rank: int, category: string, label: string, confidence: int, confidence_level: string, confidence_label: string, summary: string, evidence: list<array{type: string, message: string, data: array<string, mixed>}>}|null
     */
    private function hypothesis(array $hypothesis): ?array
    {
        $category = IncidentDiagnosisCategory::tryFrom((string) ($hypothesis['category'] ?? ''));

        if ($category === null) {
            return null;
        }

        $confidence = (int) ($hypothesis['confidence'] ?? 0);
        $incidentDiagnosisConfidence = IncidentDiagnosisConfidence::fromScore($confidence);
        $summary = is_array($hypothesis['summary'] ?? null) ? $hypothesis['summary'] : [];
        $evidence = is_array($hypothesis['evidence'] ?? null) ? $hypothesis['evidence'] : [];

        return [
            'rank' => (int) ($hypothesis['rank'] ?? 0),
            'category' => $category->value,
            'label' => $category->label(),
            'confidence' => $confidence,
            'confidence_level' => $incidentDiagnosisConfidence->value,
            'confidence_label' => $incidentDiagnosisConfidence->label(),
            'summary' => $this->message('summaries', $summary),
            'evidence' => array_values(array_filter(array_map(
                fn (mixed $item): ?array => is_array($item) ? [
                    'type' => (string) ($item['type'] ?? ''),
                    'message' => $this->message('evidence', $item),
                    'data' => is_array($item['params'] ?? null) ? $item['params'] : [],
                ] : null,
                $evidence,
            ))),
        ];
    }

    /**
     * @param  array<mixed>  $definition
     */
    private function message(string $group, array $definition): string
    {
        $key = 'incidents.diagnosis.' . $group . '.' . ($definition['key'] ?? 'unknown');
        $params = is_array($definition['params'] ?? null) ? $definition['params'] : [];
        $replacements = [];

        foreach ($params as $name => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $replacements[(string) $name] = str_ends_with((string) $name, '_at')
                ? $this->dateTime((string) $value)
                : (string) $value;
        }

        $message = __($key, $replacements);

        return is_string($message) && $message !== $key ? $message : '';
    }

    private function dateTime(string $value): string
    {
        if ($value === '') {
            return '—';
        }

        try {
            $carbon = new Carbon($value);
            $carbon->locale(App::getLocale());

            return $carbon->isoFormat('lll');
        } catch (Throwable) {
            return $value;
        }
    }

    private function loadedMonitoring(Incident $incident): ?Monitoring
    {
        $monitoring = $incident->relationLoaded('monitoring') ? $incident->getRelation('monitoring') : null;

        return $monitoring instanceof Monitoring ? $monitoring : null;
    }
}
