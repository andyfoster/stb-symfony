<?php

namespace App\DataFixtures;

use App\Entity\BookDetails;
use App\Entity\Listing;
use App\Entity\Message;
use App\Entity\SiteSettings;
use App\Entity\User;
use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingType;
use App\Listing\PaperCatalog;
use App\Photo\PhotoStorage;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Example listings and logins for local development: `task db:seed`.
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        private PaperCatalog $papers,
        private PhotoStorage $photos,
        private PlaceholderPhotos $placeholders,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Dev-only logins, all with the password "password":
        // sam@example.com (verified, shows their phone), alex@example.com (unverified,
        // so their photos stay hidden) and admin@example.com (sees the admin panel).
        $sam  = $this->user($manager, 'sam@example.com', 'Sam', verified: true)->setPhone('021 555 0123')->setShowPhone(true);
        $alex = $this->user($manager, 'alex@example.com', 'Alex', verified: false);
        $this->user($manager, 'admin@example.com', 'Admin', verified: true)->setAdmin(true);
        $manager->persist(new SiteSettings());
        $this->papers->importCsv(__DIR__ . '/../../data/otago-papers.csv');

        $sale   = ListingType::ForSale;
        $wanted = ListingType::Wanted;

        // [type, category, title, description, price ($), condition, book [author, edition, isbn, papers], days ago]
        $examples = [
            [$sale, Category::Textbooks, 'Campbell Biology', 'Some highlighting in the first few chapters, otherwise clean. Used for BIOL112.', 85, Condition::Good, ['Urry, Cain, Wasserman, Minorsky & Orr', '12th', '9780135188743', 'BIOL112'], 0],
            [$sale, Category::Textbooks, 'Chemistry: The Central Science', 'No writing inside. Comes with the unused access code.', 95, Condition::LikeNew, ['Brown, LeMay, Bursten, Murphy & Woodward', '15th', '9780137542970', 'CHEM191, CHEM111'], 1],
            [$sale, Category::Textbooks, 'Principles of Anatomy and Physiology', 'The big one for HUBS191/192. A bit worn on the corners.', 110, Condition::Fair, ['Tortora & Derrickson', '16th', '9781119662792', 'HUBS191, HUBS192'], 1],
            [$wanted, Category::Textbooks, 'Psychology (Gleitman) for PSYC111', 'Happy with an older edition as long as it is the 10th or later.', 50, null, ['Gleitman, Gross & Reisberg', '10th', null, 'PSYC111'], 2],
            [$sale, Category::Textbooks, 'Principles of Economics', 'Pencil notes in a couple of chapters, easily rubbed out.', 60, Condition::Good, ['Mankiw', '9th', '9780357038314', 'ECON112'], 3],
            [$wanted, Category::Textbooks, 'LAWS101 course reader', 'Need it before the second semester starts.', 40, null, ['Faculty of Law', null, null, 'LAWS101'], 3],
            [$sale, Category::Textbooks, 'Calculus: Early Transcendentals', 'Solid copy, no missing pages. Great for MATH160 and MATH170.', 70, Condition::Good, ['Stewart', '9th', '9781337613927', 'MATH160, MATH170'], 4],
            [$sale, Category::Textbooks, 'Introduction to Java Programming', 'Barely opened, lectures covered everything.', 45, Condition::LikeNew, ['Liang', '12th', '9780136520238', 'COMP160'], 6],
            [$wanted, Category::Textbooks, 'Lehninger Principles of Biochemistry', 'Looking for 7th or 8th edition for BIOC192.', null, null, ['Nelson & Cox', '8th', '9781319228002', 'BIOC192'], 7],
            [$sale, Category::StudyAndTech, 'Lab coat (size M)', 'Worn for one year of chem labs. Washed, no stains.', 20, Condition::Good, null, 2],
            [$sale, Category::StudyAndTech, 'Casio fx-82AU Plus II calculator', 'Approved for exams. Comes with its cover.', 15, Condition::Good, null, 5],
            [$wanted, Category::StudyAndTech, 'Safety glasses for labs', 'Any pair that meets the lab requirements.', 10, null, null, 1],
            [$sale, Category::StudyAndTech, 'Electric blanket (queen)', 'Essential for a Dunedin winter. All settings work.', 35, Condition::Good, null, 0],
            [$sale, Category::StudyAndTech, 'Oil column heater', 'Seven fins, three heat settings and a timer.', 40, Condition::Fair, null, 4],
            [$wanted, Category::StudyAndTech, 'Second monitor for study', '24 inch or bigger, HDMI. Can pick up.', 80, null, null, 2],
            [$sale, Category::FlatAndHome, 'Desk and office chair', 'Moving out, want them gone together. Desk is 120 cm wide.', 60, Condition::Fair, null, 3],
            [$sale, Category::FlatAndHome, 'Bookshelf (5 shelves)', 'Flat-pack, already assembled. Can help carry it.', 0, Condition::WellUsed, null, 8],
            [$wanted, Category::FlatAndHome, 'Single bed frame', 'Moving into a new flat in February.', 50, null, null, 5],
            [$sale, Category::FlatAndHome, 'Rice cooker', 'Makes enough for a flat of five.', 25, Condition::Good, null, 1],
            [$sale, Category::FlatAndHome, 'Box of kitchen gear', 'Pots, pans, cutlery and a few mugs. Perfect for a first flat.', 30, Condition::WellUsed, null, 9],
            [$sale, Category::BikesAndTransport, 'Commuter bike with lights and lock', 'Medium frame, new tyres this year. Handles the hills fine.', 180, Condition::Good, null, 2],
            [$wanted, Category::BikesAndTransport, 'Bike helmet', 'Medium size, must be in good condition.', 25, null, null, 6],
            [$sale, Category::ClothesAndOther, 'Puffer jacket (size L)', 'Warm and waterproof. Too big for me.', 70, Condition::LikeNew, null, 3],
            [$sale, Category::ClothesAndOther, 'Two tickets to the Toga Party', 'Can no longer make it, selling at face value.', 30, Condition::New, null, 0],
        ];

        foreach ($examples as $i => [$type, $category, $title, $description, $price, $condition, $book, $daysAgo]) {
            $listing = (new Listing())
                ->setType($type)
                ->setCategory($category)
                ->setTitle($title)
                ->setDescription($description)
                ->setPriceCents($price === null ? null : $price * 100)
                ->setCondition($condition)
                ->setSeller($i % 3 === 0 ? $alex : $sam);

            $createdAt = new \DateTimeImmutable(\sprintf('-%d days -%d minutes', $daysAgo, $i));
            $listing->setCreatedAt($createdAt)->publish(SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS, $createdAt);

            if ($book !== null) {
                [$author, $edition, $isbn, $papers] = $book;
                $listing->setBook($this->book($author, $edition, $isbn, $papers));
            }

            // Most listings get stand-in photos; textbooks get a second one.
            if ($i % 3 !== 2) {
                $this->addPhotos($listing, $book !== null ? 2 : 1);
            }

            $manager->persist($listing);
        }

        // Some of Sam's listings in other states, for "My listings".
        $manager->persist((new Listing())
            ->setSeller($sam)->setType($sale)->setCategory(Category::Textbooks)
            ->setTitle('Organic Chemistry (Clayden)')->setPriceCents(6500)->setCondition(Condition::Good)
            ->setBook($this->book('Clayden, Greeves & Warren', '2nd', '9780199270293', 'CHEM202')));
        $manager->persist((new Listing())
            ->setSeller($sam)->setType($sale)->setCategory(Category::FlatAndHome)
            ->setTitle('Standing lamp')->setPriceCents(1500)->setCondition(Condition::Fair)
            ->publish(SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS, new \DateTimeImmutable('-40 days')));
        $manager->persist((new Listing())
            ->setSeller($sam)->setType($sale)->setCategory(Category::StudyAndTech)
            ->setTitle('Bluetooth speaker')->setPriceCents(2000)->setCondition(Condition::Good)
            ->publish(SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS, new \DateTimeImmutable('-50 days')));
        $manager->persist((new Listing())
            ->setSeller($sam)->setType($sale)->setCategory(Category::Textbooks)
            ->setTitle('Microeconomics (Pindyck)')->setPriceCents(4000)->setCondition(Condition::Good)
            ->setBook((new BookDetails())->setAuthor('Pindyck & Rubinfeld'))
            ->publish(SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS, new \DateTimeImmutable('-20 days'))
            ->markSold());

        // A few enquiries for Sam's inbox, the newest unread.
        $manager->flush();
        $samsListings = array_values(array_filter(
            $manager->getRepository(Listing::class)->findBy(['seller' => $sam], ['publishedAt' => 'DESC']),
            static fn(Listing $listing): bool => $listing->isLive(),
        ));
        foreach ([
            ['Jess', 'jess@example.com', 'Hi! Is this still available? I could pick it up tomorrow after my 12 o’clock lecture.', '-2 hours', false],
            ['Tom', 'tom@example.com', 'Would you take $70 for it? I can meet on campus any day this week.', '-1 day', true],
            ['Aroha', 'aroha@example.com', 'Kia ora, does it have the access code still? Keen if so.', '-3 days', true],
        ] as $i => [$name, $email, $body, $when, $read]) {
            $message = (new Message())
                ->setListing($samsListings[$i])
                ->setRecipient($sam)
                ->setSenderName($name)
                ->setSenderEmail($email)
                ->setBody($body)
                ->setCreatedAt(new \DateTimeImmutable($when));
            $manager->persist($read ? $message->markRead() : $message);
        }

        $manager->flush();
    }

    /** $papers: comma-separated codes, e.g. "HUBS191, HUBS192". */
    private function book(string $author, ?string $edition, ?string $isbn, ?string $papers): BookDetails
    {
        $book = (new BookDetails())->setAuthor($author)->setEdition($edition)->setIsbn($isbn);
        foreach (array_filter(array_map('trim', explode(',', (string) $papers))) as $code) {
            $book->addPaper($this->papers->get($code));
        }

        return $book;
    }

    private function addPhotos(Listing $listing, int $count): void
    {
        for ($variant = 0; $variant < $count; ++$variant) {
            $file = $this->placeholders->for($listing, $variant);
            $this->photos->add($listing, $file);
            unlink($file->getPathname());
        }
    }

    private function user(ObjectManager $manager, string $email, string $name, bool $verified): User
    {
        $user = (new User())->setEmail($email)->setDisplayName($name)->setVerified($verified);
        $manager->persist($user->setPassword($this->hasher->hashPassword($user, 'password')));

        return $user;
    }
}
