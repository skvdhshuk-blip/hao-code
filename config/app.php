<?php

return [
    'name' => 'Hao Code',
    'version' => '0.1.4',
    'env' => env('APP_ENV', 'production'),

    'providers' => [
        HaoCode\Providers\AgentServiceProvider::class,
        HaoCode\Providers\ToolServiceProvider::class,
        HaoCode\Sdk\HaoCodeSdkServiceProvider::class,
    ],
];
