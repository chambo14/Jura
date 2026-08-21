<?php

namespace App\Models;

use App\Enums\FlashItemType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $flash_report_id
 * @property int|null $task_id
 * @property FlashItemType $type
 * @property string $libelle
 * @property CarbonInterface|null $date_ref
 * @property string|null $statut_libelle
 * @property int $ordre
 * @property-read FlashReport $flashReport
 * @property-read Task|null $task
 */
#[Fillable(['flash_report_id', 'task_id', 'type', 'libelle', 'date_ref', 'statut_libelle', 'ordre'])]
class FlashReportItem extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => FlashItemType::class,
            'date_ref' => 'date',
        ];
    }

    /** @return BelongsTo<FlashReport, $this> */
    public function flashReport(): BelongsTo
    {
        return $this->belongsTo(FlashReport::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Préfixe daté tel qu'écrit sur les slides : "Lundi 10 août 2026 : ...".
     */
    public function datePrefix(): ?string
    {
        return $this->date_ref?->translatedFormat('l j F Y');
    }
}
