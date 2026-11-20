<?php

namespace Modules\DonationDrive\App\Support;

class DonationOtpMessage
{
    public static function build(string $otp, ?string $name = null): string
    {
        $recipient = $name ? ucwords(strtolower(trim($name))) : 'Valued Donor';

        return "From: Lacson & Lacson Insurance Brokers, Inc.\n\n"
            ."Dear {$recipient},\n\n"
            ."Your Giving Together donation portal verification code is {$otp}.\n\n"
            ."Please enter this code within 5 minutes to continue signing in.\n\n"
            ."For your security, do not share this code with anyone.\n\n"
            .'This is an automated message. Please do not reply to this SMS.';
    }
}
