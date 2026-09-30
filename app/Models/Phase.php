<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Phase extends Model
{
    protected $primaryKey = 'phase_id';

    public $timestamps = false;

    protected $fillable = [
        'project_id', 'phase_name', 'start_date', 'end_date', 'duration', 'status', 'sequence_order',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'phase_id', 'phase_id');
    }

    public function budget()
    {
        return $this->hasOne(PhaseBudget::class, 'phase_id', 'phase_id');
    }

    public function allocatedTaskAmount(): float
    {
        return (float) $this->tasks()->sum('budget');
    }

    public function remainingTaskBudget(): float
    {
        $allocated = (float) ($this->budget?->allocated_amount ?? 0);
        $spent = (float) ($this->budget?->spent_amount ?? 0);

        return max(0, $allocated - $spent - $this->allocatedTaskAmount());
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'phase_id', 'phase_id');
    }

    /**
     * Phase funds still available for new expenditures. Reserved covers every
     * payment that is not Cancelled (completed spend plus submissions pending
     * approval or disbursement) so a worker cannot over-commit the budget
     * while an approval is in flight.
     */
    public function remainingExpenseBudget(): float
    {
        $allocated = (float) ($this->budget?->allocated_amount ?? 0);
        $reserved = (float) $this->payments()
            ->whereIn('payment_status', ['Pending', 'Approved', 'Completed'])
            ->sum('amount');

        return max(0, $allocated - $reserved);
    }

    public function totalPayments(): float
    {
        return (float) $this->payments()->where('payment_status', 'Completed')->sum('amount');
    }
}
