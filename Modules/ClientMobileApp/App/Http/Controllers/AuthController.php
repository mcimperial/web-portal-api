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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Modules\ClientMobileApp\App\Models\RegistrationOtp;

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
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'passwordConfirmation' => ['required', 'string', 'same:password'],
            'deviceName' => ['nullable', 'string'],
            'agreeToTerms' => ['required', 'accepted'],
        ]);

        $email = strtolower($request->email);

        // Check if email was verified via OTP
        $otpRecord = RegistrationOtp::where('email', $email)
            ->where('verified', true)
            ->first();

        if (!$otpRecord) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email address first.'],
            ]);
        }

        $fullName = trim($request->firstName . ' ' . $request->lastName);

        $user = User::create([
            'name' => $fullName,
            'email' => $email,
            'password' => Hash::make($request->password),
            'device_name' => $request->deviceName ?? 'mobile-app',
            'email_verified_at' => now(), // Mark as verified since OTP was verified
        ]);

        // Clean up the OTP record
        $otpRecord->delete();

        event(new Registered($user));

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'deviceName' => $user->device_name,
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

    /**
     * Generate a 6-digit OTP code.
     */
    private function generateOtp(): string
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Send OTP to email for registration verification (before account exists).
     */
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
        ]);

        $email = strtolower($request->email);
        $name = trim($request->firstName . ' ' . $request->lastName);

        // Check if email is already registered
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered. Please login instead.'],
            ]);
        }

        // Check for existing OTP record
        $existingOtp = RegistrationOtp::where('email', $email)->first();

        // Rate limiting: max 5 attempts
        if ($existingOtp && $existingOtp->hasMaxAttempts() && !$existingOtp->isExpired()) {
            throw ValidationException::withMessages([
                'email' => ['Too many OTP requests. Please try again later.'],
            ]);
        }

        // Generate new OTP
        $otp = $this->generateOtp();
        $expiresAt = now()->addMinutes(10);

        // Create or update OTP record
        $otpRecord = RegistrationOtp::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'otp' => $otp,
                'expires_at' => $expiresAt,
                'attempts' => $existingOtp ? $existingOtp->attempts + 1 : 1,
                'verified' => false,
            ]
        );

        // Send OTP email
        $this->sendRegistrationOtpEmail($email, $name, $otp);

        return response()->json([
            'message' => 'OTP sent successfully to your email.',
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Send OTP email for registration using LLIBI branding.
     */
    private function sendRegistrationOtpEmail(string $email, string $name, string $otp): void
    {
        $emailBody = View::make('emails.otp-verification', [
            'name' => $name,
            'otp' => $otp,
            'expiresInMinutes' => 10,
        ])->render();

        // Use the existing EmailSender service
        $emailSender = new \Modules\ClientMasterlist\App\Services\EmailSender(
            email: $email,
            body: $emailBody,
            subject: 'LLIBI - Email Verification Code'
        );

        $emailSender->sendLlibi();
    }

    /**
     * Send OTP email using LLIBI branding (for existing users).
     */
    private function sendOtpEmail(User $user, string $otp): void
    {
        $this->sendRegistrationOtpEmail($user->email, $user->name, $otp);
    }

    /**
     * Verify OTP code for registration.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $email = strtolower($request->email);

        // Check for OTP record
        $otpRecord = RegistrationOtp::where('email', $email)->first();

        if (!$otpRecord) {
            throw ValidationException::withMessages([
                'otp' => ['No OTP has been sent. Please request a new one.'],
            ]);
        }

        // Check if OTP has expired
        if ($otpRecord->isExpired()) {
            throw ValidationException::withMessages([
                'otp' => ['OTP has expired. Please request a new one.'],
            ]);
        }

        // Verify OTP
        if ($otpRecord->otp !== $request->otp) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP code. Please try again.'],
            ]);
        }

        // Mark as verified
        $otpRecord->update(['verified' => true]);

        return response()->json([
            'message' => 'Email verified successfully.',
            'verified' => true,
        ]);
    }

    /**
     * Resend OTP code.
     */
    public function resendOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = strtolower($request->email);

        // Check for existing OTP record to get the name
        $existingOtp = RegistrationOtp::where('email', $email)->first();

        if (!$existingOtp) {
            throw ValidationException::withMessages([
                'email' => ['No pending verification found. Please start registration again.'],
            ]);
        }

        // Add name fields from existing record
        $request->merge([
            'firstName' => explode(' ', $existingOtp->name)[0] ?? '',
            'lastName' => explode(' ', $existingOtp->name, 2)[1] ?? '',
        ]);

        // Reuse sendOtp logic
        return $this->sendOtp($request);
    }
}
