<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Sso\Accounts\UserClaims;
use App\Sso\OAuth\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserInfoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $granted = array_values(array_filter(Scopes::ALLOWED, fn (string $scope) => $user->tokenCan($scope)));

        return response()->json(UserClaims::forUserInfo($user, $granted))
            ->header('Cache-Control', 'no-store');
    }
}
