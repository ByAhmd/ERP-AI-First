@php
    use App\Services\Accounting\Reports\DrillKind;

    $statement = $this->getStatement();
    $balanced = $statement->isBalanced();
    $drillDown = (bool) ($this->filters['drill_down'] ?? false);

    // Accounting convention: a negative figure is parenthesised rather than
    // signed. A minus sign leading an Arabic right-to-left line is easy to lose;
    // brackets are unambiguous in either direction.
    $money = function (string $amount): string {
        if (bccomp($amount, '0', 4) === 0) {
            return '—';
        }

        $formatted = number_format((float) ltrim($amount, '-'), 2);

        return str_starts_with($amount, '-') ? '('.$formatted.')' : $formatted;
    };

    $isNegative = fn (string $amount): bool => bccomp($amount, '0', 4) < 0;

    $signed = function (?string $amount) {
        if ($amount === null || bccomp($amount, '0', 4) === 0) {
            return '—';
        }

        $side = bccomp($amount, '0', 4) > 0
            ? __('accounting.normal_balance.debit')
            : __('accounting.normal_balance.credit');

        return number_format(abs((float) $amount), 2).' '.$side;
    };
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    {{-- A balance sheet that does not balance invalidates itself, so the
         verdict is stated before the figures rather than left to be discovered
         by adding up two totals. --}}
    @if ($balanced === false)
        <x-filament::section>
            <div class="erp-report__verdict fi-color-danger">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="erp-report__verdict-icon" />
                {{ __($statement->imbalanceMessage ?? 'accounting.statements.out_of_balance', [
                    'difference' => number_format((float) $statement->largestImbalance(), 2),
                ]) }}
            </div>
        </x-filament::section>
    @elseif ($statement->imbalance !== null && $statement->isFiltered)
        <x-filament::section>
            <div class="erp-report__verdict erp-report__verdict--muted">
                <x-filament::icon icon="heroicon-o-information-circle" class="erp-report__verdict-icon" />
                {{ __('accounting.statements.filtered_notice') }}
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <div class="erp-report__scroll">
            <table class="erp-report__table erp-statement">
                <thead>
                    <tr>
                        <th class="erp-statement__label">{{ __('accounting.statements.account') }}</th>
                        @foreach ($statement->periods as $period)
                            <th class="erp-report__num">{{ $period->label }}</th>
                        @endforeach
                    </tr>
                </thead>

                @foreach ($statement->sections as $sectionIndex => $section)
                    <tbody class="erp-statement__section">
                        @if ($section->isSummary)
                            <tr @class([
                                'erp-statement__summary',
                                'erp-statement__summary--emphasised' => $section->isEmphasised,
                            ])>
                                <th scope="row" class="erp-statement__label">{{ $section->title() }}</th>
                                @foreach ($section->totals as $columnIndex => $amount)
                                    @php
                                        $canDrill = $drillDown
                                            && $section->isDrillable()
                                            && bccomp($amount, '0', 4) !== 0;
                                    @endphp
                                    <td
                                        @class([
                                            'erp-report__num',
                                            'erp-statement__amount--negative' => $isNegative($amount),
                                            'erp-statement__amount--drillable' => $canDrill,
                                        ])
                                        @if ($canDrill)
                                            wire:click="openDrillSection({{ $sectionIndex }}, {{ $columnIndex }}, 'summary')"
                                            role="button"
                                            tabindex="0"
                                        @endif
                                    >
                                        {{ $money($amount) }}
                                    </td>
                                @endforeach
                            </tr>
                        @else
                            <tr class="erp-statement__heading">
                                <th scope="row" class="erp-statement__label" colspan="{{ $statement->columnCount() + 1 }}">
                                    {{ $section->title() }}
                                </th>
                            </tr>

                            @forelse ($section->lines as $lineIndex => $line)
                                @include('filament.pages.partials.statement-line', [
                                    'line' => $line,
                                    'money' => $money,
                                    'isNegative' => $isNegative,
                                    'sectionIndex' => $sectionIndex,
                                    'linePath' => (string) $lineIndex,
                                    'drillDown' => $drillDown,
                                ])
                            @empty
                                <tr>
                                    <td colspan="{{ $statement->columnCount() + 1 }}" class="erp-report__empty">
                                        {{ __('accounting.statements.empty_section') }}
                                    </td>
                                </tr>
                            @endforelse

                            <tr class="erp-statement__total">
                                <th scope="row" class="erp-statement__label">{{ $section->totalLabel() }}</th>
                                @foreach ($section->totals as $columnIndex => $amount)
                                    @php
                                        $canDrill = $drillDown
                                            && $section->isDrillable()
                                            && bccomp($amount, '0', 4) !== 0;
                                    @endphp
                                    <td
                                        @class([
                                            'erp-report__num',
                                            'erp-statement__amount--negative' => $isNegative($amount),
                                            'erp-statement__amount--drillable' => $canDrill,
                                        ])
                                        @if ($canDrill)
                                            wire:click="openDrillSection({{ $sectionIndex }}, {{ $columnIndex }}, 'total')"
                                            role="button"
                                            tabindex="0"
                                        @endif
                                    >
                                        {{ $money($amount) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endif
                    </tbody>
                @endforeach
            </table>
        </div>
    </x-filament::section>

    <x-filament::modal width="5xl" wire:model.live="showDrillModal">
        @if ($drillPanel !== null)
            <x-slot name="heading">
                {{ $drillPanel['title'] }}
            </x-slot>

            <x-slot name="description">
                {{ $drillPanel['periodLabel'] }}
            </x-slot>

            @if ($drillPanel['isFiltered'])
                <div class="erp-report__verdict erp-report__verdict--muted mb-4">
                    <x-filament::icon icon="heroicon-o-information-circle" class="erp-report__verdict-icon" />
                    {{ __('accounting.statements.filtered_notice') }}
                </div>
            @endif

            @if ($drillPanel['kind'] === DrillKind::BalanceChange->value)
                <div class="erp-report__summary mb-4">
                    <div>
                        <div class="erp-report__summary-label">{{ __('accounting.general_ledger.opening') }}</div>
                        <div class="erp-report__summary-value">{{ $signed($drillPanel['opening']) }}</div>
                    </div>
                    <div>
                        <div class="erp-report__summary-label">{{ __('accounting.general_ledger.closing') }}</div>
                        <div class="erp-report__summary-value erp-report__summary-value--strong">{{ $signed($drillPanel['closing']) }}</div>
                    </div>
                </div>
            @elseif ($drillPanel['kind'] === DrillKind::CumulativeBalance->value && filled($drillPanel['closing']))
                <div class="erp-report__summary mb-4">
                    <div>
                        <div class="erp-report__summary-label">{{ __('accounting.general_ledger.closing') }}</div>
                        <div class="erp-report__summary-value erp-report__summary-value--strong">{{ $signed($drillPanel['closing']) }}</div>
                    </div>
                </div>
            @endif

            @if ($drillPanel['isBreakdown'] ?? false)
                <div class="erp-report__scroll">
                    <table class="erp-report__table">
                        <thead>
                            <tr>
                                <th>{{ __('accounting.statements.drill_component') }}</th>
                                <th class="erp-report__num">{{ __('accounting.statements.drill_effect') }}</th>
                                <th class="erp-report__num">{{ __('accounting.statements.drill_amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($drillPanel['breakdownRows'] as $row)
                                <tr class="erp-report__row">
                                    <td>{{ $row['label'] }}</td>
                                    <td class="erp-report__num">
                                        {{ $row['sign'] >= 0 ? __('accounting.statements.drill_sign_add') : __('accounting.statements.drill_sign_subtract') }}
                                    </td>
                                    <td @class(['erp-report__num', 'erp-statement__amount--negative' => bccomp($row['signedAmount'], '0', 4) < 0])>
                                        {{ $money($row['signedAmount']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        @if (filled($drillPanel['total']))
                            <tfoot>
                                <tr class="erp-report__row--total">
                                    <td colspan="2">{{ __('accounting.statements.drill_breakdown_total') }}</td>
                                    <td @class(['erp-report__num', 'erp-statement__amount--negative' => bccomp($drillPanel['total'], '0', 4) < 0])>
                                        {{ $money($drillPanel['total']) }}
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            @else
            <div class="erp-report__scroll">
                <table class="erp-report__table">
                    <thead>
                        <tr>
                            <th>{{ __('accounting.entries.columns.date') }}</th>
                            <th>{{ __('accounting.entries.columns.number') }}</th>
                            @if ($drillPanel['hasAccountColumn'])
                                <th>{{ __('accounting.statements.drill_account') }}</th>
                            @endif
                            <th>{{ __('accounting.entries.columns.description') }}</th>
                            <th class="erp-report__num">{{ __('accounting.normal_balance.debit') }}</th>
                            <th class="erp-report__num">{{ __('accounting.normal_balance.credit') }}</th>
                            @if ($drillPanel['hasBalanceColumn'])
                                <th class="erp-report__num">{{ __('accounting.general_ledger.balance') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($drillPanel['rows'] as $row)
                            <tr class="erp-report__row">
                                <td class="erp-report__date">{{ \Illuminate\Support\Carbon::parse($row['date'])->translatedFormat('d M Y') }}</td>
                                <td class="erp-report__num">
                                    <a href="{{ $this->journalEntryUrl($row['entryId']) }}" class="text-primary-600 hover:underline">
                                        {{ $row['number'] }}
                                    </a>
                                </td>
                                @if ($drillPanel['hasAccountColumn'])
                                    <td>{{ $row['accountLabel'] ?? '—' }}</td>
                                @endif
                                <td>
                                    {{ $row['description'] ?? '—' }}
                                    @if ($row['reference'])
                                        <span class="erp-report__note">({{ $row['reference'] }})</span>
                                    @endif
                                </td>
                                <td class="erp-report__num">{{ $money($row['debit']) }}</td>
                                <td class="erp-report__num">{{ $money($row['credit']) }}</td>
                                @if ($drillPanel['hasBalanceColumn'])
                                    <td class="erp-report__num">{{ filled($row['runningBalance']) ? $signed($row['runningBalance']) : '—' }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="erp-report__empty">
                                    {{ __('accounting.general_ledger.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if (filled($drillPanel['periodDebit']) && filled($drillPanel['periodCredit']))
                        <tfoot>
                            <tr class="erp-report__row--total">
                                <td colspan="{{ $drillPanel['hasAccountColumn'] ? 4 : 3 }}">
                                    {{ __('accounting.statements.drill_period_total') }}
                                </td>
                                <td class="erp-report__num">{{ $money($drillPanel['periodDebit']) }}</td>
                                <td class="erp-report__num">{{ $money($drillPanel['periodCredit']) }}</td>
                                @if ($drillPanel['hasBalanceColumn'])
                                    <td></td>
                                @endif
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            @endif
        @endif
    </x-filament::modal>
</x-filament-panels::page>
