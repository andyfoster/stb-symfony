<?php

namespace App\Captcha;

use Symfony\Component\Validator\Constraint;

/**
 * The value must be a Cloudflare Turnstile token that Cloudflare accepts.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Turnstile extends Constraint
{
    public string $message = 'Please confirm you’re not a robot, then send again.';
}
