<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Enums\AccountType;
use App\Enums\AssetAcquisitionKind;
use App\Enums\FixedAssetStatus;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\FixedAssetType;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Assets\Exceptions\AssetRuleViolation;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates registered assets — the module's only birth door in this slice.
 *
 * Two acquisition kinds:
 *
 * - Opening, in two modes. Register-only creates the row and posts nothing —
 *   the bridge for tenants whose 1210/1220 balances already stand in the
 *   ledger; without it the register–GL tie report would be red from day one.
 *   The posting mode is for balances not yet in the GL: cost against the
 *   opening-balance suspense, net of any opening accumulated depreciation.
 *   For openings the acquisition date is the BOOKING date of that entry —
 *   Qoyod's التاريخ on its opening-balance document — and must fall in an
 *   open period; the in-service date carries the historical start.
 *
 * - Manual purchase: cost (plus recoverable VAT) against a payment account.
 *   A deliberate deviation from Qoyod, whose manual path is a bare journal
 *   entry that bypasses its register — the tie invariant here demands the
 *   register see everything that reaches the asset accounts through the
 *   module. Credited to a payment account, never to a fabricated payable.
 *
 * Accounts resolve from the TYPE's stored accounts; the keyed system accounts
 * are only the type form's defaults.
 */
final class FixedAssetRegistrar
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'fixed_asset',
            defaults: ['prefix' => 'FA-', 'padding' => 5],
        ));
    }

    /**
     * Guard a type's three accounts — postable and of the right kind.
     *
     * Called on every registration, and by the type form on save.
     */
    public function guardTypeAccounts(FixedAssetType $type): void
    {
        $expectations = [
            [$type->assetAccount()->firstOrFail(), AccountType::Asset],
            [$type->accumulatedDepreciationAccount()->firstOrFail(), AccountType::Asset],
            [$type->depreciationExpenseAccount()->firstOrFail(), AccountType::Expense],
        ];

        foreach ($expectations as [$account, $expected]) {
            if (! $account->acceptsPostings()) {
                throw AssetRuleViolation::accountNotPostable($account);
            }

            if ($account->type !== $expected) {
                throw AssetRuleViolation::accountTypeMismatch($account, $expected->getLabel());
            }
        }
    }

    /**
     * Register an asset from validated form data.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, ?string $userId = null): FixedAsset
    {
        return DB::transaction(function () use ($data, $userId): FixedAsset {
            $type = FixedAssetType::query()->findOrFail($data['fixed_asset_type_id']);

            $this->guardTypeAccounts($type);

            $kind = AssetAcquisitionKind::from((string) $data['acquisition_kind']);

            $cost = $this->normalise((string) $data['cost']);
            $salvage = $this->normalise((string) ($data['salvage_value'] ?? '0'));
            $openingAccumulated = $kind === AssetAcquisitionKind::Opening
                ? $this->normalise((string) ($data['opening_accumulated_depreciation'] ?? '0'))
                : '0.0000';

            $this->guardFigures($type, $kind, $cost, $salvage, $openingAccumulated, $data);

            $asset = FixedAsset::create([
                'fixed_asset_type_id' => $type->getKey(),
                'reference' => $this->numbers->next(
                    key: 'fixed_asset',
                    defaults: ['prefix' => 'FA-', 'padding' => 5],
                ),
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'description' => $data['description'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'branch_id' => $data['branch_id'],
                'status' => FixedAssetStatus::Active,
                'acquisition_kind' => $kind,
                'acquisition_date' => $data['acquisition_date'],
                'in_service_date' => $data['in_service_date'],
                'cost' => $cost,
                'salvage_value' => $salvage,
                'useful_life_months' => $type->is_depreciable ? (int) $data['useful_life_months'] : null,
                'is_depreciable' => $type->is_depreciable,
                'opening_accumulated_depreciation' => $openingAccumulated,
                'opening_depreciated_through' => $kind === AssetAcquisitionKind::Opening
                    ? ($data['opening_depreciated_through'] ?? null)
                    : null,
            ]);

            $entry = match (true) {
                $kind === AssetAcquisitionKind::Purchase => $this->postPurchase($asset, $type, $data, $userId),
                $kind === AssetAcquisitionKind::Opening && ! ($data['register_only'] ?? false) => $this->postOpening($asset, $type, $userId),
                default => null,
            };

            if ($entry !== null) {
                $asset->forceFill(['acquisition_journal_entry_id' => $entry->getKey()])->save();
            }

            return $asset->refresh();
        });
    }

    private function postOpening(FixedAsset $asset, FixedAssetType $type, ?string $userId): \App\Models\JournalEntry
    {
        $cost = (string) $asset->cost;
        $accumulated = (string) $asset->opening_accumulated_depreciation;
        $net = bcsub($cost, $accumulated, self::SCALE);

        $lines = [
            JournalLineData::debit((string) $type->asset_account_id, $cost),
        ];

        if (bccomp($accumulated, '0', self::SCALE) > 0) {
            $lines[] = JournalLineData::credit((string) $type->accumulated_depreciation_account_id, $accumulated);
        }

        if (bccomp($net, '0', self::SCALE) > 0) {
            $lines[] = JournalLineData::credit(
                $this->registry->get(SystemAccount::OpeningBalanceSuspense)->getKey(),
                $net,
            );
        }

        return $this->post($asset, $lines, __('assets.opening.narration', [
            'reference' => $asset->reference,
            'name' => $asset->name,
        ]), $userId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postPurchase(FixedAsset $asset, FixedAssetType $type, array $data, ?string $userId): \App\Models\JournalEntry
    {
        $account = Account::query()->findOrFail($data['payment_account_id'] ?? null);

        if (! $account->is_payment_account) {
            throw AssetRuleViolation::notPaymentAccount($account);
        }

        if (! $account->acceptsPostings()) {
            throw AssetRuleViolation::accountNotPostable($account);
        }

        $cost = (string) $asset->cost;
        $tax = '0.0000';

        if (($data['tax_id'] ?? null) !== null) {
            $rate = Tax::query()->findOrFail($data['tax_id']);

            $tax = bcadd((string) BigRational::of($cost)
                ->multipliedBy(BigRational::of($rate->fraction()))
                ->toScale(2, RoundingMode::HalfUp), '0', self::SCALE);
        }

        $lines = [
            JournalLineData::debit((string) $type->asset_account_id, $cost),
        ];

        if (bccomp($tax, '0', self::SCALE) > 0) {
            $lines[] = JournalLineData::debit(
                $this->registry->get(SystemAccount::VatInputRecoverable)->getKey(),
                $tax,
            );
        }

        $lines[] = JournalLineData::credit($account->getKey(), bcadd($cost, $tax, self::SCALE));

        return $this->post($asset, $lines, __('assets.acquisition.narration', [
            'reference' => $asset->reference,
            'name' => $asset->name,
        ]), $userId);
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function post(FixedAsset $asset, array $lines, string $narration, ?string $userId): \App\Models\JournalEntry
    {
        $lines = array_map(
            fn (JournalLineData $line): JournalLineData => $line->withBranch($asset->branch_id),
            $lines,
        );

        return $this->poster->post(
            date: $asset->acquisition_date,
            lines: $lines,
            description: $narration,
            reference: $asset->reference,
            source: $asset,
            userId: $userId,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardFigures(
        FixedAssetType $type,
        AssetAcquisitionKind $kind,
        string $cost,
        string $salvage,
        string $openingAccumulated,
        array $data,
    ): void {
        if (bccomp($salvage, $cost, self::SCALE) >= 0) {
            throw AssetRuleViolation::salvageExceedsCost();
        }

        $base = bcsub($cost, $salvage, self::SCALE);

        if (bccomp($openingAccumulated, $base, self::SCALE) > 0) {
            throw AssetRuleViolation::openingAccumulatedTooLarge();
        }

        if (bccomp($openingAccumulated, '0', self::SCALE) > 0
            && ($data['opening_depreciated_through'] ?? null) === null) {
            throw AssetRuleViolation::openingAccumulatedNeedsDate();
        }

        if ($type->is_depreciable && (int) ($data['useful_life_months'] ?? 0) < 1) {
            throw AssetRuleViolation::lifeRequired();
        }

        if ($kind === AssetAcquisitionKind::Purchase
            && bccomp($this->normalise((string) ($data['opening_accumulated_depreciation'] ?? '0')), '0', self::SCALE) > 0) {
            throw AssetRuleViolation::purchaseCarriesNoAccumulated();
        }
    }

    private function normalise(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
