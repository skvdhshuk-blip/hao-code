#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

use HaoCode\Sdk\Sandbox\AgentRun\AgentRunClient;

$client = AgentRunClient::fromOptions([
    'accountId' => getenv('AGENTRUN_ACCOUNT_ID') ?: getenv('ALIBABA_CLOUD_ACCOUNT_ID') ?: '',
    'sandboxId' => getenv('AGENTRUN_SANDBOX_ID') ?: null,
    'templateName' => getenv('AGENTRUN_TEMPLATE_NAME') ?: null,
    'apiKey' => getenv('AGENTRUN_API_KEY') ?: null,
    'region' => getenv('AGENTRUN_REGION') ?: getenv('ALIBABA_CLOUD_REGION_ID') ?: 'cn-hangzhou',
    'endpoint' => getenv('AGENTRUN_DATA_ENDPOINT') ?: null,
    'timeoutSeconds' => (int) (getenv('AGENTRUN_TIMEOUT_SECONDS') ?: 60),
]);

try {
    $sandboxId = $client->sandboxId();
    echo "Sandbox: {$sandboxId}\n";

    try {
        $client->health();
        echo "Health: ok\n";
    } catch (Throwable $e) {
        echo "Health: skipped ({$e->getMessage()})\n";
    }

    $path = '/tmp/haocode-agentrun-verify-'.bin2hex(random_bytes(4)).'.txt';
    $client->writeFile($path, "hello from hao-code\n");
    $content = $client->readFile($path);
    echo 'Write/read: '.(str_contains($content, 'hello from hao-code') ? 'ok' : 'failed')."\n";

    $exec = $client->executeCode(<<<'PY'
import math
import datetime
radius = 5
area = math.pi * radius ** 2
print(f"111 半径为 {radius} 的圆面积: {area:.2f}")
print(f"当前时间: {datetime.datetime.now()}")
PY, timeoutSeconds: 30);
    $stdout = $exec['stdout'] ?? $exec['data']['stdout'] ?? $exec['result']['stdout'] ?? json_encode($exec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo "Execute stdout:\n{$stdout}\n";

    try {
        $client->remove($path);
    } catch (Throwable) {
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
