<?php

namespace HaoCode\Services\Lsp;

use HaoCode\Support\Runtime\ProcessSupervisor;

/**
 * Represents an LSP server process communicating via stdio.
 */
class LspServerProcess
{
    private const REQUEST_TIMEOUT_SECONDS = 10.0;
    private const MAX_BUFFER_BYTES = 1_000_000;
    private const MAX_STDERR_BYTES = 16_384;

    private $process = null;
    private $input = null;
    private $output = null;
    private $error = null;
    private int $pid = 0;
    private int $requestId = 0;
    private bool $initialized = false;
    private string $stdoutBuffer = '';
    private string $stderrTail = '';

    /** @param list<string> $command */
    public function __construct(
        private readonly array $command,
    ) {}

    public function initialize(string $rootPath): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->process = proc_open($this->command, $descriptors, $pipes, $rootPath, $this->processEnvironment());

        if (!is_resource($this->process)) {
            return false;
        }

        $this->input = $pipes[0];
        $this->output = $pipes[1];
        $this->error = $pipes[2];
        foreach ([$this->input, $this->output, $this->error] as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }
        $status = proc_get_status($this->process);
        $this->pid = (int) ($status['pid'] ?? 0);

        // Send initialize request
        $response = $this->sendRequest('initialize', [
            'processId' => getmypid(),
            'rootUri' => 'file://' . $rootPath,
            'capabilities' => (object) [],
        ]);

        if ($response === null) {
            return false;
        }

