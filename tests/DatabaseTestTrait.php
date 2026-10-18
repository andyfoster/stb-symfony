<?php

namespace App\Tests;

use App\Entity\BookDetails;
use App\Entity\Listing;
use App\Entity\User;
use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * For WebTestCases: a client on an isolated in-memory SQLite database (never
 * the application's data), and helpers to create users and listings in it.
 */
trait DatabaseTestTrait
{
    private KernelBrowser $client;
    private EntityManagerInterface $manager;

    private function bootClient(): void
    {
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        $this->client = static::createClient();
        // Keep the same kernel (and so the same in-memory database) across requests.
        $this->client->disableReboot();
        // Stateless CSRF accepts same-origin requests; browsers send this header.
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');
        $this->manager = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->manager))->createSchema($this->manager->getMetadataFactory()->getAllMetadata());
    }

    private function createUser(string $email, bool $verified = true, string $name = 'Sam'): User
    {
        $user = (new User())->setEmail($email)->setDisplayName($name)->setVerified($verified);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'correct horse'));
        $this->manager->persist($user);
        $this->manager->flush();

        return $user;
    }

    private function createListing(User $seller, string $title = 'Chemistry: The Central Science', ?string $published = 'now', Category $category = Category::Textbooks): Listing
    {
        $listing = (new Listing())
            ->setSeller($seller)
            ->setType(ListingType::ForSale)
            ->setCategory($category)
            ->setTitle($title)
            ->setPriceCents(9500)
            ->setCondition(Condition::Good);

        if (Category::Textbooks === $category) {
            $listing->setBook((new BookDetails())->setAuthor('Brown & LeMay')->setCourseCode('CHEM191'));
        }

        if (null !== $published) {
            $listing->publish(42, new \DateTimeImmutable($published));
        }

        $this->manager->persist($listing);
        $this->manager->flush();

        return $listing;
    }
}
