<?php

namespace App\Captcha;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks a Turnstile token with Cloudflare's siteverify API.
 */
class TurnstileValidator extends ConstraintValidator
{
    public const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(TURNSTILE_SECRET_KEY)%')]
        private readonly string $secretKey,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Turnstile) {
            throw new UnexpectedTypeException($constraint, Turnstile::class);
        }

        if (!\is_string($value) || '' === $value || !$this->verify($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }

    private function verify(string $token): bool
    {
        try {
            $result = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => array_filter([
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => $this->requestStack->getCurrentRequest()?->getClientIp(),
                ]),
                'timeout' => 10,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            // Fail closed: better to ask again than let bots through while Cloudflare is down.
            $this->logger->warning('Turnstile verification failed: {message}', ['message' => $e->getMessage()]);

            return false;
        }

        return true === ($result['success'] ?? false);
    }
}
