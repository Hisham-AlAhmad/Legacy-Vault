<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Thin Eloquent model per docs/16_Coding_Standards.md §1 — relationships/casts only,
 * business logic lives in Actions/Services.
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'email',
        'password_hash',
        'status',
        'locale',
    ];

    /** In-memory defaults matching the schema defaults, so a freshly created model is complete. */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'two_factor_enabled' => false,
        'is_admin' => false,
        'locale' => 'en',
        'failed_login_count' => 0,
    ];

    protected $hidden = [
        'password_hash',
        'two_factor_secret_enc',
        'two_factor_pending_secret_enc',
        'two_factor_backup_codes_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'two_factor_enabled' => 'boolean',
            'is_admin' => 'boolean',
            // Encrypted with the app-level key (APP_KEY), never the vault Master Key —
            // 2FA must work while the vault is locked (docs/07_Database_Design.md §users).
            'two_factor_secret_enc' => 'encrypted',
            'two_factor_pending_secret_enc' => 'encrypted',
            'two_factor_backup_codes_hash' => 'array',
            'failed_login_count' => 'integer',
            'locked_until' => 'datetime',
            'recovery_key_acknowledged_at' => 'datetime',
        ];
    }

    /** The users table stores the Argon2id PHC string in password_hash, not `password`. */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function encryptedKeys(): HasMany
    {
        return $this->hasMany(EncryptedKey::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class);
    }

    public function vaultItems(): HasMany
    {
        return $this->hasMany(VaultItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function emergencyContact(): HasOne
    {
        return $this->hasOne(EmergencyContact::class);
    }
}
