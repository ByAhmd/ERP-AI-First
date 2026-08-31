<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Enums\AssetDisposalKind;
use App\Enums\DocumentStatus;
use App\Enums\FixedAssetStatus;
use App\Enums\SystemAccount;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Assets\Exceptions\AssetRuleViolation;
use App\Services\Assets\Exceptions\DisposalRejected;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Approving a disposal — Qoyod's بيع and تخريد, one poster.
 *
 * Order matters and is enforced: the current period's depreciation posts
 * FIRST, as its own catch-up run, so the final partial charge lands in the
 * depreciation expense line of the income statement — skipping it would
 * still balance, with the charge silently reclassified into a smaller loss
 * or larger gain.
 *
 * The disposal entry then clears the POSTED figures: the cost as registered
 * and the accumulated as the opening figure plus the sum of charge rows —
 * never a recomputation, which is also why the removed figures are
 * snapshotted onto the document.
 *
 * A sale is a taxable supply: proceeds arrive gross in the payment account
 * and the VAT goes to output tax, or the return is silently understated.
 *
 * There is no un-dispose and no delete — no counter-document exists, and
 * re-activation would double-count the asset.
 */
final class AssetDisposalPoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
        private readonly DepreciationEngine $engine,
    ) {}

    public function nextReference(AssetDisposalKind $kind): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: $kind === AssetDisposalKind::Sale ? 'asset_sale' : 'asset_scrap',
            defaults: [
                'prefix' => $kind === AssetDisposalKind::Sale ? 'SE-' : 'SC-',
                'padding' => 5,
            ],
        ));
    }

    public function approve(FixedAssetDisposal $disposal, ?string $userId = null): FixedAssetDisposal
    {
        return DB::transaction(function () use ($disposal, $userId): FixedAssetDisposal {
            $locked = FixedAssetDisposal::query()
                ->whereKey($disposal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // The asset lock closes the run-vs-dispose race: a concurrent
            // run holds this row until its charges are in.
            $asset = FixedAsset::query()
                ->whereKey($locked->fixed_asset_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($locked, $asset);

            $disposalDate = CarbonImmutable::instance($locked->disposal_date)->startOfDay();

            // 1. The unregistered depreciation to the disposal date, as its
            // own run — in 5500, never inside the gain/loss.
            $catchup = null;

            if ($asset->is_depreciable && $asset->useful_life_months !== null) {
                $pending = $this->engine->preview($disposalDate, only: $asset);

                if (bccomp($pending['total'], '0', self::SCALE) > 0) {
                    $catchup = $this->engine->run($disposalDate, only: $asset, userId: $userId);
                }
            }

            // 2. Posted figures, read after the catch-up.
            $cost = (string) $asset->cost;
            $accumulated = $asset->accumulatedDepreciation();
            $book = bcsub($cost, $accumulated, self::SCALE);

            $type = $asset->type()->firstOrFail();

            // 3. The disposal entry.
            $proceeds = $locked->kind === AssetDisposalKind::Sale
                ? bcadd((string) ($locked->proceeds ?? '0'), '0', self::SCALE)
                : '0.0000';

            $tax = '0.0000';

            if ($locked->kind === AssetDisposalKind::Sale && $locked->tax_id !== null) {
                $rate = Tax::query()->findOrFail($locked->tax_id);

                $tax = bcadd((string) BigRational::of($proceeds)
                    ->multipliedBy(BigRational::of($rate->fraction()))
                    ->toScale(2, RoundingMode::HalfUp), '0', self::SCALE);
            }

            $gainLoss = bcsub($proceeds, $book, self::SCALE);

            $lines = [];

            if (bccomp($proceeds, '0', self::SCALE) > 0 || bccomp($tax, '0', self::SCALE) > 0) {
                $account = $locked->proceedsAccount()->firstOrFail();

                if (! $account->is_payment_account) {
                    throw AssetRuleViolation::notPaymentAccount($account);
                }

                $lines[] = JournalLineData::debit($account->getKey(), bcadd($proceeds, $tax, self::SCALE));
            }

            if (bccomp($tax, '0', self::SCALE) > 0) {
                $lines[] = JournalLineData::credit(
                    $this->registry->get(SystemAccount::VatOutputPayable)->getKey(),
                    $tax,
                );
            }

            if (bccomp($accumulated, '0', self::SCALE) > 0) {
                $lines[] = JournalLineData::debit((string) $type->accumulated_depreciation_account_id, $accumulated);
            }

            $lines[] = JournalLineData::credit((string) $type->asset_account_id, $cost);

            if (bccomp($gainLoss, '0', self::SCALE) > 0) {
                $lines[] = JournalLineData::credit(
                    $this->registry->get(SystemAccount::GainOnAssetDisposal)->getKey(),
                    $gainLoss,
                );
            } elseif (bccomp($gainLoss, '0', self::SCALE) < 0) {
                $lines[] = JournalLineData::debit(
                    $this->registry->get(SystemAccount::LossOnAssetDisposal)->getKey(),
                    bcmul($gainLoss, '-1', self::SCALE),
                );
            }

            $lines = array_map(
                fn (JournalLineData $line): JournalLineData => $line->withBranch($asset->branch_id),
                $lines,
            );

            $entry = $this->poster->post(
                date: $disposalDate,
                lines: $lines,
                description: trim(__('assets.disposal.narration', [
                    'reference' => $locked->reference,
                    'name' => $asset->name,
                    'kind' => $locked->kind->getLabel(),
                ])),
                reference: $locked->reference,
                source: $locked,
                userId: $userId,
            );

            // 4. Snapshots of what actually posted, then the status flips.
            $asset->forceFill(['status' => FixedAssetStatus::Disposed])->save();

            $locked->forceFill([
                'status' => DocumentStatus::Approved,
                'tax_amount' => $tax,
                'gain_loss_amount' => $gainLoss,
                'cost_removed' => $cost,
                'accumulated_removed' => $accumulated,
                'catchup_run_id' => $catchup?->getKey(),
                'journal_entry_id' => $entry->getKey(),
                'approved_at' => now(),
                'approved_by_id' => $userId,
            ])->save();

            return $locked->refresh();
        });
    }

    private function guard(FixedAssetDisposal $disposal, FixedAsset $asset): void
    {
        if ($disposal->isApproved()) {
            throw DisposalRejected::alreadyApproved($disposal);
        }

        if (! $disposal->isDraft()) {
            throw DisposalRejected::notDraft();
        }

        if ($asset->status !== FixedAssetStatus::Active) {
            throw DisposalRejected::assetNotActive($asset->name);
        }

        if ($disposal->kind === AssetDisposalKind::Sale) {
            if ($disposal->proceeds === null
                || bccomp((string) $disposal->proceeds, '0', self::SCALE) < 0) {
                throw DisposalRejected::proceedsRequired();
            }

            if ($disposal->proceeds_account_id === null
                && bccomp((string) $disposal->proceeds, '0', self::SCALE) > 0) {
                throw DisposalRejected::proceedsAccountRequired();
            }
        }
    }
}
