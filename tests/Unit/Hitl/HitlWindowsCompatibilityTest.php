<?php

declare(strict_types=1);

namespace Tests\Unit\Hitl;

use HaoCode\Services\Hitl\HitlPolicy;
use PHPUnit\Framework\TestCase;

class HitlWindowsCompatibilityTest extends TestCase
{
    public function test_windows_style_workspace_paths_use_native_boundaries(): void
    {
        $resolvePath = new \ReflectionMethod(HitlPolicy::class, 'resolvePath');
        $isWithinWorkspace = new \ReflectionMethod(HitlPolicy::class, 'isWithinWorkspace');
        $root = 'C:\\workspace\\repo';

        $inside = $resolvePath->invoke(null, 'src\\File.php', $root);
        $escaped = $resolvePath->invoke(null, '..\\outside.txt', $root);
        $driveRooted = $resolvePath->invoke(null, '\\rooted\\note.txt', 'D:\\workspace\\repo');

        $this->assertSame('C:\\workspace\\repo\\src\\File.php', $inside);
        $this->assertTrue($isWithinWorkspace->invoke(null, $inside, $root));
        $this->assertSame('C:\\workspace\\outside.txt', $escaped);
        $this->assertFalse($isWithinWorkspace->invoke(null, $escaped, $root));
        $this->assertSame('D:\\rooted\\note.txt', $driveRooted);
        $this->assertFalse($isWithinWorkspace->invoke(null, 'C:\\workspace\\repository\\File.php', $root));
        $this->assertFalse($isWithinWorkspace->invoke(null, 'D:\\workspace\\repo\\File.php', $root));
    }

    public function test_windows_style_write_patch_and_redirect_classification(): void
    {
        $classifyWrite = new \ReflectionMethod(HitlPolicy::class, 'classifyWrite');
        $classifyPatch = new \ReflectionMethod(HitlPolicy::class, 'classifyPatch');
        $checkRedirects = new \ReflectionMethod(HitlPolicy::class, 'checkRedirects');
        $root = 'C:\\workspace\\repo';

        $write = $classifyWrite->invoke(null, 'Write', [
            'file_path' => 'src\\File.php',
            'content' => '<?php',
        ], $root);
        $patch = $classifyPatch->invoke(null, [
            'patch' => "*** Begin Patch\n*** Add File: src\\File.php\n+<?php\n*** End Patch",
        ], $root);
        $safeRedirect = $checkRedirects->invoke(null, 'echo ok > build\\out.txt', $root);
        $outsideRedirect = $checkRedirects->invoke(null, 'echo no > ..\\outside.txt', $root);

        $this->assertSame(HitlPolicy::AUTO_ALLOW, $write['level']);
        $this->assertSame(HitlPolicy::AUTO_ALLOW, $patch['level']);
        $this->assertNull($safeRedirect);
        $this->assertSame(HitlPolicy::RED_LINE, $outsideRedirect['level']);
    }

    public function test_sensitive_shell_arguments_are_red_lines(): void
    {
        $cwd = (string) realpath(getcwd() ?: '.');

        foreach ([
            'cat ".env" /dev/null',
            'cat id_rsa /dev/null',
            'cat secret.pem /dev/null',
        ] as $command) {
            $verdict = HitlPolicy::classifyAction('Bash', ['command' => $command], $cwd);
            $this->assertSame(
                HitlPolicy::RED_LINE,
                $verdict['level'],
                "Expected a red line for {$command}; reason: {$verdict['reason']}",
            );
        }
    }

    public function test_real_windows_workspace_paths_classify_consistently(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Real Windows path integration coverage.');
        }

        $workspace = sys_get_temp_dir().'\\haocode-hitl-windows-'.bin2hex(random_bytes(6));
        mkdir($workspace, 0700, true);
        $workspace = (string) realpath($workspace);

        try {
            $write = HitlPolicy::classifyAction(
                'Write',
                ['file_path' => 'sub\\new.php', 'content' => '<?php'],
                $workspace,
            );
            $patch = HitlPolicy::classifyAction(
                'apply_patch',
                ['patch' => "*** Begin Patch\n*** Add File: sub\\new.php\n+<?php\n*** End Patch"],
                $workspace,
            );
            $redirect = HitlPolicy::classifyAction(
                'Bash',
                ['command' => 'echo ok > build\\out.txt'],
                $workspace,
            );
            $outside = HitlPolicy::classifyAction(
                'Write',
                ['file_path' => dirname($workspace).'\\outside.php', 'content' => '<?php'],
                $workspace,
            );

            $this->assertSame(HitlPolicy::AUTO_ALLOW, $write['level']);
            $this->assertSame(HitlPolicy::AUTO_ALLOW, $patch['level']);
            $this->assertSame(HitlPolicy::AUTO_ALLOW, $redirect['level']);
            $this->assertSame(HitlPolicy::ASK, $outside['level']);
        } finally {
            @rmdir($workspace);
        }
    }
}