        // Send initialized notification
        $this->sendNotification('initialized', (object) []);
        $this->initialized = true;
        return true;
    }

    public function goToDefinition(string $filePath, int $line, int $character): ?array
    {
        return $this->sendRequest('textDocument/definition', [
            'textDocument' => ['uri' => 'file://' . $filePath],
            'position' => ['line' => $line, 'character' => $character],
        ]);
    }

    public function findReferences(string $filePath, int $line, int $character): ?array
    {
        $response = $this->sendRequest('textDocument/references', [
            'textDocument' => ['uri' => 'file://' . $filePath],
            'position' => ['line' => $line, 'character' => $character],
            'context' => ['includeDeclaration' => true],
        ]);

        return is_array($response) ? $response : null;
    }

    public function hover(string $filePath, int $line, int $character): ?array
    {
        return $this->sendRequest('textDocument/hover', [
            'textDocument' => ['uri' => 'file://' . $filePath],
            'position' => ['line' => $line, 'character' => $character],
        ]);
    }

    public function documentSymbol(string $filePath): ?array
    {
        $response = $this->sendRequest('textDocument/documentSymbol', [
            'textDocument' => ['uri' => 'file://' . $filePath],
        ]);

        return is_array($response) ? $response : null;
    }

    public function workspaceSymbol(string $query): ?array
    {
        $response = $this->sendRequest('workspace/symbol', [
            'query' => $query,
        ]);

        return is_array($response) ? $response : null;
    }

    private function sendRequest(string $method, array|object $params): mixed
    {
        if ($this->input === null || $this->output === null) {
            return null;
        }

        $id = ++$this->requestId;
        $message = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);

        $this->writeMessage($message);
        return $this->readResponse($id);
    }

    private function sendNotification(string $method, array|object $params): void
    {
        if ($this->input === null) {
            return;
        }

        $message = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);

        $this->writeMessage($message);
    }

    private function writeMessage(string $message): void
    {
        $header = "Content-Length: " . strlen($message) . "\r\n\r\n";
        $payload = $header . $message;
        $offset = 0;
        $deadline = microtime(true) + self::REQUEST_TIMEOUT_SECONDS;

        while ($offset < strlen($payload) && microtime(true) < $deadline) {
            $written = fwrite($this->input, substr($payload, $offset));
            if ($written === false) {
                return;
            }
            if ($written > 0) {
                $offset += $written;
                continue;
            }

            $write = [$this->input];
            $read = [];
            if (is_resource($this->output)) {
                $read[] = $this->output;
            }
            if (is_resource($this->error)) {
                $read[] = $this->error;
            }
            $except = null;
            @stream_select($read, $write, $except, 0, 20_000);
            $this->drainReadyStreams($read);
        }

        fflush($this->input);
    }

    private function readResponse(int $expectedId, float $timeout = 10.0): mixed
    {
        $deadline = microtime(true) + max(0.001, $timeout);

        while (microtime(true) < $deadline) {
            $matched = $this->nextBufferedResponse($expectedId);
            if ($matched['matched']) {
                return $matched['result'];
            }

            $read = [];
            if (is_resource($this->output)) {
                $read[] = $this->output;
            }
            if (is_resource($this->error)) {
                $read[] = $this->error;
            }
            if ($read === []) {
                return null;
            }

            $write = null;
            $except = null;
            $remainingUs = max(1, (int) (($deadline - microtime(true)) * 1_000_000));
            $seconds = intdiv($remainingUs, 1_000_000);
            $microseconds = $remainingUs % 1_000_000;
            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === false) {
                return null;
            }
            if ($ready > 0) {
                $this->drainReadyStreams($read);
            }

            if (is_resource($this->process)) {
                $status = proc_get_status($this->process);
                if (! ($status['running'] ?? false) && $this->stdoutBuffer === '') {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * @return array{matched: bool, result: mixed}
     */
    private function nextBufferedResponse(int $expectedId): array
    {
        while (true) {
            $headerEnd = strpos($this->stdoutBuffer, "\r\n\r\n");
            if ($headerEnd === false) {
                return ['matched' => false, 'result' => null];
            }

            $headers = substr($this->stdoutBuffer, 0, $headerEnd);
            if (! preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
                $this->stdoutBuffer = substr($this->stdoutBuffer, $headerEnd + 4);
                continue;
            }

            $length = (int) $m[1];
            if ($length < 0 || $length > self::MAX_BUFFER_BYTES) {
                $this->stdoutBuffer = '';
                return ['matched' => false, 'result' => null];
            }
            $bodyStart = $headerEnd + 4;
            if (strlen($this->stdoutBuffer) < $bodyStart + $length) {
                return ['matched' => false, 'result' => null];
            }

            $body = substr($this->stdoutBuffer, $bodyStart, $length);
            $this->stdoutBuffer = substr($this->stdoutBuffer, $bodyStart + $length);
            $data = json_decode($body, true);
            if (! is_array($data) || isset($data['method'])) {
                continue;
            }
            if (($data['id'] ?? null) === $expectedId) {
                return ['matched' => true, 'result' => $data['result'] ?? null];
            }
        }
    }

    /** @param list<resource> $streams */
    private function drainReadyStreams(array $streams): void
    {
        foreach ($streams as $stream) {
            $chunk = stream_get_contents($stream);
            if (! is_string($chunk) || $chunk === '') {
                continue;
            }
            if ($stream === $this->output) {
                $this->stdoutBuffer .= $chunk;
                if (strlen($this->stdoutBuffer) > self::MAX_BUFFER_BYTES) {
                    $this->stdoutBuffer = substr($this->stdoutBuffer, -self::MAX_BUFFER_BYTES);
                }
            } elseif ($stream === $this->error) {
                $this->stderrTail .= $chunk;
                if (strlen($this->stderrTail) > self::MAX_STDERR_BYTES) {
                    $this->stderrTail = substr($this->stderrTail, -self::MAX_STDERR_BYTES);
                }
            }
        }
    }

    public function shutdown(): void
    {
        if ($this->input !== null && $this->initialized) {
            $this->sendRequest('shutdown', (object) []);
            $this->sendNotification('exit', (object) []);
            $this->initialized = false;
        }
        if ($this->input !== null) {
            fclose($this->input);
        }
        if ($this->output !== null) {
            fclose($this->output);
        }
        if ($this->error !== null) {
            fclose($this->error);
        }
        if (is_resource($this->process)) {
            ProcessSupervisor::terminateTree($this->pid);
            proc_close($this->process);
        }
        $this->input = null;
        $this->output = null;
        $this->error = null;
        $this->process = null;
        $this->pid = 0;
    }

    /** @return array<string, string> */
    private function processEnvironment(): array
    {
        return [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'LANG' => getenv('LANG') ?: 'C',
            'LC_ALL' => 'C',
        ];
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}
