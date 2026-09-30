<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $primaryKey = 'payment_id';

    /** Expenditure workflow statuses. */
    public const STATUS_PENDING = 'Pending';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_COMPLETED = 'Completed';

    public const STATUS_CANCELLED = 'Cancelled';

    protected $fillable = [
        'project_id',
        'phase_id',
        'task_id',
        'amount',
        'payment_date',
        'recipient',
        'payment_status',
        'reference_number',
        'description',
        'sop_process',
        'created_by',
        'approved_by',
        'approved_at',
        'disbursed_by',
        'disbursed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function phase()
    {
        return $this->belongsTo(Phase::class, 'phase_id', 'phase_id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id', 'task_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function disburser()
    {
        return $this->belongsTo(User::class, 'disbursed_by', 'user_id');
    }

    public function isCompleted(): bool
    {
        return $this->payment_status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->payment_status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->payment_status === self::STATUS_APPROVED;
    }
}
