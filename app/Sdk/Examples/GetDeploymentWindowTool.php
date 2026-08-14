<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Examples;

use HaoCode\Sdk\SdkTool;

final class GetDeploymentWindowTool extends SdkTool
{
    public function name(): string
    {
        return 'GetDeploymentWindow';
    }

    public function description(): string
    {
        return 'Check whether a service is inside a safe deployment window.';
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
        $window = [
            'service' => $input['service'],
            'deploy_safe' => false,
            'reason' => 'active finance freeze for close-of-day reconciliation',
            'next_window' => '2026-04-09T02:00:00+08:00',
        ];

        return json_encode($window, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
