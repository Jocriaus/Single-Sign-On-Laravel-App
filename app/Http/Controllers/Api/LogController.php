<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Sso\Api\ApiResponse;
use App\Sso\Api\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::query()->with(['user' => fn ($q) => $q->withTrashed()])->orderByDesc('id');

        return ApiResponse::success('Activity loaded.', Paging::of($query, $request, fn (ActivityLog $log) => [
            'id' => $log->id,
            'userId' => $log->user_id,
            'username' => $log->user?->username,
            'activity' => $log->activity,
            'activityType' => $log->activity_type,
            'module' => $log->module,
            'createdAt' => $log->created_at?->toIso8601String(),
        ]));
    }
}
