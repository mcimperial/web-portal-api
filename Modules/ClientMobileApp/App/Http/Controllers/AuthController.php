<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\ClientMobileApp\App\Models\MobileLegalDocument;
use Modules\ClientMobileApp\App\Models\MobileUser;
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

        $user = MobileUser::where('email', strtolower($request->email))->first();

        if (! $user || ! $user->is_active || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $request->device_name ?? $request->deviceName ?? 'mobile-app';

        // Update user's device_name
        $user->update(['device_name' => $deviceName]);

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'contact_number' => $user->contact_number,
                'device_name' => $user->device_name,
            ],
        ]);
    }

    /**
     * Send a password-reset code without revealing whether an account exists.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = strtolower($request->email);
        $user = MobileUser::where('email', $email)->where('is_active', true)->first();

        if ($user) {
            RegistrationOtp::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $user->name,
                    'otp' => $this->generateOtp(),
                    'expires_at' => now()->addMinutes(10),
                    'attempts' => 0,
                    'verified' => false,
                    'reset_token_hash' => null,
                ]
            );

            $otp = RegistrationOtp::where('email', $email)->firstOrFail();
            $this->sendPasswordResetOtpEmail($user, $otp->otp);
        }

        return response()->json([
            'message' => 'If an account matches that email, a reset code has been sent.',
        ]);
    }

    /**
     * Verify a password-reset code and issue a short-lived encrypted token.
     */
    public function verifyPasswordResetOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $email = strtolower($request->email);
        $record = RegistrationOtp::where('email', $email)->first();
        $user = MobileUser::where('email', $email)->where('is_active', true)->first();

        if (! $record || ! $user || $record->isExpired()) {
            throw ValidationException::withMessages([
                'otp' => ['The reset code is invalid or has expired. Request a new code.'],
            ]);
        }

        if ($record->hasMaxAttempts()) {
            throw ValidationException::withMessages([
                'otp' => ['Too many invalid attempts. Request a new reset code.'],
            ]);
        }

        if (! hash_equals($record->otp, $request->otp)) {
            $record->increment('attempts');
            throw ValidationException::withMessages([
                'otp' => ['The reset code is invalid.'],
            ]);
        }

        $resetToken = bin2hex(random_bytes(32));
        $record->update([
            'verified' => true,
            'reset_token_hash' => hash('sha256', $resetToken),
            'expires_at' => now()->addMinutes(10),
        ]);

        return response()->json([
            'reset_token' => $resetToken,
        ]);
    }

    /**
     * Set a new password after a verified reset.
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ]);

        $email = strtolower($data['email']);
        $record = RegistrationOtp::where('email', $email)->first();
        $user = MobileUser::where('email', $email)->where('is_active', true)->first();
        $tokenHash = hash('sha256', $data['token']);
        $validToken = $record?->reset_token_hash
            && hash_equals($record->reset_token_hash, $tokenHash);

        if (! $record || ! $record->verified || ! $validToken || $record->isExpired() || ! $user) {
            throw ValidationException::withMessages([
                'token' => ['The password reset session is invalid or has expired.'],
            ]);
        }

        DB::connection('mysql')->transaction(function () use ($user, $record, $data): void {
            $user->update(['password' => Hash::make($data['password'])]);
            $user->tokens()->delete();
            $record->delete();
        });

        return response()->json([
            'message' => 'Password reset successfully. You can now sign in.',
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
     * Permanently delete the authenticated mobile account.
     */
    public function deleteAccount(Request $request)
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:delete-account'],
        ]);

        $user = $request->user();
        DB::connection('mysql')->transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json([
            'message' => 'Account deleted successfully.',
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
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('mysql.mobile_users', 'email')],
            'contact_number' => ['nullable', 'string', 'regex:/^09[0-9]{9}$/'],
            'password' => ['required', 'string', Password::min(8)],
            'passwordConfirmation' => ['required', 'string', 'same:password'],
            'deviceName' => ['nullable', 'string'],
            'agreeToTerms' => ['required', 'accepted'],
            'terms_version' => ['required', 'integer', 'min:1'],
            'privacy_version' => ['required', 'integer', 'min:1'],
        ]);

        $email = strtolower($request->email);
        $terms = MobileLegalDocument::where('document_key', 'terms')->where('is_active', true)->first();
        $privacy = MobileLegalDocument::where('document_key', 'privacy')->where('is_active', true)->first();

        if (
            ! $terms
            || ! $privacy
            || $request->integer('terms_version') !== $terms->version
            || $request->integer('privacy_version') !== $privacy->version
        ) {
            throw ValidationException::withMessages([
                'agreeToTerms' => ['The legal documents have changed. Please review and accept the latest versions.'],
            ]);
        }

        // Check if email was verified via OTP
        $otpRecord = RegistrationOtp::where('email', $email)
            ->where('verified', true)
            ->first();

        if (! $otpRecord) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email address first.'],
            ]);
        }

        $fullName = trim($request->firstName.' '.$request->lastName);

        $user = MobileUser::create([
            'name' => $fullName,
            'email' => $email,
            'contact_number' => $request->input('contact_number') ?: null,
            'password' => Hash::make($request->password),
            'device_name' => $request->deviceName ?? 'mobile-app',
            'email_verified_at' => now(), // Mark as verified since OTP was verified
            'terms_version' => $terms->version,
            'privacy_version' => $privacy->version,
            'legal_accepted_at' => now(),
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
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'contact_number' => $user->contact_number,
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
        $user = MobileUser::where('email', $email)->first();

        if ($user) {
            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    'email' => ['This mobile account is disabled.'],
                ]);
            }

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
            $user = MobileUser::create([
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
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'contact_number' => $user->contact_number,
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

        $exists = MobileUser::where('email', strtolower($request->email))->exists();

        return response()->json([
            'available' => ! $exists,
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
        $name = trim($request->firstName.' '.$request->lastName);

        // Check if email is already registered
        if (MobileUser::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered. Please login instead.'],
            ]);
        }

        // Check for existing OTP record
        $existingOtp = RegistrationOtp::where('email', $email)->first();

        // Rate limiting: max 5 attempts
        if ($existingOtp && $existingOtp->hasMaxAttempts() && ! $existingOtp->isExpired()) {
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
    private function sendRegistrationOtpEmail(
        string $email,
        string $name,
        string $otp,
        string $subject = 'LLIBI - Email Verification Code',
        string $heading = 'Email Verification',
        string $message = 'Please use the following One-Time Password (OTP) to verify your email address:'
    ): void {
        $emailBody = View::make('emails.otp-verification', [
            'name' => $name,
            'otp' => $otp,
            'expiresInMinutes' => 10,
            'heading' => $heading,
            'message' => $message,
        ])->render();

        // Use the existing EmailSender service
        $emailSender = new \Modules\ClientMasterlist\App\Services\EmailSender(
            email: $email,
            body: $emailBody,
            subject: $subject
        );

        $emailSender->sendLlibi();
    }

    protected function sendPasswordResetOtpEmail(MobileUser $user, string $otp): void
    {
        $this->sendRegistrationOtpEmail(
            $user->email,
            $user->name,
            $otp,
            'LLIBI - Password Reset Code',
            'Password Reset',
            'Please use the following One-Time Password (OTP) to reset your LLIBI account password:'
        );
    }

    /**
     * Send OTP email using LLIBI branding (for existing users).
     */
    private function sendOtpEmail(MobileUser $user, string $otp): void
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

        if (! $otpRecord) {
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
     * Activate member account by validating member_id and birth_date against the masterlist.
     */
    public function activateMember(Request $request)
    {
        $request->validate([
            'member_id' => ['required', 'string'],
            'birth_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $memberId = trim($request->member_id);
        $birthDate = $request->birth_date;

        // Query the sync (masterlist) database
        $member = \Illuminate\Support\Facades\DB::connection('mysql_sync')
            ->table('masterlist')
            ->where('member_id', $memberId)
            ->whereDate('birth_date', $birthDate)
            ->select([
                'id', 'member_id', 'company_code', 'cost_code', 'company_name',
                'last_name', 'first_name', 'middle_name', 'relation',
                'birth_date', 'email', 'incepfrom', 'incepto', 'empcode',
                'noofconsult', 'plantype', 'rb', 'rbdep', 'ismbl', 'mbl',
                'layer', 'preexist', 'pemonth', 'philhealth', 'currency',
                'rb2', 'rb3',
            ])
            ->first();

        if (! $member) {
            throw ValidationException::withMessages([
                'member_id' => ['No member found with the provided Member ID and Birth Date. Please check your details and try again.'],
            ]);
        }

        return response()->json([
            'message' => 'Member activated successfully.',
            'member' => $member,
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

        if (! $existingOtp) {
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
