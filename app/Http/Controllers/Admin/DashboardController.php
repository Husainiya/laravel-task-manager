<?php
// app/Http/Controllers/Admin/DashboardController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\Category;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $totalTasks = Task::count();
        $completedTasks = Task::where('status', 'Completed')->count();
        $pendingTasks = Task::where('status', 'Pending')->count();
        $overdueTasks = Task::where('deadline', '<', now())
            ->where('status', '!=', 'Completed')
            ->count();
        $totalUsers = User::count();
        $totalCategories = Category::count();

        // Calculate task completion rate
        $taskCompletionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

        // Calculate user engagement (users with active tasks in last 7 days)
        // FIXED: Changed 'tasks' to 'assignedTasks'
        $activeUsers = User::whereHas('assignedTasks', function ($query) {
            $query->where('updated_at', '>=', now()->subDays(7));
        })->count();

        $userEngagement = $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100) : 0;

        // Recent activities (you might want to create an Activity model for this)
        $recentActivities = $this->getRecentActivities();

        // Upcoming tasks (next 7 days)
        $upcomingTasks = Task::with('user')
            ->where('deadline', '>=', now())
            ->where('deadline', '<=', now()->addDays(7))
            ->where('status', '!=', 'Completed')
            ->orderBy('deadline')
            ->limit(5)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'task_name' => $task->task_name,
                    'assigned_user' => $task->user->name,
                    'deadline' => Carbon::parse($task->deadline)->format('M j, Y'),
                    'status' => $task->status,
                    'priority' => $task->priority ?? 'Medium',
                ];
            });

        return Inertia::render('Admin/Dashboard', [
            'totalTasks' => $totalTasks,
            'completedTasks' => $completedTasks,
            'pendingTasks' => $pendingTasks,
            'overdueTasks' => $overdueTasks,
            'totalUsers' => $totalUsers,
            'totalCategories' => $totalCategories,
            'taskCompletionRate' => $taskCompletionRate,
            'userEngagement' => $userEngagement,
            'recentActivities' => $recentActivities,
            'upcomingTasks' => $upcomingTasks,
        ]);
    }

    private function getRecentActivities()
    {
        // This is a simplified version. You might want to create an Activity model
        // to track user activities in your system
        $recentTasks = Task::with('user')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'user' => $task->user->name,
                    'action' => 'created task',
                    'target' => $task->task_name,
                    'time' => Carbon::parse($task->created_at)->diffForHumans(),
                    'type' => 'task',
                ];
            });

        return $recentTasks->toArray();
    }
}
