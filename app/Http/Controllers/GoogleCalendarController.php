<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class GoogleCalendarController extends Controller
{
    protected $google;

    public function __construct(GoogleCalendarService $google)
    {
        $this->google = $google;
    }

    public function connect()
    {
        if (!$this->google->isConfigured()) {
            return redirect()->back()->with('error', 'Google Calendar is not configured.');
        }

        if ($this->google->isConnected()) {
            return redirect()->back()->with('message', 'Already connected!');
        }

        return redirect()->away($this->google->getAuthUrl());
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('task.index')->with('error', 'Access denied.');
        }

        if (!$request->has('code')) {
            return redirect()->route('task.index')->with('error', 'No code returned.');
        }

        if ($this->google->handleCallback($request->code)) {
            return redirect()->route('task.index')->with('success', 'Google Calendar connected!');
        }

        return redirect()->route('task.index')->with('error', 'Failed to connect to Google Calendar.');
    }

    public function disconnect()
    {
        $this->google->disconnect();
        return redirect()->route('task.index')->with('success', 'Disconnected successfully!');
    }

    public function status()
    {
        return response()->json([
            'connected' => $this->google->isConnected(),
            'configured' => $this->google->isConfigured(),
        ]);
    }
}
