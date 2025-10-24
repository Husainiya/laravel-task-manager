<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Task extends Model
{
    protected $fillable = [
        'task_name',
        'description',
        'deadline',
        'status',
        'assigned_user_id',
        'category_id',
        'google_calendar_event_id',
    ];

    protected $casts = [
        'deadline' => 'datetime',
    ];

    // Relationship with user with fallback
    public function user()
    {
        return $this->belongsTo(User::class, 'assigned_user_id')->withDefault([
            'name' => 'Unassigned User',
            'id' => null,
        ]);
    }

    // Relationship with category with fallback
    public function category()
    {
        return $this->belongsTo(Category::class)->withDefault([
            'name' => 'Uncategorized',
            'id' => null,
        ]);
    }

    /**
     * Check if task has Google Calendar event
     */
    public function hasGoogleCalendarEvent(): bool
    {
        return !empty($this->google_calendar_event_id);
    }

    /**
     * Get Google Calendar sync status
     */
    public function getGoogleCalendarSyncStatus(): string
    {
        if (!$this->hasGoogleCalendarEvent()) {
            return 'not_synced';
        }

        // You can add more sophisticated status checks here
        return 'synced';
    }

    /**
     * Get event data for Google Calendar - CORRECTED VERSION
     */
    public function getGoogleCalendarEventData(): array
    {
        // Use the deadline as the start time, and add 1 hour for the end time
        // This makes more sense for task deadlines
        $startTime = $this->deadline->copy();
        $endTime = $this->deadline->copy()->addHours(1);

        // Build description with null safety
        $description = "Task: {$this->task_name}\n";
        $description .= "Description: " . ($this->description ?? 'No description') . "\n";
        $description .= "Status: {$this->status}\n";
        $description .= "Category: " . ($this->category->name ?? 'Uncategorized') . "\n";
        $description .= "Assigned to: " . ($this->user->name ?? 'Unassigned');

        return [
            'title' => $this->task_name,
            'description' => $description,
            'start_time' => $startTime->toRfc3339String(), // Google prefers RFC3339
            'end_time' => $endTime->toRfc3339String(),
        ];
    }

    /**
     * Check if task is overdue
     */
    public function isOverdue(): bool
    {
        return $this->deadline->isPast() && $this->status !== 'Completed';
    }

    /**
     * Get formatted deadline for display
     */
    public function getFormattedDeadlineAttribute(): string
    {
        return $this->deadline->format('M j, Y g:i A');
    }

    /**
     * Get days until deadline
     */
    public function getDaysUntilDeadlineAttribute(): int
    {
        return $this->deadline->diffInDays(now(), false);
    }
}
