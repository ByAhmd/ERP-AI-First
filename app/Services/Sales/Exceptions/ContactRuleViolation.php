<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use App\Models\Contact;
use RuntimeException;

/**
 * A change to a contact that cannot be accepted.
 */
final class ContactRuleViolation extends RuntimeException
{
    /**
     * Sales to a government body are reported differently. Clearing the flag
     * would change how invoices already raised against the contact should have
     * been treated, after the fact.
     */
    public static function governmentEntityCannotBeCleared(Contact $contact): self
    {
        return new self(__('sales.contacts.errors.government_entity_locked', [
            'contact' => $contact->contact_name,
        ]));
    }
}
