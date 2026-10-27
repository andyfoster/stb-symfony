<?php

namespace App\Enum;

/**
 * Parts of the site admins can switch on and off under Feature flags in
 * /admin (FeatureFlags). Add a case here to add a flag; its row is created
 * with default() the first time the flags are read.
 */
enum Feature: string
{
    case GoogleSignIn = 'google_sign_in';
    case SignUpCaptcha = 'sign_up_captcha';
    case ListingCaptcha = 'listing_captcha';
    case ContactCaptcha = 'contact_captcha';

    public function label(): string
    {
        return match ($this) {
            self::GoogleSignIn => 'Continue with Google',
            self::SignUpCaptcha => 'Cloudflare check on sign-up',
            self::ListingCaptcha => 'Cloudflare check on new listings',
            self::ContactCaptcha => 'Cloudflare check on contact forms',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GoogleSignIn => 'The Google button on the login and sign-up pages. Also needs GOOGLE_CLIENT_ID.',
            self::SignUpCaptcha => 'Turnstile on the sign-up form.',
            self::ListingCaptcha => 'Turnstile when anyone posts a new listing (not when editing one).',
            self::ContactCaptcha => 'Turnstile on “Contact the seller” and the About page’s contact form, for guests and unconfirmed accounts.',
        };
    }

    /** Whether it's on before an admin changes it. */
    public function default(): bool
    {
        return match ($this) {
            self::GoogleSignIn => false,
            default => true,
        };
    }
}
