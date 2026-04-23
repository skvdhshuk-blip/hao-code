<?php

return [
    'allowed_tools' => ['Read', 'Grep', 'Glob'],
    'denied_tools' => ['Bash', 'Write'],
    'description' => 'Read-only: only Read, Grep, Glob within --root. No Bash or Write.',
];
