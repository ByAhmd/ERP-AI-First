<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Contact;
use App\Services\Purchases\BillOutstanding;
use App\Services\Sales\InvoiceOutstanding;
use Carbon\CarbonImmutable;

/**
 * ملخص مستحقات العملاء / الموردين — the balances summary reports.
 *
 * One row per contact, four figures: the open invoices, the standalone
 * notes not applied to any of them, the vouchers paid or received and not
 * yet allocated, and the net of the three — what the relationship actually
 * still owes. This is where Qoyod surfaces the unused voucher amounts the
 * aging grid deliberately omits.
 *
 * Qoyod's version carries one more column — صافي حركات القيود اليدوية, the
 * net of manual journal entries touching the contact — which cannot exist
 * here: journal lines carry no contact. A manual entry against receivable
 * or payable is visible in the ledger reports, not per contact. Tracked as
 * a parity gap.
 *
 * All three figures come from the outstanding services, so this report and
 * the aging reports can never disagree about any of them.
 */
final class BalancesSummary
{
    private const SCALE = 4;

    public function __construct(
        private readonly InvoiceOutstanding $receivables,
        private readonly BillOutstanding $payables,
    ) {}

    /**
     * @return array{rows: list<BalancesSummaryRow>, totals: array<string, string>}
     */
    public function customers(CarbonImmutable $asOf): array
    {
        return $this->assemble(
            $this->receivables->openByContact($asOf),
            $this->receivables->unappliedNotesByContact($asOf),
            $this->receivables->advancesByContact($asOf),
        );
    }

    /**
     * @return array{rows: list<BalancesSummaryRow>, totals: array<string, string>}
     */
    public function suppliers(CarbonImmutable $asOf): array
    {
        return $this->assemble(
            $this->payables->openByContact($asOf),
            $this->payables->unappliedNotesByContact($asOf),
            $this->payables->advancesByContact($asOf),
        );
    }

    /**
     * @param  array<string, array{amount: string, count: int}>  $open
     * @param  array<string, string>  $notes
     * @param  array<string, string>  $vouchers
     * @return array{rows: list<BalancesSummaryRow>, totals: array<string, string>}
     */
    private function assemble(array $open, array $notes, array $vouchers): array
    {
        $ids = array_values(array_unique([
            ...array_keys($open),
            ...array_keys($notes),
            ...array_keys($vouchers),
        ]));

        if ($ids === []) {
            return [
                'rows' => [],
                'totals' => [
                    'open_invoices' => '0.0000',
                    'unapplied_notes' => '0.0000',
                    'unused_vouchers' => '0.0000',
                    'net' => '0.0000',
                ],
            ];
        }

        $contacts = Contact::query()
            ->withTrashed()
            ->whereKey($ids)
            ->orderBy('contact_name')
            ->get();

        $rows = [];
        $totals = [
            'open_invoices' => '0.0000',
            'unapplied_notes' => '0.0000',
            'unused_vouchers' => '0.0000',
            'net' => '0.0000',
        ];

        foreach ($contacts as $contact) {
            $id = $contact->getKey();

            $openAmount = $open[$id]['amount'] ?? '0.0000';
            $noteAmount = $notes[$id] ?? '0.0000';
            $voucherAmount = $vouchers[$id] ?? '0.0000';

            $net = bcsub(
                bcsub($openAmount, $noteAmount, self::SCALE),
                $voucherAmount,
                self::SCALE,
            );

            $rows[] = new BalancesSummaryRow(
                contactId: $id,
                name: $contact->displayName(),
                code: $contact->code,
                openInvoices: $openAmount,
                unappliedNotes: $noteAmount,
                unusedVouchers: $voucherAmount,
                net: $net,
            );

            $totals['open_invoices'] = bcadd($totals['open_invoices'], $openAmount, self::SCALE);
            $totals['unapplied_notes'] = bcadd($totals['unapplied_notes'], $noteAmount, self::SCALE);
            $totals['unused_vouchers'] = bcadd($totals['unused_vouchers'], $voucherAmount, self::SCALE);
            $totals['net'] = bcadd($totals['net'], $net, self::SCALE);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}
