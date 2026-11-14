<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Models\User;

/**
 * The user representation returned by the API — never a raw Eloquent model
 * (AI_RULES.md #4, docs/09_Backend_Architecture.md §3).
 */
final readonly class UserSummaryDto
{
    public function __construct(
        public int $id,
        public string $email,
        public string $status,
        public string $locale,
        public bool $twoFactorEnabled,
        public bool $recoveryKeyAcknowledged,
        public string $createdAt,
    ) {
    }

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            email: $user->email,
            status: $user->status,
            locale: $user->locale,
            twoFactorEnabled: $user->two_factor_enabled,
            recoveryKeyAcknowledged: $user->recovery_key_acknowledged_at !== null,
            createdAt: $user->created_at->toIso8601String(),
        );
    }

    /** @return array<string, mixed> snake_case per docs/16_Coding_Standards.md §4 */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'status' => $this->status,
            'locale' => $this->locale,
            'two_factor_enabled' => $this->twoFactorEnabled,
            'recovery_key_acknowledged' => $this->recoveryKeyAcknowledged,
            'created_at' => $this->createdAt,
        ];
    }
}
