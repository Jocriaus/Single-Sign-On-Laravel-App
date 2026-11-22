<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Sso\Accounts\UserClaims;
use App\Sso\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success('Profile loaded.', [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'userType' => UserClaims::userType($user),
            'roles' => UserClaims::roles($user),
            'name' => UserClaims::displayName($user),
            'firstName' => $user->first_name,
            'middleName' => $user->middle_name,
            'middleInitial' => $user->middle_initial,
            'lastName' => $user->last_name,
            'suffix' => $user->suffix,
            'studentNumber' => $user->student_no,
            'employeeNumber' => $user->employee_no,
        ]);
    }
}
