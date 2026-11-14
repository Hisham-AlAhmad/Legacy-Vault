<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\RecipientData;
use App\Exceptions\ApiException;
use App\Models\Recipient;

/**
 * Domain invariant for recipients (docs/08_API_Specification.md POST /recipients,
 * docs/09_Backend_Architecture.md §8 layer 3): every chosen delivery method must be
 * backed by the contact detail it needs, and there must be at least one way to reach the
 * person at all — an unreachable recipient is a silent delivery failure (reliability is a
 * security property, docs/00_Project_Vision.md §3).
 */
final class RecipientReachability
{
    public static function assert(RecipientData $data): void
    {
        $errors = [];

        if (in_array(Recipient::METHOD_EMAIL, $data->preferredMethods, true) && $data->email === null) {
            $errors['email'][] = __('legacyvault.validation.email_required_for_method');
        }

        if (in_array(Recipient::METHOD_SMS, $data->preferredMethods, true) && $data->phone === null) {
            $errors['phone'][] = __('legacyvault.validation.phone_required_for_sms');
        }

        if ($data->email === null && $data->phone === null) {
            $errors['email'][] = __('legacyvault.validation.recipient_contact_required');
        }

        if ($errors !== []) {
            throw new ApiException('VALIDATION_FAILED', 'legacyvault.validation.failed', 422, $errors);
        }
    }
}
