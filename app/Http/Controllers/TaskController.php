<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Services\GoogleCalendarService;

class TaskController extends Controller
{
    protected $googleCalendar;

    public function __construct(GoogleCalendarService $googleCalendar)
    {
        $this->googleCalendar = $googleCalendar;
    }

    public function index()
    {
        // Show only user's own tasks for regular users
        $tasks = Task::with('category')
                    ->where('assigned_user_id', Auth::id())
                    ->latest()
                    ->get()
                    ->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'task_name' => $task->task_name,
                            'description' => $task->description,
                            'deadline' => $task->deadline
                                ? Carbon::parse($task->deadline)->format('Y-m-d')
                                : null,
                            'status' => $task->status,
                            'category_name' => $task->category?->name ?? 'Uncategorized',
                            'google_calendar_event_id' => $task->google_calendar_event_id,
                        ];
                    });

        return Inertia::render('task/Index', [
            'tasks' => $tasks
        ]);
    }

    public function create()
    {
        $categories = Category::where('status', 'Active')->get(['id', 'name']);

        // If user is admin, show all users for assignment
        $users = Auth::user()->isAdmin()
            ? User::where('id', '!=', Auth::id())->get(['id', 'name'])
            : collect();

        return Inertia::render('task/Create', [
            'categories' => $categories,
            'users' => $users,
            'isAdmin' => Auth::user()->isAdmin(),
            'googleCalendarConnected' => $this->googleCalendar->isConnected(),
            'googleCalendarConfigured' => $this->googleCalendar->isConfigured(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'task_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'deadline' => 'required|date|after:today',
            'status' => 'required|in:Pending,In Progress,Completed',
            'category_id' => 'nullable|exists:categories,id',
            'assigned_user_id' => Auth::user()->isAdmin() ? 'required|exists:users,id' : 'nullable'
        ]);

        // Convert deadline to proper format
        $data['deadline'] = Carbon::parse($data['deadline'])->format('Y-m-d H:i:s');

        // Set assigned user - admin can assign to others, regular users can only create for themselves
        $data['assigned_user_id'] = Auth::user()->isAdmin()
            ? $data['assigned_user_id']
            : Auth::id();

        // Create the task
        $task = Task::create($data);

        // Sync with Google Calendar if connected and configured
        if ($this->googleCalendar->isConnected() && $this->googleCalendar->isConfigured()) {
            try {
                $eventData = $task->getGoogleCalendarEventData();
                $eventId = $this->googleCalendar->createEvent($eventData);

                if ($eventId) {
                    $task->update(['google_calendar_event_id' => $eventId]);

                    // Add success message for sync
                    $message = 'Task added and synced with Google Calendar successfully!';
                } else {
                    // Add warning message for sync failure
                    $message = 'Task added successfully but failed to sync with Google Calendar.';
                }
            } catch (\Exception $e) {
                \Log::error('Google Calendar sync error: ' . $e->getMessage());
                $message = 'Task added successfully but Google Calendar sync failed.';
            }
        } else {
            $message = 'Task added successfully!';

            // Add info message if not connected to Google Calendar
            if (!$this->googleCalendar->isConnected()) {
                $message .= ' Connect to Google Calendar to enable automatic sync.';
            }
        }

        // Redirect admin to admin task index, regular users to their task index
        if (Auth::user()->isAdmin()) {
            return redirect()->route('task.admin.index')->with('message', $message);
        }

        return redirect()->route('task.index')->with('message', $message);
    }

    public function edit(Task $task)
    {
        // Authorization - users can only edit their own tasks unless admin
        if ($task->assigned_user_id !== Auth::id() && !Auth::user()->isAdmin()) {
            abort(403);
        }

        $categories = Category::where('status', 'Active')->get(['id', 'name']);
        $users = Auth::user()->isAdmin()
            ? User::where('id', '!=', Auth::id())->get(['id', 'name'])
            : collect();

        // Format deadline for the form
        $task->deadline = Carbon::parse($task->deadline)->format('Y-m-d');

        return Inertia::render('task/Edit', [
            'task' => $task,
            'categories' => $categories,
            'users' => $users,
            'isAdmin' => Auth::user()->isAdmin(),
            'googleCalendarConnected' => $this->googleCalendar->isConnected(),
            'googleCalendarConfigured' => $this->googleCalendar->isConfigured(),
        ]);
    }

    public function update(Request $request, Task $task)
    {
        // Authorization
        if ($task->assigned_user_id !== Auth::id() && !Auth::user()->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'task_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'deadline' => 'required|date|after:today',
            'status' => 'required|in:Pending,In Progress,Completed',
            'category_id' => 'nullable|exists:categories,id',
            'assigned_user_id' => Auth::user()->isAdmin() ? 'required|exists:users,id' : 'nullable'
        ]);

        // Convert deadline
        $data['deadline'] = Carbon::parse($data['deadline'])->format('Y-m-d H:i:s');

        // Only admin can change assigned user
        if (!Auth::user()->isAdmin()) {
            unset($data['assigned_user_id']);
        }

        // Store old event ID for update
        $oldEventId = $task->google_calendar_event_id;

        $task->update($data);

        // Sync with Google Calendar if connected and configured
        if ($this->googleCalendar->isConnected() && $this->googleCalendar->isConfigured()) {
            try {
                $eventData = $task->getGoogleCalendarEventData();

                if ($oldEventId) {
                    // Update existing event
                    $success = $this->googleCalendar->updateEvent($oldEventId, $eventData);

                    if ($success) {
                        $message = 'Task updated and Google Calendar event synced successfully!';
                    } else {
                        $message = 'Task updated successfully but failed to sync with Google Calendar.';
                    }
                } else {
                    // Create new event
                    $eventId = $this->googleCalendar->createEvent($eventData);
                    if ($eventId) {
                        $task->update(['google_calendar_event_id' => $eventId]);
                        $message = 'Task updated and synced with Google Calendar successfully!';
                    } else {
                        $message = 'Task updated successfully but failed to sync with Google Calendar.';
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Google Calendar sync error: ' . $e->getMessage());
                $message = 'Task updated successfully but Google Calendar sync failed.';
            }
        } else {
            $message = 'Task updated successfully!';

            // Add info message if not connected to Google Calendar
            if (!$this->googleCalendar->isConnected()) {
                $message .= ' Connect to Google Calendar to enable automatic sync.';
            }
        }

        // Redirect admin to admin task index, regular users to their task index
        if (Auth::user()->isAdmin()) {
            return redirect()->route('task.admin.index')->with('message', $message);
        }

        return redirect()->route('task.index')->with('message', $message);
    }

    public function destroy(Task $task)
    {
        // Authorization
        if ($task->assigned_user_id !== Auth::id() && !Auth::user()->isAdmin()) {
            abort(403);
        }

        $calendarEventId = $task->google_calendar_event_id;

        // Delete from Google Calendar if connected, configured and event exists
        if ($this->googleCalendar->isConnected() && $this->googleCalendar->isConfigured() && $calendarEventId) {
            try {
                $this->googleCalendar->deleteEvent($calendarEventId);
                $message = 'Task and Google Calendar event deleted successfully!';
            } catch (\Exception $e) {
                \Log::error('Google Calendar delete error: ' . $e->getMessage());
                $message = 'Task deleted successfully but failed to delete Google Calendar event.';
            }
        } else {
            $message = 'Task deleted successfully!';
        }

        $task->delete();

        // Redirect admin to admin task index, regular users to their task index
        if (Auth::user()->isAdmin()) {
            return redirect()->route('task.admin.index')->with('message', $message);
        }

        return redirect()->route('task.index')->with('message', $message);
    }

    // Admin method to view all tasks
    public function adminIndex()
    {
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        $tasks = Task::with(['user', 'category'])
                    ->latest()
                    ->get()
                    ->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'task_name' => $task->task_name,
                            'description' => $task->description,
                            'deadline' => $task->deadline
                                ? Carbon::parse($task->deadline)->format('Y-m-d')
                                : null,
                            'status' => $task->status,
                            'assigned_user_name' => $task->user ? $task->user->name : 'Unassigned',
                            'category_name' => $task->category?->name ?? 'Uncategorized',
                            'google_calendar_event_id' => $task->google_calendar_event_id,
                        ];
                    });

        return Inertia::render('task/AdminIndex', [
            'tasks' => $tasks
        ]);
    }

    /**
     * Manually sync a task with Google Calendar
     */
    public function syncWithGoogleCalendar(Task $task)
    {
        // Authorization
        if ($task->assigned_user_id !== Auth::id() && !Auth::user()->isAdmin()) {
            abort(403);
        }

        if (!$this->googleCalendar->isConnected()) {
            return redirect()->back()->with('error', 'Please connect to Google Calendar first.');
        }

        if (!$this->googleCalendar->isConfigured()) {
            return redirect()->back()->with('error', 'Google Calendar is not configured. Please contact administrator.');
        }

        try {
            $eventData = $task->getGoogleCalendarEventData();

            if ($task->google_calendar_event_id) {
                // Update existing event
                $success = $this->googleCalendar->updateEvent($task->google_calendar_event_id, $eventData);
                $action = 'updated';
            } else {
                // Create new event
                $eventId = $this->googleCalendar->createEvent($eventData);
                if ($eventId) {
                    $task->update(['google_calendar_event_id' => $eventId]);
                    $success = true;
                    $action = 'created';
                } else {
                    $success = false;
                }
            }

            if ($success) {
                return redirect()->back()->with('success', "Task successfully synced with Google Calendar! Event {$action}.");
            } else {
                return redirect()->back()->with('error', 'Failed to sync task with Google Calendar.');
            }

        } catch (\Exception $e) {
            \Log::error('Manual Google Calendar sync error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Error syncing with Google Calendar: ' . $e->getMessage());
        }
    }

    /**
     * Remove Google Calendar event for a task (without deleting the task)
     */
    public function removeFromGoogleCalendar(Task $task)
    {
        // Authorization
        if ($task->assigned_user_id !== Auth::id() && !Auth::user()->isAdmin()) {
            abort(403);
        }

        if (!$task->google_calendar_event_id) {
            return redirect()->back()->with('error', 'Task is not synced with Google Calendar.');
        }

        if (!$this->googleCalendar->isConnected()) {
            return redirect()->back()->with('error', 'Please connect to Google Calendar first.');
        }

        try {
            $success = $this->googleCalendar->deleteEvent($task->google_calendar_event_id);

            if ($success) {
                $task->update(['google_calendar_event_id' => null]);
                return redirect()->back()->with('success', 'Task removed from Google Calendar successfully!');
            } else {
                return redirect()->back()->with('error', 'Failed to remove task from Google Calendar.');
            }

        } catch (\Exception $e) {
            \Log::error('Remove from Google Calendar error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Error removing from Google Calendar: ' . $e->getMessage());
        }
    }
}
