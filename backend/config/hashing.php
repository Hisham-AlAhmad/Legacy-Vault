<?php

declare(strict_types=1);

// Auth password hashing parameters — docs/06_Cryptography.md §6, "Password hash (auth)" row.
// These are deliberately DISTINCT from the client-side key-derivation parameters (64 MiB/3/4),
// which live in the frontend crypto constants file. Never share the two parameter sets.
return [

    'driver' => 'argon2id',

    'argon' => [
        'memory' => 19456, // KiB = 19 MiB (OWASP baseline; cheap enough for shared-hosting CPU)
        'threads' => 1,
        'time' => 2,
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
