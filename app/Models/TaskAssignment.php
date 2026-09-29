<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskAssignment extends Model
{
    protected $primaryKey = 'task_assignment_id';

    public $timestamps = false;

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
}
