<?php

namespace App\Support;

final class RegistrationEmailDomain
{
    private const CONSUMER_DOMAINS = [
        'gmail.com', 'googlemail.com',
        'yahoo.com', 'yahoo.co.id', 'ymail.com', 'rocketmail.com',
        'outlook.com', 'outlook.co.id', 'hotmail.com', 'hotmail.co.id', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'proton.me', 'protonmail.com', 'pm.me',
        'zoho.com', 'zohomail.com',
        'aol.com', 'gmx.com', 'fastmail.com',
        'tuta.com', 'tutanota.com',
    ];

    public static function allows(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        $domain = substr($email, $at + 1);
        $academicDomain = $domain === 'belajar.id'
            || str_ends_with($domain, '.belajar.id')
            || $domain === 'madrasah.kemenag.go.id'
            || str_ends_with($domain, '.madrasah.kemenag.go.id')
            || preg_match('/\.(ac|sch|edu)(\.[a-z]{2})?$/i', $domain) === 1;

        return in_array($domain, self::CONSUMER_DOMAINS, true) || $academicDomain;
    }
}
