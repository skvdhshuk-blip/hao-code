<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\HumanInterruptCoordinator;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Settings\SettingsManager;

trait HaoCodeRunStructuredStateMachineConcern
{

    /**
     * Shared parse → validate → correct-JSON retry loop for structured output.
     *
     * @param  list<string|array<string, mixed>>  $initialImages
     * @internal
     */
    private static function runStructuredStateMachine(
        Conversation $conversation,
        array $schema,
        int $maxRetries,
        ?string $initialPrompt = null,
        array $initialImages = [],
        ?QueryResult $seedResult = null,
    ): StructuredResult {
        $attempt = 0;
        $lastValidationErrors = [];
        $lastRawText = '';
        $queryResult = $seedResult;

        while (true) {
            if ($queryResult === null) {
                if ($attempt === 0) {
                    if ($initialPrompt === null || $initialPrompt === '') {
                        throw new \InvalidArgumentException('Structured state machine requires an initial prompt or seed result.');
                    }
                    $promptForAttempt = $initialPrompt;
                    $images = $initialImages;
                } else {
                    $promptForAttempt = self::buildStructuredCorrectionPrompt($schema, $lastValidationErrors);
                    $images = [];
                }
                // Initial turn may use tools; correction turns must not re-execute side effects.
                if ($attempt === 0) {
                    $queryResult = $conversation->send($promptForAttempt, $images);
                } else {
                    $queryResult = $conversation->sendWithoutTools($promptForAttempt);
                }
            }

            $lastRawText = $queryResult->text;
            $parsedOrErrors = self::tryParseStructuredResult($queryResult);
            if (is_array($parsedOrErrors)) {
                $lastValidationErrors = $parsedOrErrors;
                if ($attempt >= $maxRetries) {
                    throw new StructuredResultValidationException(
                        'Structured response failed JSON parsing after '.($attempt + 1).
                        ' attempt(s). Issues: '.implode('; ', $lastValidationErrors),
                        $lastRawText,
                        $lastValidationErrors,
                    );
                }
                $attempt++;
                $queryResult = null;
                continue;
            }

            $errors = self::validateAgainstSchema($parsedOrErrors->toArray(), $schema);
            if ($errors === []) {
                return $parsedOrErrors;
            }

            $lastValidationErrors = $errors;
            if ($attempt >= $maxRetries) {
                throw new StructuredResultValidationException(
                    'Structured response failed schema validation after '.($attempt + 1).
                    ' attempt(s). Violations: '.implode('; ', $errors),
                    $lastRawText,
                    $errors,
                );
            }
            $attempt++;
            $queryResult = null;
        }
    }

    /**
     * @return non-empty-string
     */
    private static function structuredJsonRootLabel(array $schema): string
    {
        return match ($schema['type'] ?? null) {
            'array' => 'JSON array',
            'object' => 'JSON object',
            default => 'JSON value',
        };
    }

    private static function buildStructuredBasePrompt(string $prompt, array $schema): string
    {
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $root = self::structuredJsonRootLabel($schema);

        return $prompt."\n\n".
            "IMPORTANT: You MUST respond with ONLY a valid {$root} matching this schema. ".
            "No markdown fences, no explanation, no extra text — just the raw JSON.\n\n".
            "Schema:\n".$schemaJson;
    }

    /**
     * @param  list<string>  $errors
     */
    private static function buildStructuredCorrectionPrompt(array $schema, array $errors): string
    {
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $root = self::structuredJsonRootLabel($schema);

        return "Your previous response was not acceptable. ".
            "Do not call tools again unless strictly required to fill a missing field — ".
            "previous tools may already have executed; do not repeat completed side effects.\n".
            "Reply with ONLY the corrected {$root} matching this schema ".
            "(no markdown fences, no explanation):\n".
            implode("\n", $errors).
            "\n\nSchema:\n".$schemaJson;
    }

