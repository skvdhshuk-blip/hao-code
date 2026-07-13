<?php

require __DIR__.'/_bootstrap.php';

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterruptException;

$apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "[skip] ANTHROPIC_API_KEY is required.\n");
    exit(0);
}

$config = new HaoCodeConfig(
    apiKey: $apiKey,
    cwd: sys_get_temp_dir(),
    allowedTools: ['Write'],
    interruptOn: ['Write' => true],
);

try {
    HaoCode::query('Write a short greeting to haocode-hitl-demo.txt.', $config);
} catch (HumanInterruptException $e) {
    $action = $e->interrupt->actions[0];
    echo json_encode($e->interrupt->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

    $result = HaoCode::resumeInterrupt(
        $e->interrupt->sessionId,
        $e->interrupt->id,
        [HumanDecision::approve($action->id)],
        $config,
    );
    echo $result->text."\n";
}
