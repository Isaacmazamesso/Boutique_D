<?php

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Request;

if (!function_exists('activity_log')) {
    function activity_log(int $userId, string $action, ?string $modelType = null, ?int $modelId = null, array $details = []): void
    {
        ActivityLog::create([
            'user_id'    => $userId,
            'action'     => $action,
            'model_type' => $modelType,
            'model_id'   => $modelId,
            'details'    => $details ?: null,
            'ip_address' => Request::ip(),
            'device'     => Request::userAgent(),
        ]);
    }
}
