<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return redirect('/login');
        }

        $user = Auth::user();

        // Debug: Check if method exists and user data
        if (!method_exists($user, 'isAdmin')) {
            \Log::error('isAdmin method not found on User model', [
                'user_id' => $user->id,
                'user_class' => get_class($user)
            ]);

            // Fallback: Check role directly
            if (isset($user->role) && $user->role === 'admin') {
                return $next($request);
            }

            return redirect('/dashboard')->with('error', 'Admin method not available.');
        }

        // Check if user is admin
        if (!$user->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized access. Admin privileges required.');
        }

        return $next($request);
    }
}
