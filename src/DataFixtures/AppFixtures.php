<?php

namespace App\DataFixtures;

use App\Entity\BookDetails;
use App\Entity\Listing;
use App\Entity\SiteSettings;
use App\Entity\User;
use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Example listings and logins for local development: `task db:seed`.
 */
class AppFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        // Dev-only logins, all with the password "password":
        // sam@example.com (verified) and alex@example.com (unverified,
        // so their photos stay hidden).
        $sam = $this->user($manager, 'sam@example.com', 'Sam', verified: true);
        $alex = $this->user($manager, 'alex@example.com', 'Alex', verified: false);
        $manager->persist(new SiteSettings());

        $sale = ListingType::ForSale;
        $wanted = ListingType::Wanted;

        // [type, category, title, description, price ($), condition, location, book [author, edition, isbn, course], days ago]
        $examples = [
            [$sale, Category::Textbooks, 'Campbell Biology', 'Some highlighting in the first few chapters, otherwise clean. Used for BIOL112.', 85, Condition::Good, 'North Dunedin', ['Urry, Cain, Wasserman, Minorsky & Orr', '12th', '9780135188743', 'BIOL112'], 0],
            [$sale, Category::Textbooks, 'Chemistry: The Central Science', 'No writing inside. Comes with the unused access code.', 95, Condition::LikeNew, 'Castle Street', ['Brown, LeMay, Bursten, Murphy & Woodward', '15th', '9780137542970', 'CHEM191'], 1],
            [$sale, Category::Textbooks, 'Principles of Anatomy and Physiology', 'The big one for HUBS191/192. A bit worn on the corners.', 110, Condition::Fair, 'Leith Street', ['Tortora & Derrickson', '16th', '9781119662792', 'HUBS191'], 1],
            [$wanted, Category::Textbooks, 'Psychology (Gleitman) for PSYC111', 'Happy with an older edition as long as it is the 10th or later.', 50, null, 'Anywhere in Dunedin', ['Gleitman, Gross & Reisberg', '10th', null, 'PSYC111'], 2],
            [$sale, Category::Textbooks, 'Principles of Economics', 'Pencil notes in a couple of chapters, easily rubbed out.', 60, Condition::Good, 'Albany Street', ['Mankiw', '9th', '9780357038314', 'ECON112'], 3],
            [$wanted, Category::Textbooks, 'LAWS101 course reader', 'Need it before the second semester starts.', 40, null, 'Campus', ['Faculty of Law', null, null, 'LAWS101'], 3],
            [$sale, Category::Textbooks, 'Calculus: Early Transcendentals', 'Solid copy, no missing pages. Great for MATH160 and MATH170.', 70, Condition::Good, 'Dundas Street', ['Stewart', '9th', '9781337613927', 'MATH160'], 4],
            [$sale, Category::Textbooks, 'Introduction to Java Programming', 'Barely opened, lectures covered everything.', 45, Condition::LikeNew, 'Union Street', ['Liang', '12th', '9780136520238', 'COMP160'], 6],
            [$wanted, Category::Textbooks, 'Lehninger Principles of Biochemistry', 'Looking for 7th or 8th edition for BIOC192.', null, null, 'North Dunedin', ['Nelson & Cox', '8th', '9781319228002', 'BIOC192'], 7],
            [$sale, Category::CourseGear, 'Lab coat (size M)', 'Worn for one year of chem labs. Washed, no stains.', 20, Condition::Good, 'Castle Street', null, 2],
            [$sale, Category::CourseGear, 'Casio fx-82AU Plus II calculator', 'Approved for exams. Comes with its cover.', 15, Condition::Good, 'Clyde Street', null, 5],
            [$wanted, Category::CourseGear, 'Safety glasses for labs', 'Any pair that meets the lab requirements.', 10, null, 'Campus', null, 1],
            [$sale, Category::Electronics, 'Electric blanket (queen)', 'Essential for a Dunedin winter. All settings work.', 35, Condition::Good, 'Leith Street', null, 0],
            [$sale, Category::Electronics, 'Oil column heater', 'Seven fins, three heat settings and a timer.', 40, Condition::Fair, 'Hyde Street', null, 4],
            [$wanted, Category::Electronics, 'Second monitor for study', '24 inch or bigger, HDMI. Can pick up.', 80, null, 'North Dunedin', null, 2],
            [$sale, Category::Furniture, 'Desk and office chair', 'Moving out, want them gone together. Desk is 120 cm wide.', 60, Condition::Fair, 'Dundas Street', null, 3],
            [$sale, Category::Furniture, 'Bookshelf (5 shelves)', 'Flat-pack, already assembled. Can help carry it.', 0, Condition::WellUsed, 'Castle Street', null, 8],
            [$wanted, Category::Furniture, 'Single bed frame', 'Moving into a new flat in February.', 50, null, 'Anywhere in Dunedin', null, 5],
            [$sale, Category::FlatAndKitchen, 'Rice cooker', 'Makes enough for a flat of five.', 25, Condition::Good, 'Albany Street', null, 1],
            [$sale, Category::FlatAndKitchen, 'Box of kitchen gear', 'Pots, pans, cutlery and a few mugs. Perfect for a first flat.', 30, Condition::WellUsed, 'Union Street', null, 9],
            [$sale, Category::BikesAndTransport, 'Commuter bike with lights and lock', 'Medium frame, new tyres this year. Handles the hills fine.', 180, Condition::Good, 'North East Valley', null, 2],
            [$wanted, Category::BikesAndTransport, 'Bike helmet', 'Medium size, must be in good condition.', 25, null, 'Campus', null, 6],
            [$sale, Category::Clothing, 'Puffer jacket (size L)', 'Warm and waterproof. Too big for me.', 70, Condition::LikeNew, 'Leith Street', null, 3],
            [$sale, Category::Other, 'Two tickets to the Toga Party', 'Can no longer make it, selling at face value.', 30, Condition::New, 'Campus', null, 0],
        ];

        foreach ($examples as $i => [$type, $category, $title, $description, $price, $condition, $location, $book, $daysAgo]) {
            $listing = (new Listing())
                ->setType($type)
                ->setCategory($category)
                ->setTitle($title)
                ->setDescription($description)
                ->setPriceCents(null === $price ? null : $price * 100)
                ->setCondition($condition)
                ->setLocation($location)
                ->setSeller(0 === $i % 3 ? $alex : $sam);

            $createdAt = new \DateTimeImmutable(\sprintf('-%d days -%d minutes', $daysAgo, $i));
            $listing->setCreatedAt($createdAt)->publish(SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS, $createdAt);

            if (null !== $book) {
                [$author, $edition, $isbn, $courseCode] = $book;
                $listing->setBook((new BookDetails())
                    ->setAuthor($author)
                    ->setEdition($edition)
                    ->setIsbn($isbn)
                    ->setCourseCode($courseCode));
            }

            $manager->persist($listing);
        }

        // Some of Sam's listings in other states, for "My listings".
        $manager->persist((new Listing())
            ->setSeller($sam)->setType($sale)->setCategory(Category::Textbooks)
            ->setTitle('Organic Chemistry (Clayden)')->setPriceCents(6500)->setCondition(Condition::Good)
            ->setBook((new BookDetails())->setAuthor('Clayden, Greeves & Warren')->setEdition('2nd')->setIsbn('9780199270293')->setCourseCode('CHEM202')));
        $manager->persist((new Listing())
            ->setSeller($sam)->setType($sale)->setCategory(Category::Furniture)
            ->setTitle('Standing lamp')->setPriceCents(1500)->setCondition(Condition::Fair)->setLocation('Castle Street')
            ->publish(SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS, new \DateTimeImmutable('-40 days')));
        $manager->persist((new Listing())
            ->setSeller($sam)->setType($sale)->setCategory(Category::Electronics)
            ->setTitle('Bluetooth speaker')->setPriceCents(2000)->setCondition(Condition::Good)
            ->publish(SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS, new \DateTimeImmutable('-50 days')));
        $manager->persist((new Listing())
            ->setSeller($sam)->setType($sale)->setCategory(Category::Textbooks)
            ->setTitle('Microeconomics (Pindyck)')->setPriceCents(4000)->setCondition(Condition::Good)
            ->setBook((new BookDetails())->setAuthor('Pindyck & Rubinfeld'))
            ->publish(SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS, new \DateTimeImmutable('-20 days'))
            ->markSold());

        $manager->flush();
    }

    private function user(ObjectManager $manager, string $email, string $name, bool $verified): User
    {
        $user = (new User())->setEmail($email)->setDisplayName($name)->setVerified($verified);
        $manager->persist($user->setPassword($this->hasher->hashPassword($user, 'password')));

        return $user;
    }
}
