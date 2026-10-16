<?php

namespace App\Tests\Entity;

use App\Entity\Listing;
use App\Enum\Category;
use App\Enum\ListingType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BaseTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();
        $this->manager = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->manager))->createSchema($this->manager->getMetadataFactory()->getAllMetadata());
    }

    public function testTimestampsAreSetOnCreateAndTouchedOnUpdate(): void
    {
        $listing = (new Listing())
            ->setType(ListingType::ForSale)
            ->setCategory(Category::Furniture)
            ->setTitle('Bookshelf')
            ->setUpdatedAt(new \DateTimeImmutable('-1 day'));
        $this->manager->persist($listing);
        $this->manager->flush();

        self::assertSame('Listing '.$listing->getId(), (string) $listing);
        $before = $listing->getUpdatedAt();

        $listing->setTitle('Tall bookshelf');
        $this->manager->flush();

        self::assertGreaterThan($before, $listing->getUpdatedAt());
        self::assertGreaterThan($listing->getCreatedAt(), $listing->getUpdatedAt());
    }
}
