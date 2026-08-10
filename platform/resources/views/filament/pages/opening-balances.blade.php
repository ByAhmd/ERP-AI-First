@php
    $accounts = $this->getAccounts();
    $years = $this->getFiscalYears();
    $totals = $this->getTotals();
    $entry = $this->getEntry();
    $posted = $this->isPosted();

    $money = fn (string $amount) => bccomp($amount, '0', 4) === 0 ? '—' : number_format((float) $amount, 2);
    $balanced = bccomp($totals['difference'], '0', 4) === 0;
@endphp

<x-filament-panels::page>
    @if ($years->isEmpty())
        <x-filament::section>
            <p class="erp-report__empty">{{ __('accounting.opening_balances.no_fiscal_year') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="erp-ob__controls">
                <label class="erp-ob__field">
                    <span class="erp-report__summary-label">{{ __('accounting.opening_balances.fiscal_year') }}</span>
                    <select wire:model.live="fiscalYearId" class="erp-ob__select" @disabled($posted && $years->count() === 1)>
                        @foreach ($years as $year)
                            <option value="{{ $year->getKey() }}">{{ $year->name }}</option>
                        @endforeach
                    </select>
                </label>

                {{-- The difference is stated before the figures rather than left
                     to be discovered, because it is the one thing the person
                     doing this needs to watch. --}}
                <div class="erp-ob__verdict">
                    @if ($balanced)
                        <span class="erp-report__verdict fi-color-success">
                            <x-filament::icon icon="heroicon-o-check-circle" class="erp-report__verdict-icon" />
                            {{ __('accounting.opening_balances.balanced') }}
                        </span>
                    @else
                        <span class="erp-report__verdict fi-color-warning">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="erp-report__verdict-icon" />
                            {{ __('accounting.opening_balances.unbalanced', [
                                'difference' => number_format(abs((float) $totals['difference']), 2),
                            ]) }}
                        </span>
                    @endif
                </div>
            </div>
        </x-filament::section>

        @if ($posted)
            <x-filament::section>
                <div class="erp-report__verdict erp-report__verdict--muted">
                    <x-filament::icon icon="heroicon-o-lock-closed" class="erp-report__verdict-icon" />
                    {{ __('accounting.opening_balances.posted_notice', ['number' => $entry?->number]) }}
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="erp-report__scroll">
                <table class="erp-report__table erp-ob__table">
                    <thead>
                        <tr>
                            <th class="erp-report__num">{{ __('accounting.opening_balances.columns.code') }}</th>
                            <th>{{ __('accounting.opening_balances.columns.account') }}</th>
                            <th>{{ __('accounting.opening_balances.columns.type') }}</th>
                            <th class="erp-report__num">{{ __('accounting.opening_balances.columns.debit') }}</th>
                            <th class="erp-report__num">{{ __('accounting.opening_balances.columns.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($accounts as $account)
                            <tr class="erp-report__row">
                                <td class="erp-report__num">{{ $account->code }}</td>
                                <td>{{ $account->name }}</td>
                                <td class="erp-ob__type">{{ $account->type->getLabel() }}</td>

                                @foreach (['debit', 'credit'] as $side)
                                    <td class="erp-report__num">
                                        @if ($posted)
                                            {{ $money($this->balances[$account->getKey()][$side] ?? '0') }}
                                        @else
                                            <input
                                                type="number"
                                                step="0.01"
                                                inputmode="decimal"
                                                class="erp-ob__input"
                                                wire:model.blur="balances.{{ $account->getKey() }}.{{ $side }}"
                                                aria-label="{{ $account->name }} — {{ __("accounting.opening_balances.columns.{$side}") }}"
                                            />
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="erp-report__empty">
                                    {{ __('accounting.opening_balances.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($accounts->isNotEmpty())
                        <tfoot>
                            <tr class="erp-report__row--total">
                                <td colspan="3">{{ __('accounting.opening_balances.totals') }}</td>
                                <td class="erp-report__num">{{ $money($totals['debit']) }}</td>
                                <td class="erp-report__num">{{ $money($totals['credit']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
