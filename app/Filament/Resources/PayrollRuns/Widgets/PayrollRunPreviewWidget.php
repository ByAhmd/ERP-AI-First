<?php

declare(strict_types=1);

namespace App\Filament\Resources\PayrollRuns\Widgets;

use App\Models\PayrollRun;
use App\Services\Payroll\PayrollRunEngine;
use Filament\Widgets\Widget;

/**
 * What approving the draft would pay — advisory; the posted figures come
 * from the approval's own computation under lock.
 */
class PayrollRunPreviewWidget extends Widget
{
    protected string $view = 'filament.payroll.run-preview-widget';

    public ?PayrollRun $record = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        if ($this->record === null || ! $this->record->isDraft()) {
            return ['rows' => [], 'total' => '0'];
        }

        try {
            $preview = app(PayrollRunEngine::class)->preview($this->record);
        } catch (\Throwable) {
            return ['rows' => [], 'total' => '0'];
        }

        return ['rows' => $preview['rows'], 'total' => $preview['total_net']];
    }
}
