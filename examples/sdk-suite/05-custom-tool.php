#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\SdkTool;

class GetStockPriceTool extends SdkTool {
    public function name(): string { return 'GetStockPrice'; }
    public function description(): string { return 'Return a fake stock price for a ticker.'; }
    public function parameters(): array {
        return ['ticker' => ['type' => 'string', 'description' => 'Stock ticker', 'required' => true]];
    }
    public function handle(array $input): string {
        $prices = ['AAPL' => 189.50, 'GOOG' => 178.20, 'MSFT' => 415.00];
        return json_encode(['ticker' => strtoupper($input['ticker']), 'price' => $prices[strtoupper($input['ticker'])] ?? 100.00]);
    }
}

echo HaoCode::query('What is the price of AAPL?', new HaoCodeConfig(
    apiKey: getenv('ANTHROPIC_API_KEY'), maxTurns: 3,
    allowedTools: ['GetStockPrice'], tools: [new GetStockPriceTool],
))."\n";
