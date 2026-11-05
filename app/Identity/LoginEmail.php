<?php

namespace App\Identity;

final class LoginEmail
{
    public static function normalize(string $email): ?string
    {
        // Deliberate candidate boundary: ASCII mailbox/domain, no silent IDNA or
        // Unicode folding. Do not apply this to historical legacy contact data.
        if (preg_match('/[^\x20-\x7E]/', $email)) {
            return null;
        }
        $email = strtolower(trim($email));

        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
