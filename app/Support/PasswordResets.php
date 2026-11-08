<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Email password resets only work when the app can send real email.
 * With the log or array mailer a reset link would never arrive, so the
 * "Forgot password?" link stays hidden until a mailer is set up.
 */
class PasswordResets
{
    public static function available(): bool
    {
        if (in_array(config('mail.default'), ['log', 'array', null, ''], true)) {
            return false;
        }

        try {
            return Schema::hasTable('password_reset_tokens');
        } catch (\Throwable) {
            return false;
        }
    }
}
