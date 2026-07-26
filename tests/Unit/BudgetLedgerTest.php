<?php

namespace Tests\Unit;

use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Support\Runtime\SdkRuntime;
use PHPUnit\Framework\TestCase;

class BudgetLedgerTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir().'/haocode-budget-ledger-'.bin2hex(random_bytes(4));
        SdkRuntime::reset();
        SdkRuntime::boot(dirname(__DIR__, 2), $this->storagePath);
    }

    protected function tearDown(): void
    {
        SdkRuntime::reset();
        $this->removeDirectory($this->storagePath);
    }

    public function test_trackers_share_one_process_safe_budget_and_resume_it(): void
    {
        $ledger = BudgetLedger::create(1.0);
        $first = new CostTracker(0.8, 1.0, $ledger);
        $second = new CostTracker(0.8, 1.0, $ledger);

        $first->addUsage(0, 40_000);
        $second->addUsage(0, 40_000);

        $this->assertEqualsWithDelta(1.2, $first->getTotalCost(), 0.0001);
        $this->assertEqualsWithDelta(1.2, $second->getTotalCost(), 0.0001);
        $this->assertTrue($first->shouldStop());
        $this->assertTrue($second->shouldStop());

        $resumed = BudgetLedger::resume($ledger->getId(), 1.0);
        $third = new CostTracker(0.8, 1.0, $resumed);
        $this->assertEqualsWithDelta(1.2, $third->getTotalCost(), 0.0001);
        $this->assertTrue($third->shouldStop());
    }

    public function test_resume_can_tighten_budget_but_not_widen_it(): void
    {
        $ledger = BudgetLedger::create(10.0);
        $ledger->add(1.0);

        $tight = BudgetLedger::resume($ledger->getId(), 5.0);
        $this->assertEqualsWithDelta(5.0, $tight->getLimit(), 0.0001);
        $this->assertEqualsWithDelta(1.0, $tight->getSpent(), 0.0001);
        $this->assertFalse($tight->shouldStop());

        $attemptWiden = BudgetLedger::resume($ledger->getId(), 10.0);
        $this->assertEqualsWithDelta(5.0, $attemptWiden->getLimit(), 0.0001);
        $this->assertEqualsWithDelta(1.0, $attemptWiden->getSpent(), 0.0001);

        $over = BudgetLedger::resume($ledger->getId(), 5.0, 6.0);
        $this->assertEqualsWithDelta(6.0, $over->getSpent(), 0.0001);
        $this->assertTrue($over->shouldStop());
    }

    public function test_stale_ledgers_are_collected_and_can_be_rebuilt_from_a_checkpoint(): void
    {
        $ledger = BudgetLedger::create(1.0);
        $directory = SdkRuntime::storagePath('app/haocode/budgets');
        $path = $directory.'/budget-'.$ledger->getId().'.json';
        $expired = time() - (100 * 86400);
        touch($path, $expired);
        touch($directory.'/.gc', $expired);
        file_put_contents($directory.'/.gc', (string) $expired);

        BudgetLedger::create(2.0);

        $this->assertFileDoesNotExist($path);

        $resumed = BudgetLedger::resume($ledger->getId(), 1.0, 0.4);
        $this->assertFileExists($path);
        $this->assertEqualsWithDelta(0.4, $resumed->getSpent(), 0.0001);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
