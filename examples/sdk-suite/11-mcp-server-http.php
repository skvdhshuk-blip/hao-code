#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';

$port  = 15999;
$token = 'demo-'.bin2hex(random_bytes(4));
$bin   = $packageRoot.'/bin/hao-code';

$proc = proc_open("php {$bin} mcp-serve --mode=http --port={$port} --token={$token} --preset=strict",
    [], $pipes, null, ['ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY')]);
if (!is_resource($proc)) { fwrite(STDERR, "[error] could not start MCP HTTP server\n"); exit(1); }
sleep(2);

// Without token → 401
$r1 = http_call("http://127.0.0.1:{$port}/", null, ['Content-Type: application/json'],
    json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>[]]));
echo "Without token → ".$r1['status']."\n";

// With token → 200 + initialize response
$r2 = http_call("http://127.0.0.1:{$port}/", $token, ['Content-Type: application/json'],
    json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize',
        'params'=>['protocolVersion'=>'2024-11-05','clientInfo'=>['name'=>'demo','version'=>'0.1'],'capabilities'=>[]]]));
echo "With token    → ".$r2['status']."\n";
$data = json_decode($r2['body'], true);
echo "serverInfo:    ".json_encode($data['result']['serverInfo'] ?? 'n/a')."\n";

proc_terminate($proc); proc_close($proc);

function http_call(string $url, ?string $token, array $headers, string $body): array {
    if ($token) $headers[] = "Authorization: Bearer {$token}";
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true]]);
    $resp = @file_get_contents($url, false, $ctx);
    return ['status' => $http_response_header[0] ?? 'no response', 'body' => $resp ?: ''];
}
