<?php

namespace Tests\Unit;

use HaoCode\Services\FileHistory\FileHistoryManager;
use PHPUnit\Framework\TestCase;

class FileHistoryManagerTest extends TestCase
{
    use FileHistoryManagerTestSetUpConcern;
    use FileHistoryManagerTestTestPreplantedStorageRootSymlinkIsRejectedConcern;

    private string $sessionId;
    private FileHistoryManager $manager;
    private string $storageRoot;
    private string $historyPath;
}
