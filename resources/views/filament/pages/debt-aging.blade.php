@php
    $data = $this->getData();
    $money = fn ($amount) => (float) $amount == 0.0 ? '—' : number_format((float) $amount, 2);
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <div class="erp-report__scroll">
            @if ($this->isDetails())
                <table class="erp-report__table">
                    <thead>
                        <tr>
                            <th>{{ __('accounting.debt_aging.columns.reference') }}</th>
                            <th>{{ __('accounting.debt_aging.columns.document_type') }}</th>
                            <th>{{ __('accounting.debt_aging.columns.issue_date') }}</th>
                            <th>{{ __('accounting.debt_aging.columns.due_date') }}</th>
                            <th>{{ __('accounting.debt_aging.columns.contact') }}</th>
                            <th class="erp-report__num">{{ __('accounting.debt_aging.columns.remainder') }}</th>
                            <th class="erp-report__num">{{ __('accounting.debt_aging.columns.delay') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data->details as $row)
                            <tr class="erp-report__row">
                                <td>{{ $row->reference }}</td>
                                <td>{{ __('accounting.debt_aging.document_types.'.$row->documentType) }}</td>
                                <td class="erp-report__num">{{ $row->issueDate }}</td>
                                <td class="erp-report__num">{{ $row->dueDate ?? '—' }}</td>
                                <td>{{ $row->contactName }}</td>
                                <td class="erp-report__num">{{ number_format((float) $row->remainder, 2) }}</td>
                                <td class="erp-report__num">{{ $row->delayDays }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="erp-report__empty">
                                    {{ __('accounting.debt_aging.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($data->details !== [])
                        <tfoot>
                            <tr class="erp-report__row--total">
                                <td colspan="5">{{ __('accounting.debt_aging.totals') }}</td>
                                <td class="erp-report__num">{{ number_format((float) $data->totals['total'], 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            @else
                <table class="erp-report__table">
                    <thead>
                        <tr>
                            <th>{{ __('accounting.debt_aging.columns.contact') }}</th>
                            <th>{{ __('accounting.debt_aging.columns.contact_type') }}</th>
                            <th class="erp-report__num">{{ __('accounting.debt_aging.buckets.current') }}</th>
                            <th class="erp-report__num">{{ __('accounting.debt_aging.buckets.b1_30') }}</th>
                            <th class="erp-report__num">{{ __('accounting.debt_aging.buckets.b31_60') }}</th>
                            <th class="erp-report__num">{{ __('accounting.debt_aging.buckets.b61_90') }}</th>
                            <th class="erp-report__num">{{ __('accounting.debt_aging.buckets.over_90') }}</th>
                            <th class="erp-report__num">{{ __('accounting.debt_aging.columns.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data->summary as $row)
                            <tr class="erp-report__row">
                                <td>{{ $row->name }}</td>
                                <td>{{ __('accounting.debt_aging.contact_types.'.$row->type) }}</td>
                                @foreach (\App\Services\Reports\DebtAgingData::BUCKETS as $bucket)
                                    <td class="erp-report__num">{{ $money($row->buckets[$bucket]) }}</td>
                                @endforeach
                                <td class="erp-report__num">{{ number_format((float) $row->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="erp-report__empty">
                                    {{ __('accounting.debt_aging.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($data->summary !== [])
                        <tfoot>
                            <tr class="erp-report__row--total">
                                <td colspan="2">{{ __('accounting.debt_aging.totals') }}</td>
                                @foreach (\App\Services\Reports\DebtAgingData::BUCKETS as $bucket)
                                    <td class="erp-report__num">{{ $money($data->totals[$bucket]) }}</td>
                                @endforeach
                                <td class="erp-report__num">{{ number_format((float) $data->totals['total'], 2) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
