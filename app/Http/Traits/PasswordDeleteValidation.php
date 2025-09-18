<?php

namespace App\Http\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

trait PasswordDeleteValidation
{
    /**
     * Validate the password for destructive actions (like delete).
     * Returns the user if valid, or a JSON response if invalid.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Auth\Authenticatable|\Illuminate\Http\JsonResponse
     */
    public function validateDeletePassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);
        $user = $request->user();
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid password'], 403);
        }
        return $user;
    }
}
