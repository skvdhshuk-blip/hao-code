<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Examples;

use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\SdkSkill;
use HaoCode\Sdk\SdkTool;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Tools\ToolResult;

trait GetEscalationTicketToolNameConcern
{
    public function name(): string
    {
        return 'GetEscalationTicket';
    }

    public function description(): string
    {
        return 'Fetch the current incident ticket, impact summary, and owner.';
    }

    public function parameters(): array
    {
        return [
            'ticket_id' => [
                'type' => 'string',
                'description' => 'Incident or escalation ticket identifier',
                'required' => true,
            ],
        ];
    }

    public function handle(array $input): string
    {
        $ticket = [
            'ticket_id' => $input['ticket_id'],
            'severity' => 'sev2',
            'service' => 'payments-api',
            'summary' => 'Customers are seeing duplicate charges after the retry worker rollout.',
            'customer_impact' => '34 confirmed duplicate captures in the last 18 minutes.',
            'owner' => 'payments-oncall',
            'recent_change' => 'retry-worker config deploy 2026.04.07-rc3',
        ];

        return json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
