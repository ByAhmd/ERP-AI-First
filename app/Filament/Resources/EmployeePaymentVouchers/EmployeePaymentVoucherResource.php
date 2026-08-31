<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeePaymentVouchers;

use App\Enums\DocumentStatus;
use App\Filament\Resources\EmployeePaymentVouchers\Pages\CreateEmployeePaymentVoucher;
use App\Filament\Resources\EmployeePaymentVouchers\Pages\EditEmployeePaymentVoucher;
use App\Filament\Resources\EmployeePaymentVouchers\Pages\ListEmployeePaymentVouchers;
use App\Filament\Resources\EmployeePaymentVouchers\Pages\ViewEmployeePaymentVoucher;
use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeePaymentVoucher;
use App\Models\Payslip;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Payroll\EmployeePaymentPoster;
use App\Services\Payroll\Exceptions\VoucherRejected;
use App\Services\Payroll\PayrollOutstanding;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Employee payment vouchers — سندات الموظفين.
 */
class EmployeePaymentVoucherResource extends Resource
{
    protected static ?string $model = EmployeePaymentVoucher::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('payroll.vouchers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payroll.vouchers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.vouchers.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('payroll.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('payroll.vouchers.sections.details'))
                ->schema([
                    Select::make('employee_id')
                        ->label(__('payroll.vouchers.fields.employee'))
                        ->options(fn (): array => Employee::query()
                            ->whereIn('status', ['active', 'terminated'])
                            ->orderBy('reference')
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [
                                $e->getKey() => $e->reference.' — '.$e->fullName(),
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->live(),

                    TextInput::make('amount')
                        ->label(__('payroll.vouchers.fields.amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),

                    DatePicker::make('payment_date')
                        ->label(__('payroll.vouchers.fields.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    Select::make('payment_account_id')
                        ->label(__('payroll.vouchers.fields.payment_account'))
                        ->options(fn (): array => Account::query()
                            ->where('is_payment_account', true)
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a): array => [
                                $a->getKey() => $a->displayName(),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),

                    TextInput::make('notes')
                        ->label(__('payroll.vouchers.fields.notes'))
                        ->maxLength(255),
                ])
                ->columns(3),

            Section::make(__('payroll.vouchers.sections.allocations'))
                ->description(__('payroll.vouchers.hints.allocations'))
                ->schema([
                    Repeater::make('allocations')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make(__('payroll.vouchers.fields.target_payslip'))->width('35%'),
                            TableColumn::make(__('payroll.vouchers.fields.target_bonus'))->width('35%'),
                            TableColumn::make(__('payroll.vouchers.fields.allocation_amount'))->width('30%')->alignEnd(),
                        ])
                        ->schema([
                            Select::make('payslip_id')
                                ->options(function (Get $get): array {
                                    $employeeId = $get('../../employee_id');

                                    if (blank($employeeId)) {
                                        return [];
                                    }

                                    $outstanding = app(PayrollOutstanding::class);

                                    return Payslip::query()
                                        ->where('employee_id', $employeeId)
                                        ->with('period')
                                        ->get()
                                        ->filter(fn (Payslip $slip): bool => bccomp($outstanding->payslipOutstanding($slip), '0', 4) > 0)
                                        ->mapWithKeys(fn (Payslip $slip): array => [
                                            $slip->getKey() => $slip->period->name
                                                .' — '.number_format((float) $outstanding->payslipOutstanding($slip), 2),
                                        ])
                                        ->all();
                                })
                                ->placeholder('—'),

                            Select::make('employee_bonus_id')
                                ->options(function (Get $get): array {
                                    $employeeId = $get('../../employee_id');

                                    if (blank($employeeId)) {
                                        return [];
                                    }

                                    $outstanding = app(PayrollOutstanding::class);

                                    return EmployeeBonus::query()
                                        ->where('employee_id', $employeeId)
                                        ->where('status', DocumentStatus::Approved)
                                        ->get()
                                        ->filter(fn (EmployeeBonus $bonus): bool => bccomp($outstanding->bonusOutstanding($bonus), '0', 4) > 0)
                                        ->mapWithKeys(fn (EmployeeBonus $bonus): array => [
                                            $bonus->getKey() => $bonus->reference
                                                .' — '.number_format((float) $outstanding->bonusOutstanding($bonus), 2),
                                        ])
                                        ->all();
                                })
                                ->placeholder('—'),

                            TextInput::make('amount')
                                ->numeric()
                                ->minValue(0.01)
                                ->required(),
                        ])
                        ->defaultItems(1)
                        ->minItems(1)
                        ->reorderable(false)
                        ->addActionLabel(__('payroll.vouchers.sections.allocations')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'paymentAccount']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('payroll.vouchers.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.first_name')
                    ->label(__('payroll.vouchers.columns.employee'))
                    ->formatStateUsing(fn (EmployeePaymentVoucher $record): string => $record->employee?->fullName() ?? '—'),

                TextColumn::make('amount')
                    ->label(__('payroll.vouchers.columns.amount'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('payment_date')
                    ->label(__('payroll.vouchers.columns.date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('paymentAccount.name')
                    ->label(__('payroll.vouchers.columns.account'))
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('payroll.vouchers.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payroll.vouchers.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (EmployeePaymentVoucher $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (EmployeePaymentVoucher $record): bool => ! $record->isDraft()),

                Action::make('reverse')
                    ->label(__('payroll.vouchers.actions.reverse'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (EmployeePaymentVoucher $record): bool => $record->isApproved())
                    ->requiresConfirmation()
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('payroll.vouchers.actions.reversal_date'))
                            ->native(false)
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function (EmployeePaymentVoucher $record, array $data, EmployeePaymentPoster $poster): void {
                        try {
                            $poster->reverse($record, CarbonImmutable::parse($data['date']), Filament::auth()->id());
                        } catch (VoucherRejected|PostingRejected $refusal) {
                            Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title(__('payroll.vouchers.actions.reversed'))->success()->send();
                    }),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEmployeePaymentVouchers::route('/'),
            'create' => CreateEmployeePaymentVoucher::route('/create'),
            'edit' => EditEmployeePaymentVoucher::route('/{record}/edit'),
            'view' => ViewEmployeePaymentVoucher::route('/{record}'),
        ];
    }
}
