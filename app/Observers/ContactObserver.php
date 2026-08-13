<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Contact;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Sales\Exceptions\ContactRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Reference numbers and the one field a contact cannot take back.
 */
final class ContactObserver
{
    public function __construct(
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    public function creating(Contact $contact): void
    {
        if (blank($contact->code)) {
            $contact->code = $this->allocateCode($contact);
        }
    }

    public function updating(Contact $contact): void
    {
        $this->guardGovernmentEntity($contact);
    }

    /**
     * Allocate the next reference in this contact type's series.
     *
     * Qoyod opens its customer form on `CUS001`, so the series is per type and
     * padded to three. The allocator refuses to run outside a transaction —
     * deliberately, so a number cannot survive a failed insert — and a contact
     * created straight from a form is not in one, hence the wrapper.
     */
    private function allocateCode(Contact $contact): string
    {
        $prefix = $contact->type->referencePrefix();

        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'contact_'.$contact->type->value,
            defaults: ['prefix' => $prefix, 'padding' => 3],
        ));
    }

    /**
     * A contact cannot stop being a government entity.
     *
     * Qoyod's own help says the same, and the reason is regulatory rather than
     * technical: sales to a government body are reported differently, so
     * clearing the flag would silently change how invoices already raised
     * against it should have been treated. Setting it is allowed; unsetting is
     * not.
     */
    private function guardGovernmentEntity(Contact $contact): void
    {
        if (! $contact->isDirty('is_government_entity')) {
            return;
        }

        if ($contact->getRawOriginal('is_government_entity') && ! $contact->is_government_entity) {
            throw ContactRuleViolation::governmentEntityCannotBeCleared($contact);
        }
    }
}
