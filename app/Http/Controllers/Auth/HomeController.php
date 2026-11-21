<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $name = trim(trim((string) $user->first_name).' '.trim((string) $user->last_name));

        return view('home', [
            'displayName' => $name !== '' ? $name : $user->username,
            'username' => $user->username,
            'isAdmin' => $user->hasRole('ICTO_ADMIN', 'ADMIN'),
        ]);
    }
}
