<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Examples;

use HaoCode\Sdk\SdkTool;

final class GetServiceHealthTool extends SdkTool
{
    public function name(): string
    {
        return 'GetServiceHealth';
    }

    public function description(): string
    {
        return 'Fetch recent service health metrics for an API or worker.';
    }

    public function parameters(): array
    {
        return [
            'service' => [
                'type' => 'string',
                'description' => 'Service name to inspect',
                'required' => true,
            ],
        ];
    }

    public function handle(array $input): string
    {
        $health = [
            'service' => $input['service'],
            'error_rate' => '7.8%',
            'queue_lag_seconds' => 142,
            'refund_backlog' => 34,
            'suspected_cause' => 'duplicate retries caused by retry-worker config drift',
        ];

        return json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
