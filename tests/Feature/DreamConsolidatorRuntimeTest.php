<?php

namespace Tests\Feature;

use HaoCode\Services\Memory\ConsolidationLock;
use HaoCode\Services\Memory\DreamConsolidator;
use HaoCode\Services\Memory\SessionMemory;
use Tests\TestCase;

class DreamConsolidatorRuntimeTest extends TestCase
{
    public function test_default_transcript_dir_follows_session_path_config(): void
    {
        $sessionPath = storage_path('app/custom-haocode/sessions');
        config(['haocode.session_path' => $sessionPath]);

        $consolidator = new DreamConsolidator(
            new SessionMemory,
            new ConsolidationLock,
            $sessionPath,
        );

        $this->assertSame($sessionPath, $consolidator->getTranscriptDir());
    }
}
