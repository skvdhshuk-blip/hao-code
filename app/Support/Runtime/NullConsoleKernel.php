<?php

namespace HaoCode\Support\Runtime;

class NullConsoleKernel
{
    public function bootstrap(): void
    {
        // Compatibility shim for older SDK consumers that called a console kernel.
    }
}
