<?php

namespace Tests;

use HaoCode\Support\Runtime\SdkRuntime;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected mixed $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshApplication();
    }

    protected function refreshApplication(): void
    {
        SdkRuntime::reset();
        $this->app = SdkRuntime::boot(basePath: dirname(__DIR__));
    }
}
