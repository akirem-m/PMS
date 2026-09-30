<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskAssignment extends Model
{
    /** Canonical acceptance decisions stored on the pivot. */
    public const STATUS_PENDING = 'Pending Acceptance';

    public const STATUS_ACCEPTED = 'Accepted';

    public const STATUS_REJECTED = 'Rejected';

    protected $primaryKey = 'task_assignment_id';

    protected $fillable = [
        'task_id', 'user_id', 'role_label', 'acceptance_status', 'rejection_reason', 'assigned_by', 'assigned_at', 'responded_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id', 'task_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Lowercase slug for the assignment decision, tolerant of legacy rows
     * that stored 'pending' / 'accepted' / 'rejected' verbatim. This is the
     * single place UI and controllers should compare against.
     */
    public function statusSlug(): string
    {
        return match (strtolower((string) $this->acceptance_status)) {
            'accepted' => 'accepted',
            'rejected' => 'rejected',
            default => 'pending',
        };
    }

    public function isPending(): bool
    {
        return $this->statusSlug() === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->statusSlug() === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->statusSlug() === 'rejected';
    }
}
