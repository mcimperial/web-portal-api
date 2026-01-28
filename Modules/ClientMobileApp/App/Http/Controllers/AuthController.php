<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Illuminate\Auth\Events\Registered;

class AuthController extends Controller
{
    /**
     * Handle mobile app login request.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'nullable|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $request->deviceName ?? 'mobile-app';

        // Update user's device_name
        $user->update(['device_name' => $deviceName]);

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'device_name' => $user->device_name,
            ],
        ]);
    }

    /**
     * Handle mobile app logout request.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * Revoke all tokens for the authenticated user.
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out from all devices successfully',
        ]);
    }

    /**
     * Handle mobile app account creation request.
     */
    public function createAccount(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'device_name' => ['nullable', 'string'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),
            'device_name' => $request->device_name ?? 'mobile-app',
        ]);

        event(new Registered($user));

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'device_name' => $user->device_name,
            ],
        ], 201);
    }

    /**
     * Handle social media login/registration (Google, Facebook, Apple).
     */
    public function socialLogin(Request $request)
    {
        $request->validate([
            'provider' => ['required', 'string', 'in:google,facebook,apple'],
            'provider_id' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'avatar' => ['nullable', 'string', 'url'],
            'device_name' => ['nullable', 'string'],
        ]);

        $provider = $request->provider;
        $providerId = $request->provider_id;
        $email = strtolower($request->email);
        $name = $request->name;
        $avatar = $request->avatar;
        $deviceName = $request->device_name ?? 'mobile-app';

        // Check if user exists by email
        $user = User::where('email', $email)->first();

        if ($user) {
            // User exists - update social provider info if needed
            $socialData = $user->social_providers ?? [];
            $socialData[$provider] = [
                'provider_id' => $providerId,
                'avatar' => $avatar,
                'linked_at' => now()->toDateTimeString(),
            ];

            $user->update([
                'social_providers' => $socialData,
                'device_name' => $deviceName,
            ]);
        } else {
            // Create new user with social login
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(32)), // Random password for social users
                'device_name' => $deviceName,
                'social_providers' => [
                    $provider => [
                        'provider_id' => $providerId,
                        'avatar' => $avatar,
                        'linked_at' => now()->toDateTimeString(),
                    ],
                ],
                'email_verified_at' => now(), // Social logins are considered verified
            ]);

            event(new Registered($user));
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'message' => $user->wasRecentlyCreated ? 'Account created successfully' : 'Logged in successfully',
            'is_new_user' => $user->wasRecentlyCreated,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'device_name' => $user->device_name,
                'avatar' => $avatar,
                'provider' => $provider,
            ],
        ], $user->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Handle Google sign-in for mobile app.
     */
    public function googleSignIn(Request $request)
    {
        $request->merge(['provider' => 'google']);
        return $this->socialLogin($request);
    }

    /**
     * Handle Facebook sign-in for mobile app.
     */
    public function facebookSignIn(Request $request)
    {
        $request->merge(['provider' => 'facebook']);
        return $this->socialLogin($request);
    }

    /**
     * Handle Apple sign-in for mobile app.
     */
    public function appleSignIn(Request $request)
    {
        $request->merge(['provider' => 'apple']);
        return $this->socialLogin($request);
    }

    /**
     * Check if email is available for registration.
     */
    public function checkEmail(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $exists = User::where('email', strtolower($request->email))->exists();

        return response()->json([
            'available' => !$exists,
            'message' => $exists ? 'Email is already registered' : 'Email is available',
        ]);
    }
}
