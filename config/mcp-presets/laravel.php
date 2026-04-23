<?php

return [
    'allowed_tools' => ['Read', 'Grep', 'Glob'],
    'denied_tools' => ['Bash', 'Write'],
    'root' => 'cwd',
    'blacklist_extra' => ['storage/', 'bootstrap/cache/'],
    'description' => 'Laravel read-only: Read + Grep + Glob within app root, no Bash.',
];
