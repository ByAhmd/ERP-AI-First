@php
    $rows = $this->getRows();
    $totals = $this->getTotals();
    $money = fn ($amount) => (float) $amount == 0.0 ? '—' : number_format((float) $amount, 2);
    $difference = abs((float) $totals['closing_debit'] - (float) $totals['closing_credit']);
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    {{-- The verdict first. Whether the ledger balances is the question this
         report exists to answer, so it is stated before the detail. --}}
    <x-filament::section>
        @if ($totals['balanced'])
            <div class="erp-report__verdict fi-color-success">
                <x-filament::icon icon="heroicon-o-check-circle" class="erp-report__verdict-icon" />
                {{ __('accounting.trial_balance.balanced') }}
            </div>
        @else
            <div class="erp-report__verdict fi-color-danger">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="erp-report__verdict-icon" />
                {{ __('accounting.trial_balance.out_of_balance', ['difference' => number_format($difference, 2)]) }}
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <div class="erp-report__scroll">
            <table class="erp-report__table">
                <thead>
                    <tr>
                        <th>{{ __('accounting.accounts.columns.code') }}</th>
                        <th>{{ __('accounting.accounts.columns.name') }}</th>
                        <th class="erp-report__num">{{ __('accounting.trial_balance.opening') }}</th>
                        <th class="erp-report__num">{{ __('accounting.normal_balance.debit') }}</th>
                        <th class="erp-report__num">{{ __('accounting.normal_balance.credit') }}</th>
                        <th class="erp-report__num">{{ __('accounting.trial_balance.closing_debit') }}</th>
                        <th class="erp-report__num">{{ __('accounting.trial_balance.closing_credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="erp-report__row">
                            <td class="erp-report__num">{{ $row->code }}</td>
                            <td>{{ $row->name }}</td>
                            <td class="erp-report__num">
                                {{ $money((float) $row->openingDebit - (float) $row->openingCredit) }}
                            </td>
                            <td class="erp-report__num">{{ $money($row->periodDebit) }}</td>
                            <td class="erp-report__num">{{ $money($row->periodCredit) }}</td>
                            <td class="erp-report__num">{{ $money($row->closingDebit) }}</td>
                            <td class="erp-report__num">{{ $money($row->closingCredit) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="erp-report__empty">
                                {{ __('accounting.trial_balance.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot>
                        <tr class="erp-report__row--total">
                            <td colspan="3">{{ __('accounting.trial_balance.totals') }}</td>
                            <td class="erp-report__num">{{ $money($totals['period_debit']) }}</td>
                            <td class="erp-report__num">{{ $money($totals['period_credit']) }}</td>
                            <td class="erp-report__num">{{ $money($totals['closing_debit']) }}</td>
                            <td class="erp-report__num">{{ $money($totals['closing_credit']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
