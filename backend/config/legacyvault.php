<?php

declare(strict_types=1);

// Application-specific configuration. All secrets come from environment variables
// (AI_RULES.md #7, docs/14_Deployment.md §2) — no env() calls outside config files
// (docs/16_Coding_Standards.md §1).
return [

    /*
     * Server Wrap Key (SWK) — docs/06_Cryptography.md §5.
     *
     * Base64-encoded 32-byte key. The single most sensitive secret in the system
     * (docs/05_Security_Architecture.md §18). Wrapping happens once at registration in
     * ServerKeyWrapService; UNWRAPPING is permitted only inside the delivery-trigger code
     * path (AI_RULES.md #13) — no other code may read this value for unwrapping.
     */
    'server_wrap_key' => env('SERVER_WRAP_KEY'),

    /*
     * Registered-but-unacknowledged accounts (users.status = pending) are purged after
     * this many hours — docs/02_User_Flows.md Flow 1 edge case.
     */
    'pending_account_ttl_hours' => 24,

    /*
     * Failed-login handling — docs/05_Security_Architecture.md §1. Exponential backoff
     * per account: 2^(failures - threshold) seconds, capped.
     */
    'lockout' => [
        'threshold' => 5,        // failures before backoff starts
        'max_backoff_seconds' => 900,
    ],

    /*
     * Session idle lifetimes, server-enforced — docs/05_Security_Architecture.md §3.
     * The base session lifetime (12h) is config/session.php `lifetime`; remembered
     * ("trusted") devices use Laravel's remember cookie, revocable server-side.
     */

    /*
     * Payload caps for client-submitted wrapped-key material (defense against abuse —
     * a wrapped 32-byte key is well under 128 bytes; base64 of that is < 200 chars).
     */
    'max_wrapped_key_b64_length' => 512,

    /*
     * Decoded-byte cap for a sealed metadata field (docs/06_Cryptography.md §15): the
     * longest plaintext (120-char name, up to 4 UTF-8 bytes per char = 480) plus the
     * 29-byte envelope fits in 512. Matches the VARBINARY(512) columns.
     */
    'max_sealed_field_bytes' => 512,

    /*
     * Vault item caps (docs/08_API_Specification.md, docs/19_Decision_Log.md Decision #17).
     * Decoded envelope bytes — the server can't see plaintext length. Title: 200 chars ×
     * up to 4 UTF-8 bytes + 29-byte envelope fits in 1024 (VARBINARY(1024)). Message text
     * isn't counted against the attachment quota, so these caps are what bounds it.
     */
    'vault_items' => [
        'max_title_bytes' => 1024,
        'max_content_bytes' => 262144, // 256 KiB
        'max_per_recipient' => 100,
    ],

];
