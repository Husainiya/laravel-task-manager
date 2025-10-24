<?php

namespace App\Services;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GoogleCalendarService
{
    protected Client $client;
    protected Calendar $calendarService;
    protected string $calendarId;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setApplicationName(config('app.name'));
        $this->client->setScopes([Calendar::CALENDAR_EVENTS]);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->setIncludeGrantedScopes(true);

        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));

        $this->calendarService = new Calendar($this->client);
        $this->calendarId = config('services.google.calendar_id', 'primary');
    }

    public function isConfigured(): bool
    {
        return !empty(config('services.google.client_id')) &&
               !empty(config('services.google.client_secret'));
    }

    public function isConnected(): bool
    {
        $user = Auth::user();
        if (!$user || !$user->google_access_token) return false;

        $token = json_decode($user->google_access_token, true);
        $this->client->setAccessToken($token);

        // Token expired → refresh it
        if ($this->client->isAccessTokenExpired()) {
            $refreshToken = $token['refresh_token'] ?? null;
            if ($refreshToken) {
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
                $newToken['refresh_token'] = $refreshToken;
                $user->google_access_token = json_encode($newToken);
                $user->save();
                $this->client->setAccessToken($newToken);
                return true;
            }
            return false;
        }

        return true;
    }

    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    public function handleCallback(string $code): bool
    {
        try {
            $token = $this->client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                Log::error('Google OAuth error: ' . $token['error']);
                return false;
            }

            // Keep refresh_token safe
            $existing = Auth::user()->google_access_token;
            if ($existing) {
                $existing = json_decode($existing, true);
                if (isset($existing['refresh_token']) && !isset($token['refresh_token'])) {
                    $token['refresh_token'] = $existing['refresh_token'];
                }
            }

            $user = Auth::user();
            $user->google_access_token = json_encode($token);
            $user->save();

            $this->client->setAccessToken($token);
            return true;
        } catch (\Exception $e) {
            Log::error('Google callback error: ' . $e->getMessage());
            return false;
        }
    }

    public function createEvent(array $data): ?string
    {
        if (!$this->isConnected()) return null;

        try {
            $event = new Event([
                'summary' => $data['title'] ?? 'No Title',
                'description' => $data['description'] ?? '',
                'start' => ['dateTime' => Carbon::parse($data['start_time'])->toRfc3339String()],
                'end' => ['dateTime' => Carbon::parse($data['end_time'])->toRfc3339String()],
            ]);

            $created = $this->calendarService->events->insert($this->calendarId, $event);
            return $created->getId();
        } catch (\Exception $e) {
            Log::error('Google Calendar create event error: ' . $e->getMessage());
            return null;
        }
    }

    public function getEvents(): ?array
    {
        if (!$this->isConnected()) return null;

        try {
            $events = $this->calendarService->events->listEvents($this->calendarId, [
                'maxResults' => 5,
                'orderBy' => 'startTime',
                'singleEvents' => true,
                'timeMin' => Carbon::now()->toRfc3339String(),
            ]);

            $list = [];
            foreach ($events->getItems() as $event) {
                $list[] = [
                    'id' => $event->getId(),
                    'summary' => $event->getSummary(),
                    'start' => $event->getStart()->getDateTime(),
                    'end' => $event->getEnd()->getDateTime(),
                ];
            }

            return $list;
        } catch (\Exception $e) {
            Log::error('Get events failed: ' . $e->getMessage());
            return null;
        }
    }

    public function disconnect(): bool
    {
        $user = Auth::user();
        if ($user) {
            $user->google_access_token = null;
            $user->save();
        }
        return true;
    }
}
