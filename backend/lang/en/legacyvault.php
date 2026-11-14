<?php

declare(strict_types=1);

// User-facing backend strings — en. Every key here has a matching key in
// lang/ar/legacyvault.php, maintained in the same change (AI_RULES.md #10).
return [

    'auth' => [
        'invalid_credentials' => 'The email or password is incorrect.',
        'account_locked' => 'Too many failed attempts. Please wait a moment and try again.',
        'account_suspended' => 'This account is suspended. Contact support for help.',
        'account_not_finalized' => 'This registration was never completed. Please register again.',
        'email_in_use' => 'An account with this email already exists.',
        'totp_required' => 'Enter the 6-digit code from your authenticator app.',
        'totp_invalid' => 'That code is not valid. Try again.',
        'totp_already_enabled' => 'Two-factor authentication is already enabled.',
        'totp_not_enabled' => 'Two-factor authentication is not enabled.',
        'totp_enrollment_not_started' => 'Two-factor enrollment has not been started.',
        'session_not_found' => 'That session no longer exists.',
        'unauthenticated' => 'Please sign in to continue.',
        'reset_link_sent' => 'If an account exists for that email, a reset link is on its way.',
        'password_reset_done' => 'Your account access has been restored. Your vault is still locked — use your Recovery Key to unlock it.',
        'invalid_reset_token' => 'This reset link is invalid or has expired. Request a new one.',
        'account_not_active' => 'Finish setting up your account before adding to your vault.',
    ],

    'validation' => [
        'failed' => 'Some fields need attention.',
        'password_too_weak' => 'This password is too easy to guess. Use a longer, less common phrase.',
        'invalid_key_material' => 'The encrypted key material is malformed.',
        'invalid_sealed_field' => 'This encrypted value is malformed or too long.',
        'invalid_phone' => 'Enter the phone number in international format, starting with + and the country code.',
        'email_required_for_method' => 'Add an email address to deliver by email.',
        'phone_required_for_sms' => 'Add a phone number to deliver by SMS.',
        'recipient_contact_required' => 'Add an email address or phone number so this person can be reached.',
        'recipient_not_found' => 'That recipient no longer exists.',
        'invalid_item_id' => 'The item identifier is malformed.',
        'content_key_not_rotated' => 'An edited item must be encrypted with a new key.',
        'reorder_mismatch' => 'The list of items is out of date. Reload and try again.',
    ],

    'vault' => [
        'emergency_contact_not_found' => 'No emergency contact is set.',
        'item_id_conflict' => 'An item with this identifier already exists. Please try saving again.',
        'item_too_large' => 'This message is too long to save. Shorten it and try again.',
        'item_limit_reached' => 'This recipient already has the maximum number of messages.',
    ],

    'errors' => [
        'rate_limited' => 'Too many requests. Please slow down and try again shortly.',
        'not_found' => 'Not found.',
        'forbidden' => 'You do not have access to this.',
    ],

    'mail' => [
        'password_reset_subject' => 'Reset your Legacy Vault password',
        'password_reset_intro' => 'We received a request to reset the password for your Legacy Vault account.',
        'password_reset_button' => 'Reset password',
        'password_reset_vault_note' => 'Resetting your password restores account access only. Your vault stays locked until you enter your Recovery Key — this is how your privacy is protected.',
        'password_reset_ignore' => 'If you did not request this, you can safely ignore this email.',
    ],

];
