<?php

namespace App\Identity;

final class LoginEmail
{
    public static function normalize(string $email): ?string
    {
        // MVP technical constraint, not permanent owner policy or SMTP equivalence:
        // ASCII mailbox/domain with case-insensitive local-part identity. Systems
        // requiring case-distinct mailboxes are unsupported. No IDNA/alias folding;
        // never apply this normalizer to historical legacy contact data.
        if (preg_match('/[^\x20-\x7E]/', $email)) {
            return null;
        }
        $email = strtolower(trim($email));

        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
