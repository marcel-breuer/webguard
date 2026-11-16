<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;

/**
 * Reusable instruction templates that guide a model through multi-step WebGuard workflows.
 *
 * Prompts only contain instructions. They hold no customer data and never perform an
 * action themselves; write tools stay behind their own ability and the model's
 * confirmation behaviour.
 */
final readonly class McpPromptHandler implements PromptHandlerInterface
{
    public function __construct(private string $prompt) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<PromptMessage>
     */
    public function get(array $arguments, ClientGateway $gateway): array
    {
        $text = match ($this->prompt) {
            'investigate_incident' => sprintf(
                'Investigate WebGuard incident %s. Call diagnose_incident for the ranked probable causes, then get_monitoring and get_monitoring_analytics for the affected monitoring over the last 7 days. Summarise: what is affected, since when, the most likely cause with its evidence, and recommended next steps. Do not change anything.',
                $this->argument($arguments, 'incident_id'),
            ),
            'monitoring_health_review' => sprintf(
                'Review the health of my WebGuard monitorings over the last %s days. Call list_monitorings, then get_monitoring_analytics for the ones that are not healthy or have recent incidents. Report uptime, response-time trends and open incidents, ordered by importance, and propose concrete follow-ups. Do not change anything.',
                $this->argument($arguments, 'days', '30'),
            ),
            'draft_status_page_update' => sprintf(
                'Draft a customer-facing status update for incident %s on status page %s. Read the incident with get_status_page_incident, write a short neutral message that contains no internal details, hostnames, credentials or personal data, and show me the draft with the status (investigating, identified, monitoring or resolved) you propose. Only call post_incident_update after I explicitly confirm the text.',
                $this->argument($arguments, 'incident_id'),
                $this->argument($arguments, 'status_page_id'),
            ),
            default => '',
        };

        return [new PromptMessage(Role::User, new TextContent($text))];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function argument(array $arguments, string $name, string $default = '(not provided)'): string
    {
        $value = $arguments[$name] ?? null;

        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 100) : $default;
    }
}
