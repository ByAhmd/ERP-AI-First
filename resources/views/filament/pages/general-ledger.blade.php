@php
    $account = $this->getAccount();
    $movements = $this->getMovements();
    $summary = $this->getSummary();
    $money = fn ($amount) => (float) $amount == 0.0 ? '—' : number_format((float) $amount, 2);

    // A balance reads in the account's natural direction with its side marked,
    // so a payable at 5,000 credit shows as owed rather than negative.
    $signed = function ($amount) {
        if ((float) $amount == 0.0) {
            return '—';
        }

        $side = (float) $amount > 0
            ? __('accounting.normal_balance.debit')
            : __('accounting.normal_balance.credit');

        return number_format(abs((float) $amount), 2).' '.$side;
    };
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($account === null)
        <x-filament::section>
            <p class="erp-report__empty">{{ __('accounting.general_ledger.choose_account') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="erp-report__summary">
                <div>
                    <div class="erp-report__summary-label">{{ __('accounting.general_ledger.opening') }}</div>
                    <div class="erp-report__summary-value">{{ $signed($summary['opening']) }}</div>
                </div>
                <div>
                    <div class="erp-report__summary-label">{{ __('accounting.normal_balance.debit') }}</div>
                    <div class="erp-report__summary-value">{{ $money($summary['debit']) }}</div>
                </div>
                <div>
                    <div class="erp-report__summary-label">{{ __('accounting.normal_balance.credit') }}</div>
                    <div class="erp-report__summary-value">{{ $money($summary['credit']) }}</div>
                </div>
                <div>
                    <div class="erp-report__summary-label">{{ __('accounting.general_ledger.closing') }}</div>
                    <div class="erp-report__summary-value erp-report__summary-value--strong">
                        {{ $signed($summary['closing']) }}
                    </div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="erp-report__scroll">
                <table class="erp-report__table">
                    <thead>
                        <tr>
                            <th>{{ __('accounting.entries.columns.date') }}</th>
                            <th>{{ __('accounting.entries.columns.number') }}</th>
                            <th>{{ __('accounting.entries.columns.description') }}</th>
                            <th class="erp-report__num">{{ __('accounting.normal_balance.debit') }}</th>
                            <th class="erp-report__num">{{ __('accounting.normal_balance.credit') }}</th>
                            <th class="erp-report__num">{{ __('accounting.general_ledger.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- The opening balance is a row of its own: without it the
                             first movement's running balance looks unexplained. --}}
                        <tr class="erp-report__row--opening">
                            <td colspan="5">{{ __('accounting.general_ledger.opening') }}</td>
                            <td class="erp-report__num">{{ $signed($summary['opening']) }}</td>
                        </tr>

                        @forelse ($movements as $movement)
                            <tr class="erp-report__row">
                                <td class="erp-report__date">{{ $movement->date->translatedFormat('d M Y') }}</td>
                                <td class="erp-report__num">{{ $movement->number }}</td>
                                <td>
                                    {{ $movement->description ?? '—' }}
                                    @if ($movement->reference)
                                        <span class="erp-report__note">({{ $movement->reference }})</span>
                                    @endif
                                </td>
                                <td class="erp-report__num">{{ $money($movement->debit) }}</td>
                                <td class="erp-report__num">{{ $money($movement->credit) }}</td>
                                <td class="erp-report__num">{{ $signed($movement->balance) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="erp-report__empty">
                                    {{ __('accounting.general_ledger.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="erp-report__row--total">
                            <td colspan="3">{{ __('accounting.general_ledger.closing') }}</td>
                            <td class="erp-report__num">{{ $money($summary['debit']) }}</td>
                            <td class="erp-report__num">{{ $money($summary['credit']) }}</td>
                            <td class="erp-report__num">{{ $signed($summary['closing']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
