<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The person who can confirm the user is alive during emergency verification
 * (docs/07_Database_Design.md §emergency_contacts). No vault access, ever (EC-2).
 */
class EmergencyContact extends Model
{
    protected $fillable = [
        'name_enc',
        'email',
        'phone',
    ];

    protected $hidden = ['name_enc'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
