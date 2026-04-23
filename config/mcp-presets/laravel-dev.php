<?php

return [
    'allowed_tools' => ['Read', 'Grep', 'Glob', 'Bash', 'Write'],
    'denied_tools' => [],
    'root' => 'cwd',
    'allow_bash_commands' => ['composer', 'php artisan'],
    'allow_write_paths' => ['storage/'],
    'description' => 'Laravel dev: adds Bash (composer/artisan) + Write to storage/ on top of laravel preset.',
];
