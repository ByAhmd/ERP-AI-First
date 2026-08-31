<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Contact;

/**
 * Folds per-column contact figures into the grid every aging report renders.
 *
 * All four reports produce the same shape — a map of contact to amount and
 * count, once per column date — and differ only in how they compute it. The
 * fold lives once so four copies cannot drift: the union of contacts across
 * columns, ordered by name, zero-filled where a column has nothing (Qoyod
 * shows `0 (0)`, never a blank), and a totals row summed with bcmath.
 *
 * Contacts are fetched with trashed rows included: a retired supplier's open
 * balance is still a balance, and a report that dropped the row would break
 * its tie to the control account.
 */
final class AgingGrid
{
    private const SCALE = 4;

    /**
     * @param  list<array<string, array{amount: string, count: int}>>  $columns
     * @return list<AgingRow>
     */
    public static function rows(array $columns): array
    {
        $contactIds = [];

        foreach ($columns as $column) {
            foreach (array_keys($column) as $id) {
                $contactIds[$id] = true;
            }
        }

        if ($contactIds === []) {
            return [];
        }

        $contacts = Contact::query()
            ->withTrashed()
            ->whereKey(array_keys($contactIds))
            ->orderBy('contact_name')
            ->get();

        $rows = [];

        foreach ($contacts as $contact) {
            $cells = [];

            foreach ($columns as $column) {
                $cells[] = $column[$contact->getKey()] ?? ['amount' => '0.0000', 'count' => 0];
            }

            $rows[] = new AgingRow(
                contactId: $contact->getKey(),
                name: $contact->displayName(),
                code: $contact->code,
                cells: $cells,
            );
        }

        return $rows;
    }

    /**
     * @param  list<array<string, array{amount: string, count: int}>>  $columns
     * @return list<array{amount: string, count: int}>
     */
    public static function totals(array $columns): array
    {
        return array_map(static function (array $column): array {
            $amount = '0.0000';
            $count = 0;

            foreach ($column as $cell) {
                $amount = bcadd($amount, $cell['amount'], self::SCALE);
                $count += $cell['count'];
            }

            return ['amount' => $amount, 'count' => $count];
        }, $columns);
    }
}