    /**
     * @return StructuredResult|list<string>
     */
    private static function tryParseStructuredResult(QueryResult $queryResult): StructuredResult|array
    {
        $text = trim($queryResult->text);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text) ?? $text;
            $text = preg_replace('/\n?```\s*$/', '', $text) ?? $text;
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [
                'JSON syntax error: '.$e->getMessage()
                .' (raw: '.mb_substr($text, 0, 200).')',
            ];
        }

        if (! is_array($decoded)) {
            return [
                'JSON root must be an object or array, got '.get_debug_type($decoded)
                .' (raw: '.mb_substr($text, 0, 200).')',
            ];
        }

        return new StructuredResult($decoded, $queryResult->text, $queryResult);
    }

    /**
     * Parse JSON or throw (used by interrupt resume paths that already re-entered).
     */
    private static function parseStructuredResult(QueryResult $queryResult): StructuredResult
    {
        $parsed = self::tryParseStructuredResult($queryResult);
        if (is_array($parsed)) {
            throw new StructuredResultValidationException(
                'Failed to parse structured response as JSON. '.implode('; ', $parsed),
                $queryResult->text,
                $parsed,
            );
        }

        return $parsed;
    }

    /**
     * Compile the schema once so broken caller schemas fail before model spend.
     *
     * @return list<string>
     */
    private static function validateSchemaIsUsable(array $schema): array
    {
        $rootType = $schema['type'] ?? null;
        if (is_string($rootType) && ! in_array($rootType, ['object', 'array'], true)) {
            return [
                'StructuredResult only supports object or array JSON roots; '
                ."got type '{$rootType}'. Rejecting before model spend.",
            ];
        }
        if (is_array($rootType)) {
            $allowed = array_values(array_intersect($rootType, ['object', 'array']));
            if ($allowed === []) {
                return [
                    'StructuredResult only supports object or array JSON roots; '
                    .'schema type union has neither.',
                ];
            }
        }

        try {
            $schemaObj = json_decode((string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            \Swaggest\JsonSchema\Schema::import(
                $schemaObj,
                self::safeJsonSchemaContext(),
            );

            return [];
        } catch (\Throwable $e) {
            return ['Schema validation setup failed: '.$e->getMessage()];
        }
    }

    /**
     * Validate the decoded structured response against the JSON Schema.
     *
     * Returns a list of human-readable error strings (empty when valid). Each
     * error includes the JSON-pointer path produced by the validator so the
     * retry prompt can point the model at the offending field.
     *
     * @return list<string>
     */
    private static function validateAgainstSchema(array $data, array $schema): array
    {
        try {
            $schemaObj = json_decode((string) json_encode($schema, JSON_UNESCAPED_SLASHES));
            $dataObj = json_decode((string) json_encode($data, JSON_UNESCAPED_SLASHES));
            \Swaggest\JsonSchema\Schema::import(
                $schemaObj,
                self::safeJsonSchemaContext(),
            )->in($dataObj, self::safeJsonSchemaContext());

            return [];
        } catch (\Swaggest\JsonSchema\InvalidValue $e) {
            return [trim($e->getMessage())];
        } catch (\Throwable $e) {
            // Schema itself was malformed (e.g. unsupported draft) — surface
            // as a single validation error so the caller can diagnose.
            return ['Schema validation setup failed: '.$e->getMessage()];
        }
    }

    /**
     * Keep JSON Schema validation self-contained. Swaggest otherwise installs
     * a BasicFetcher that performs file_get_contents() on arbitrary $ref URLs
     * with TLS verification disabled.
     */
    private static function safeJsonSchemaContext(): \Swaggest\JsonSchema\Context
    {
        return new \Swaggest\JsonSchema\Context(
            new \HaoCode\Support\JsonSchema\DenyRemoteRefProvider,
        );
    }

    private static function queryWithBudgetLedger(
        string $prompt,
        HaoCodeConfig $config,
        BudgetLedger $budgetLedger,
    ): QueryResult {
        $run = self::createRun($config, $budgetLedger);
        $loop = $run->loop;
        $userInput = $config->images !== []
            ? ImageContentBlock::buildUserContent($prompt, $config->images, $config->cwd)
            : $prompt;

        try {
            $response = $loop->run(
                userInput: $userInput,
                onTextDelta: $config->onText,
                onToolStart: $config->onToolStart,
                onToolComplete: $config->onToolComplete,
                onTurnStart: $config->onTurnStart,
                onThinkingDelta: $config->onThinking,
            );

            return new QueryResult(
                text: $response,
                usage: self::extractUsage($loop),
                cost: $loop->getEstimatedCost(),
                sessionId: $config->ephemeral ? null : $loop->getSessionManager()->getSessionId(),
                turnsUsed: $loop->getLastRunTurns(),
            );
        } finally {
            $run->close();
        }
    }

    private static function createRun(
        HaoCodeConfig $config,
        ?BudgetLedger $budgetLedger = null,
    ): SdkRun
    {
        /** @var AgentLoopFactory $factory */
        $factory = \HaoCode\Support\Runtime\SdkRuntime::app(AgentLoopFactory::class);

        return SdkRunFactory::create($config, $factory, budgetLedger: $budgetLedger);
    }

    /**
     * Build a standalone StreamingClient when SDK config overrides API settings.
     *
     * Returns null if no overrides are present (use container default).
     */
    private static function buildStreamingClient(
        HaoCodeConfig $config,
        ?SettingsManager $settings = null,
    ): ?StreamingClient {
        return SdkRunFactory::buildStreamingClient($config, $settings);
    }

    private static function extractUsage(AgentLoop $loop): array
    {
        return [
            'input_tokens' => $loop->getTotalInputTokens(),
            'output_tokens' => $loop->getTotalOutputTokens(),
            'cache_creation_tokens' => $loop->getCacheCreationTokens(),
            'cache_read_tokens' => $loop->getCacheReadTokens(),
            'last_turn_input_tokens' => $loop->getLastTurnInputTokens(),
            'cost_available' => $loop->isCostEstimateAvailable(),
        ];
    }
}
