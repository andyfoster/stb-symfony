<?php

namespace App\Tests\Controller;

use App\Entity\FeatureFlag;
use App\Entity\Listing;
use App\Entity\User;
use App\Enum\Feature;
use App\Feature\FeatureFlags;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Feature flags in /admin, and what they switch: "Continue with Google" and
 * the Turnstile checks on sign-up, new listings and the contact forms.
 * Turnstile accepts any token except "fail" (MockHttpResponses).
 */
final class FeatureFlagTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testAdminsSwitchFlagsOnTheFeatureFlagsPage(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin/listing');
        $this->client->click($crawler->selectLink('Feature flags')->link());
        self::assertResponseIsSuccessful();
        foreach (Feature::cases() as $feature) {
            self::assertAnySelectorTextContains('td', $feature->label());
        }
        self::assertCount(\count(Feature::cases()), $this->manager->getRepository(FeatureFlag::class)->findAll());

        $flag = $this->manager->getRepository(FeatureFlag::class)->findOneBy(['name' => Feature::GoogleSignIn->value]);
        $this->client->request('GET', '/admin/feature-flag/'.$flag->getId().'/edit');
        $this->client->submitForm('Save changes', ['FeatureFlag[enabled]' => true]);
        self::assertResponseRedirects();

        self::assertTrue(static::getContainer()->get(FeatureFlags::class)->isEnabled(Feature::GoogleSignIn));
    }

    public function testFeaturesUseTheirDefaultsUntilAnAdminChangesThem(): void
    {
        $flags = static::getContainer()->get(FeatureFlags::class);

        self::assertFalse($flags->isEnabled(Feature::GoogleSignIn));
        self::assertTrue($flags->isEnabled(Feature::SignUpCaptcha));

        $flags->set(Feature::SignUpCaptcha, false);
        self::assertFalse($flags->isEnabled(Feature::SignUpCaptcha));
    }

    public function testGoogleSignInIsOffByDefault(): void
    {
        foreach (['/login', '/register'] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorNotExists('.google-button');
        }
        $this->client->request('GET', '/connect/google');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/connect/google/check?code=abc&state=xyz');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.login-error', 'Signing in with Google is turned off');

        $this->setFeature(Feature::GoogleSignIn, true);
        $this->client->request('GET', '/login');
        self::assertSelectorTextContains('.google-button', 'Continue with Google');
    }

    public function testSignUpNeedsTheTurnstileCheck(): void
    {
        $this->client->request('GET', '/register');
        self::assertSelectorExists('form [data-controller="turnstile"]');

        $this->signUp(captcha: null);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'not a robot');

        $this->signUp(captcha: 'fail');
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->manager->getRepository(User::class)->findOneBy([]));

        $this->signUp(captcha: 'ok');
        self::assertResponseRedirects('/');
        self::assertNotNull($this->manager->getRepository(User::class)->findOneBy(['email' => 'jo@example.com']));
    }

    public function testSignUpWithoutTheCheckWhenSwitchedOff(): void
    {
        $this->setFeature(Feature::SignUpCaptcha, false);
        $this->client->request('GET', '/register');
        self::assertSelectorNotExists('[data-controller="turnstile"]');

        $this->signUp(captcha: null);
        self::assertResponseRedirects('/');
    }

    public function testNewListingsNeedTheTurnstileCheckButEditsDont(): void
    {
        $seller = $this->createUser('sam@example.com');
        $this->client->loginUser($seller);
        $this->client->request('GET', '/sell');
        self::assertSelectorExists('.listing-form [data-controller="turnstile"]');

        $this->postListing(captcha: 'fail');
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->manager->getRepository(Listing::class)->findAll());

        $this->postListing(captcha: 'ok');
        self::assertResponseRedirects();
        $listing = $this->manager->getRepository(Listing::class)->findOneBy([]);
        self::assertSame('Desk lamp', $listing->getTitle());

        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');
        self::assertSelectorNotExists('[data-controller="turnstile"]');
    }

    public function testNewListingsWithoutTheCheckWhenSwitchedOff(): void
    {
        $this->setFeature(Feature::ListingCaptcha, false);
        $this->client->loginUser($this->createUser('sam@example.com'));
        $this->client->request('GET', '/sell');
        self::assertSelectorNotExists('[data-controller="turnstile"]');

        $this->postListing(captcha: null);
        self::assertResponseRedirects();
    }

    public function testContactFormsSkipTheCheckWhenSwitchedOff(): void
    {
        $listing = $this->createListing($this->createUser('sam@example.com'));
        $this->client->request('GET', '/about');
        self::assertSelectorExists('.about-contact [data-controller="turnstile"]');

        $this->setFeature(Feature::ContactCaptcha, false);
        $this->client->request('GET', '/about');
        self::assertSelectorNotExists('[data-controller="turnstile"]');
        $this->client->request('GET', '/listings/'.$listing->getId());
        self::assertSelectorNotExists('[data-controller="turnstile"]');
    }

    private function signUp(?string $captcha): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Sign up')->form();
        $values = $form->getPhpValues();
        $values['registration_form'] = [
            'displayName' => 'Jo',
            'email' => 'jo@example.com',
            'plainPassword' => ['first' => 'correct horse', 'second' => 'correct horse'],
        ] + $values['registration_form'];
        if (null !== $captcha) {
            // The Turnstile widget posts its token under the field's name.
            $values['registration_form']['captcha'] = $captcha;
        }

        $this->client->request('POST', $form->getUri(), $values);
    }

    private function postListing(?string $captcha): void
    {
        $crawler = $this->client->request('GET', '/sell');
        $form = $crawler->selectButton('Publish listing')->form();
        $values = $form->getPhpValues();
        $values['listing_form'] = [
            'title' => 'Desk lamp',
            'category' => 'flat_and_home',
            'condition' => 'good',
            'publish' => '',
        ] + $values['listing_form'];
        if (null !== $captcha) {
            $values['listing_form']['captcha'] = $captcha;
        }

        $this->client->request('POST', $form->getUri(), $values);
    }
}
