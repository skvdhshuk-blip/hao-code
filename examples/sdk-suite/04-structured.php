#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$result = HaoCode::structured(
    'Classify: "My order #1234 hasn\'t arrived after 2 weeks."',
    ['type' => 'object',
     'properties' => [
         'category' => ['type' => 'string', 'enum' => ['billing', 'shipping', 'technical', 'other']],
         'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
         'summary'  => ['type' => 'string'],
     ],
     'required' => ['category', 'priority', 'summary']],
    new HaoCodeConfig(apiKey: getenv('ANTHROPIC_API_KEY'), maxTurns: 1, allowedTools: [])
);
echo "category: {$result->category}\npriority: {$result->priority}\nsummary:  {$result->summary}\n";
