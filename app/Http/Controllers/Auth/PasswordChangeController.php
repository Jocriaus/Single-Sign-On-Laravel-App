<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Sso\Accounts\PasswordPolicy;
use App\Sso\Activity;
use App\Sso\LocalRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasswordChangeController extends Controller
{
    public function show(Request $request)
    {
        return view('change-password', [
            'required' => (bool) $request->user()->must_change_password,
            'problems' => session('problems', []),
            'minLength' => PasswordPolicy::MIN_LENGTH,
        ]);
    }

    public function update(Request $request, PasswordPolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string'],
            'new_password_confirmation' => ['required', 'string'],
        ]);
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->fail(['Your current password is wrong.']);
        }
        if ($problems = $policy->problems($data['new_password'], $user->username)) {
            return $this->fail($problems);
        }
        if ($data['new_password'] !== $data['new_password_confirmation']) {
            return $this->fail(["The two new passwords don't match."]);
        }
        if ($data['new_password'] === $data['current_password']) {
            return $this->fail(["Choose a password you haven't just used."]);
        }

        $user->forceFill([
            'password' => $data['new_password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
        Activity::log($user, 'Changed password', Activity::UPDATE);
        $request->session()->regenerate();

        return LocalRedirect::intended()->with('status', 'Your password has been changed.');
    }

    private function fail(array $messages): RedirectResponse
    {
        return redirect()->route('password.change')->with('problems', $messages);
    }
}
