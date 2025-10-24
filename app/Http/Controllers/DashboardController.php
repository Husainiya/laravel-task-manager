<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function dashboard() // 
    {
        // Get the authenticated user
        $user = Auth::user();

        // Calculate task stats for the current user
        $taskStats = [
            'total' => $user->assignedTasks()->count(),
            'pending' => $user->assignedTasks()->where('status', 'Pending')->count(),
            'in_progress' => $user->assignedTasks()->where('status', 'In Progress')->count(),
            'completed' => $user->assignedTasks()->where('status', 'Completed')->count(),
        ];

        // Get upcoming deadlines (next 7 days)
        $upcomingDeadlines = $user->assignedTasks()
            ->where('deadline', '>=', now())
            ->where('deadline', '<=', now()->addDays(7))
            ->where('status', '!=', 'Completed')
            ->with('category')
            ->orderBy('deadline')
            ->get()
            ->map(function ($task) {
                $daysRemaining = now()->diffInDays(Carbon::parse($task->deadline), false);

                return [
                    'id' => $task->id,
                    'task_name' => $task->task_name,
                    'deadline' => Carbon::parse($task->deadline)->format('M j, Y'),
                    'category_name' => $task->category->name ?? 'Uncategorized',
                    'days_remaining' => $daysRemaining >= 0 ? $daysRemaining : 0,
                ];
            });

        // Get recent tasks
        $recentTasks = $user->assignedTasks()
            ->with('category')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'task_name' => $task->task_name,
                    'status' => $task->status,
                    'created_at' => $task->created_at->format('M j, Y'),
                    'category_name' => $task->category->name ?? 'Uncategorized',
                ];
            });

        // Tasks by status for distribution
        $tasksByStatus = $user->assignedTasks()
            ->groupBy('status')
            ->selectRaw('status, count(*) as count')
            ->pluck('count', 'status')
            ->toArray();

        return Inertia::render('Dashboard', [
            'taskStats' => $taskStats,
            'upcomingDeadlines' => $upcomingDeadlines,
            'recentTasks' => $recentTasks,
            'tasksByStatus' => $tasksByStatus,
        ]);
    }
}
