<?php

namespace Modules\DonationDrive\App\Support;

class DonationOtpMessage
{
    public static function build(string $otp): string
    {
        return "Giving Together\n"
            ."Your secure donation portal code is {$otp}.\n"
            .'Expires in 5 minutes. Do not share it with anyone.';
    }
}
