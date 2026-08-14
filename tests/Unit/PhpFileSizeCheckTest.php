<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Scripts\PhpFileSizeCheck;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/php-file-size-check.php';

final class PhpFileSizeCheckTest extends TestCase
{
    public function test_it_reports_only_tracked_php_files_over_the_limit(): void
    {
        $root = $this->gitFixture();

        try {
            file_put_contents($root.'/large.php', "<?php\n".str_repeat("// line\n", 5));
            file_put_contents($root.'/exact.php', "<?php\n// two\n// three");
            file_put_contents($root.'/notes.md', str_repeat("line\n", 10));
            file_put_contents($root.'/untracked.php', str_repeat("<?php\n", 10));
            $this->git($root, ['add', 'large.php', 'exact.php', 'notes.md']);

            $result = PhpFileSizeCheck::audit($root, 3);

            $this->assertSame(2, $result['files']);
            $this->assertSame(
                ['large.php has 6 lines (max 3).'],
                $result['issues'],
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_physical_line_count_handles_empty_and_unterminated_files(): void
    {
        $this->assertSame(0, PhpFileSizeCheck::countPhysicalLines(''));
        $this->assertSame(1, PhpFileSizeCheck::countPhysicalLines('<?php'));
        $this->assertSame(2, PhpFileSizeCheck::countPhysicalLines("<?php\n// two"));
        $this->assertSame(2, PhpFileSizeCheck::countPhysicalLines("<?php\n// two\n"));
    }

    private function gitFixture(): string
    {
        $root = sys_get_temp_dir().'/haocode-file-size-'.bin2hex(random_bytes(6));
        mkdir($root, 0755, true);
        $this->git($root, ['init', '--quiet']);

        return $root;
    }

    /** @param list<string> $arguments */
    private function git(string $root, array $arguments): void
    {
        $command = array_merge(['git', '-C', $root], $arguments);
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), trim((string) $stdout."\n".(string) $stderr));
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
