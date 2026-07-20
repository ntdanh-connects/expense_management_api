<?php

namespace App\Services;

use App\Models\ActivityLog;

class ActivityLogService
{
    /**
     * Log a user activity.
     *
     * @param string $action
     * @param string $description
     * @param string|null $userId
     * @return void
     */
    public static function log(string $action, string $description, ?string $userId = null): void
    {
        try {
            // If userId is not provided, try to resolve it
            if (!$userId) {
                $userId = request()->attributes->get('user_id')
                    ?? request()->header('X-User-Id');
            }

            if (!$userId && auth()->check()) {
                $userId = auth()->user()->user_id;
            }

            // If we still don't have a userId, skip logging to avoid database constraint errors
            if (!$userId) {
                return;
            }

            ActivityLog::create([
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'ip_address' => request()->ip(),
                'user_agent' => request()->header('User-Agent')
            ]);
        } catch (\Throwable $e) {
            // Fail silently but log to laravel logs to avoid crashing main flow
            \Illuminate\Support\Facades\Log::error("Failed to write activity log: " . $e->getMessage());
        }
    }
}
