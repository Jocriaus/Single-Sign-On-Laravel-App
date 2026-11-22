<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function __invoke(Request $request)
    {
        return view('admin.overview', [
            'userCount' => User::count(),
            'disabledCount' => User::where('status_flag', false)->count(),
            'lockedCount' => User::where('account_locked', true)->count(),
            'mustChangeCount' => User::where('must_change_password', true)->count(),
            'appCount' => $request->user()->hasRole('ICTO_ADMIN') ? Client::count() : null,
            'activity' => ActivityLog::with('user')->latest('created_at')->latest('id')->limit(20)->get(),
        ]);
    }
}
