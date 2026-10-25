<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What an AuditEvent records. Values are "area.action", so the log can be
 * filtered by prefix (all "login." events, say).
 */
enum AuditType: string implements TranslatableInterface
{
    // Signing in and out
    case LoginSucceeded = 'login.success';
    case LoginFailed = 'login.failure';
    case LoginThrottled = 'login.throttled';
    case RememberMeLogin = 'login.remember_me';
    case Logout = 'logout';

    // Accounts
    case UserRegistered = 'user.registered';
    case EmailVerified = 'user.email_verified';
    case EmailChanged = 'user.email_changed';
    case GoogleLinked = 'user.google_linked';
    case PasswordResetRequested = 'password.reset_requested';
    case PasswordReset = 'password.reset';
    case PasswordUpgraded = 'password.upgraded';
    case PasswordSet = 'password.set';
    case RoleChanged = 'user.role_changed';

    // Listings
    case ListingCreated = 'listing.created';
    case ListingUpdated = 'listing.updated';
    case ListingPublished = 'listing.published';
    case ListingRefreshed = 'listing.refreshed';
    case ListingArchived = 'listing.archived';
    case ListingSold = 'listing.sold';
    case ListingDeleted = 'listing.deleted';
    case PhotoAdded = 'photo.added';
    case PhotoRemoved = 'photo.removed';
    case MessageSent = 'message.sent';
    case MessageThrottled = 'message.throttled';
    case ContactSent = 'contact.sent';
    case ContactThrottled = 'contact.throttled';

    // The admin panel
    case AdminCreated = 'admin.created';
    case AdminUpdated = 'admin.updated';
    case AdminDeleted = 'admin.deleted';

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Logged in',
            self::LoginFailed => 'Login failed',
            self::LoginThrottled => 'Login throttled',
            self::RememberMeLogin => 'Remembered login',
            self::Logout => 'Logged out',
            self::UserRegistered => 'Signed up',
            self::EmailVerified => 'Email confirmed',
            self::EmailChanged => 'Email changed',
            self::GoogleLinked => 'Google sign-in linked',
            self::PasswordResetRequested => 'Password reset requested',
            self::PasswordReset => 'Password reset',
            self::PasswordUpgraded => 'Password rehashed',
            self::PasswordSet => 'Password set by admin',
            self::RoleChanged => 'Admin access changed',
            self::ListingCreated => 'Listing created',
            self::ListingUpdated => 'Listing edited',
            self::ListingPublished => 'Listing published',
            self::ListingRefreshed => 'Listing refreshed',
            self::ListingArchived => 'Listing archived',
            self::ListingSold => 'Listing sold',
            self::ListingDeleted => 'Listing deleted',
            self::PhotoAdded => 'Photo added',
            self::PhotoRemoved => 'Photo removed',
            self::MessageSent => 'Message sent',
            self::MessageThrottled => 'Message throttled',
            self::ContactSent => 'Contact form sent',
            self::ContactThrottled => 'Contact form throttled',
            self::AdminCreated => 'Admin: created',
            self::AdminUpdated => 'Admin: edited',
            self::AdminDeleted => 'Admin: deleted',
        };
    }

    /** Badge colour in the admin's audit log. */
    public function badge(): string
    {
        return match ($this) {
            self::LoginFailed, self::LoginThrottled, self::MessageThrottled, self::ContactThrottled, self::ListingDeleted, self::AdminDeleted => 'danger',
            self::PasswordSet, self::RoleChanged, self::EmailChanged, self::GoogleLinked, self::PasswordReset, self::PasswordResetRequested => 'warning',
            self::LoginSucceeded, self::RememberMeLogin, self::Logout => 'info',
            default => 'secondary',
        };
    }

    /** Lets EasyAdmin (and forms) show label() instead of the case name. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->label(), locale: $locale);
    }
}
