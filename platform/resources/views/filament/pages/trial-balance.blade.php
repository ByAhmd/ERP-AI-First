@php
    $rows = $this->getRows();
    $totals = $this->getTotals();
    $money = fn ($amount) => (float) $amount == 0.0 ? '—' : number_format((float) $amount, 2);
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    {{-- The verdict first. Whether the ledger balances is the question this
         report exists to answer, so it is stated before the detail. --}}
    <x-filament::section>
        @if ($totals['balanced'])
            <div class="fi-color-success" style="display:flex;align-items:center;gap:.5rem;font-weight:600;">
                <x-filament::icon icon="heroicon-o-check-circle" style="width:1.25rem;height:1.25rem;" />
                {{ __('accounting.trial_balance.balanced') }}
            </div>
        @else
            <div class="fi-color-danger" style="display:flex;align-items:center;gap:.5rem;font-weight:600;">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width:1.25rem;height:1.25rem;" />
                {{ __('accounting.trial_balance.out_of_balance', [
                    'difference' => number_format(abs((float) $totals['closing_debit'] - (float) $totals['closing_credit']), 2),
                ]) }}
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <div style="overflow-x:auto;">
            <table class="fi-ta-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:start;padding:.5rem;">{{ __('accounting.accounts.columns.code') }}</th>
                        <th style="text-align:start;padding:.5rem;">{{ __('accounting.accounts.columns.name') }}</th>
                        <th style="text-align:end;padding:.5rem;">{{ __('accounting.trial_balance.opening') }}</th>
                        <th style="text-align:end;padding:.5rem;">{{ __('accounting.normal_balance.debit') }}</th>
                        <th style="text-align:end;padding:.5rem;">{{ __('accounting.normal_balance.credit') }}</th>
                        <th style="text-align:end;padding:.5rem;">{{ __('accounting.trial_balance.closing_debit') }}</th>
                        <th style="text-align:end;padding:.5rem;">{{ __('accounting.trial_balance.closing_credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr style="border-top:1px solid var(--gray-200);">
                            <td style="padding:.5rem;font-variant-numeric:tabular-nums;">{{ $row->code }}</td>
                            <td style="padding:.5rem;">{{ $row->name }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">
                                {{ $money((float) $row->openingDebit - (float) $row->openingCredit) }}
                            </td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($row->periodDebit) }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($row->periodCredit) }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($row->closingDebit) }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($row->closingCredit) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding:2rem;text-align:center;color:var(--gray-500);">
                                {{ __('accounting.trial_balance.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot>
                        <tr style="border-top:2px solid var(--gray-400);font-weight:700;">
                            <td colspan="3" style="padding:.5rem;">{{ __('accounting.trial_balance.totals') }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($totals['period_debit']) }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($totals['period_credit']) }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($totals['closing_debit']) }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($totals['closing_credit']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
