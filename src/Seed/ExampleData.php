<?php

namespace App\Seed;

use App\Entity\BookDetails;
use App\Entity\Listing;
use App\Entity\ListingPhoto;
use App\Entity\Message;
use App\Entity\SiteSettings;
use App\Entity\User;
use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingStatus;
use App\Enum\ListingType;
use App\Listing\PaperCatalog;
use App\Photo\PhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Example sellers, listings, photos and messages for local development and the
 * test site: app:seed (task db:seed, task test:seed). Expects an empty database.
 */
final readonly class ExampleData
{
    /** Logins, all with the same password: [email, name, verified, phone shown on listings]. */
    private const array USERS = [
        // Verified and shows their phone: the dev login, with listings in every state.
        'sam'   => ['sam@example.com', 'Sam', true, '021 555 0123'],
        // Unverified, so their photos stay hidden from everyone else.
        'alex'  => ['alex@example.com', 'Alex', false, null],
        'aroha' => ['aroha@example.com', 'Aroha', true, '027 555 0198'],
        'liam'  => ['liam@example.com', 'Liam', true, null],
        'priya' => ['priya@example.com', 'Priya', true, null],
        'mere'  => ['mere@example.com', 'Mere', true, '022 555 0147'],
        'josh'  => ['josh@example.com', 'Josh', true, null],
        'mei'   => ['mei@example.com', 'Mei', true, null],
        'ben'   => ['ben@example.com', 'Ben', false, null],
        'admin' => ['admin@example.com', 'Admin', true, null],
    ];

    public function __construct(
        private EntityManagerInterface $manager,
        private UserPasswordHasherInterface $hasher,
        private PaperCatalog $papers,
        private PhotoStorage $photos,
        private PlaceholderPhotos $placeholders,
        #[Autowire('%kernel.project_dir%/data/otago-papers.csv')]
        private string $papersFile,
    ) {}

    /**
     * @return array{users: int, listings: int, photos: int, messages: int}
     */
    public function load(string $password): array
    {
        $users = [];
        foreach (self::USERS as $key => [$email, $name, $verified, $phone]) {
            $user = (new User())->setEmail($email)->setDisplayName($name)->setVerified($verified)
                ->setPhone($phone)->setShowPhone($phone !== null)->setAdmin($key === 'admin');
            $this->manager->persist($user->setPassword($this->hasher->hashPassword($user, $password)));
            $users[$key] = $user;
        }
        $this->manager->persist(new SiteSettings());
        $this->papers->importCsv($this->papersFile);

        $sale   = ListingType::ForSale;
        $wanted = ListingType::Wanted;

        // [seller, type, category, title, description, price ($, null = make an offer), condition,
        //  book [author, edition, isbn, papers], days ago]
        $examples = [
            ['sam', $sale, Category::Textbooks, 'Campbell Biology', 'Some highlighting in the first few chapters, otherwise clean. Used for BIOL112 and CELS191.', 85, Condition::Good, ['Urry, Cain, Wasserman, Minorsky & Orr', 12, '9780135188743', 'BIOL112, CELS191'], 0],
            ['aroha', $sale, Category::Textbooks, 'Chemistry: The Central Science', 'No writing inside. Comes with the unused access code.', 95, Condition::LikeNew, ['Brown, LeMay, Bursten, Murphy & Woodward', 15, '9780137542970', 'CHEM191, CHEM111'], 1],
            ['alex', $sale, Category::Textbooks, 'Principles of Anatomy and Physiology', 'The big one for HUBS191/192. A bit worn on the corners, all pages there.', 110, Condition::Fair, ['Tortora & Derrickson', 16, '9781119662792', 'HUBS191, HUBS192'], 1],
            ['priya', $wanted, Category::Textbooks, 'Psychology (Gleitman) for PSYC111', 'Happy with an older edition as long as it is the 10th or later.', 50, null, ['Gleitman, Gross & Reisberg', 10, null, 'PSYC111'], 2],
            ['liam', $sale, Category::Textbooks, 'Principles of Economics', 'Pencil notes in a couple of chapters, easily rubbed out.', 60, Condition::Good, ['Mankiw', 9, '9780357038314', 'ECON112'], 3],
            ['mere', $wanted, Category::Textbooks, 'LAWS101 course reader', 'Need it before the second semester starts. Either volume is fine.', 40, null, ['Faculty of Law', null, null, 'LAWS101'], 3],
            ['josh', $sale, Category::Textbooks, 'Calculus: Early Transcendentals', 'Solid copy, no missing pages. Covers both MATH160 and MATH170.', 70, Condition::Good, ['Stewart', 9, '9781337613927', 'MATH160, MATH170'], 4],
            ['mei', $sale, Category::Textbooks, 'Introduction to Java Programming', 'Barely opened, the lectures covered everything.', 45, Condition::LikeNew, ['Liang', 12, '9780136520238', 'COMP160'], 6],
            ['ben', $wanted, Category::Textbooks, 'Lehninger Principles of Biochemistry', 'Looking for the 7th or 8th edition for BIOC192.', null, null, ['Nelson & Cox', 8, '9781319228002', 'BIOC192'], 7],
            ['aroha', $sale, Category::Textbooks, 'Physics for Scientists and Engineers', 'Used for PHSI191. Cover is creased but the inside is spotless.', 65, Condition::Good, ['Serway & Jewett', 10, null, 'PHSI191'], 2],
            ['priya', $sale, Category::Textbooks, 'Molecular Biology of the Cell', 'Hardback. Recommended reading for CELS191, essential for later papers.', 90, Condition::Good, ['Alberts, Heald, Johnson, Morgan, Raff, Roberts & Walter', 7, null, 'CELS191'], 5],
            ['mere', $sale, Category::Textbooks, 'Basic Epidemiology', 'Short and readable, the POPH192 lecturers refer to it a lot.', 25, Condition::Fair, ['Bonita, Beaglehole & Kjellström', 2, null, 'POPH192'], 8],
            ['liam', $sale, Category::Textbooks, 'Accounting: Business Reporting for Decision Making', 'All the practice questions are still blank.', 55, Condition::Good, ['Birt, Chalmers, Maloney, Brooks & Oliver', 7, null, 'ACCT102'], 4],
            ['josh', $sale, Category::Textbooks, 'Principles of Marketing', 'Global edition. A few sticky notes left in it.', 50, Condition::Good, ['Kotler & Armstrong', 18, null, 'MART112'], 9],
            ['mere', $sale, Category::Textbooks, 'Learning Legal Rules', 'Handy for LAWS101 essays and the legal method tutorials.', 35, Condition::Good, ['Holland & Webb', 11, null, 'LAWS101'], 6],
            ['ben', $sale, Category::Textbooks, 'The Basic Practice of Statistics', 'Well loved. Some answers written in pen.', 40, Condition::WellUsed, ['Moore, Notz & Fligner', 9, null, 'STAT110'], 12],
            ['priya', $sale, Category::Textbooks, 'Cognitive Psychology', 'Bought new last year, read once.', 45, Condition::LikeNew, ['Goldstein', 5, null, 'PSYC211'], 10],
            ['aroha', $sale, Category::Textbooks, 'Brock Biology of Microorganisms', 'For MICR221. Heavy, so I would rather meet on campus.', 80, Condition::Good, ['Madigan, Bender, Buckley, Sattley & Stahl', 16, null, 'MICR221'], 11],
            ['mei', $sale, Category::Textbooks, 'Introduction to Algorithms', 'The COSC201 bible. No highlighting.', 70, Condition::LikeNew, ['Cormen, Leiserson, Rivest & Stein', 4, '9780262046305', 'COSC201'], 3],
            ['mere', $sale, Category::Textbooks, 'Criminal Law in New Zealand', 'Tabbed by topic for the open-book exam.', 75, Condition::Good, ['Simester & Brookbanks', 5, null, 'LAWS201'], 7],
            ['josh', $sale, Category::Textbooks, 'Genetics: From Genes to Genomes', 'Some water damage on the back cover, pages are fine.', 45, Condition::Fair, ['Hartwell, Goldberg, Fischer & Hood', 7, null, 'GENE221'], 13],
            ['liam', $sale, Category::Textbooks, 'Intermediate Microeconomics', 'Make me an offer, I just want it gone.', null, Condition::Fair, ['Varian', 9, null, 'ECON201'], 15],
            ['ben', $sale, Category::Textbooks, 'Critical Thinking: A Concise Guide', 'Free to a good home. Missing the dust jacket.', 0, Condition::WellUsed, ['Bowell & Kemp', 4, null, 'PHIL105'], 14],
            ['alex', $sale, Category::Textbooks, 'HUBS191 lab manual and lecture notes', 'Printed notes from every lecture plus the completed lab manual.', null, Condition::Good, ['Department of Anatomy', null, null, 'HUBS191'], 5],
            ['josh', $wanted, Category::Textbooks, 'Cultural Anthropology', 'For ANTH103. Any recent edition.', 30, null, ['Kottak', null, null, 'ANTH103'], 4],
            ['mei', $wanted, Category::Textbooks, 'ENGL127 course reader', 'Lost mine on the bus. Will pay well for one with notes!', 25, null, ['Department of English and Linguistics', null, null, 'ENGL127'], 1],
            ['aroha', $wanted, Category::Textbooks, 'Physiology textbook for HUBS192', 'Silverthorn or similar. Must have the renal chapters.', 60, null, ['Silverthorn', null, null, 'HUBS192'], 6],

            ['sam', $sale, Category::StudyAndTech, 'Lab coat (size M)', 'Worn for one year of chem labs. Washed, no stains.', 20, Condition::Good, null, 2],
            ['liam', $sale, Category::StudyAndTech, 'Casio fx-82AU Plus II calculator', 'Approved for exams. Comes with its cover.', 15, Condition::Good, null, 5],
            ['priya', $wanted, Category::StudyAndTech, 'Safety glasses for labs', 'Any pair that meets the lab requirements.', 10, null, null, 1],
            ['mere', $sale, Category::StudyAndTech, 'Electric blanket (queen)', 'Essential for a Dunedin winter. All settings work.', 35, Condition::Good, null, 0],
            ['josh', $sale, Category::StudyAndTech, 'Oil column heater', 'Seven fins, three heat settings and a timer.', 40, Condition::Fair, null, 4],
            ['ben', $wanted, Category::StudyAndTech, 'Second monitor for study', '24 inch or bigger, HDMI. Can pick up.', 80, null, null, 2],
            ['mei', $sale, Category::StudyAndTech, 'Noise-cancelling headphones', 'Perfect for the library. Battery still lasts all day.', 120, Condition::Good, null, 3],
            ['aroha', $sale, Category::StudyAndTech, 'Laptop stand and wireless keyboard', 'Moving to a desktop so no longer need them.', 45, Condition::LikeNew, null, 8],
            ['alex', $sale, Category::StudyAndTech, 'Desk lamp with USB charger', 'Warm and cool light settings.', 18, Condition::Good, null, 6],

            ['sam', $sale, Category::FlatAndHome, 'Desk and office chair', 'Moving out, want them gone together. Desk is 120 cm wide.', 60, Condition::Fair, null, 3],
            ['liam', $sale, Category::FlatAndHome, 'Bookshelf (5 shelves)', 'Flat-pack, already assembled. Can help carry it.', 0, Condition::WellUsed, null, 8],
            ['priya', $wanted, Category::FlatAndHome, 'Single bed frame', 'Moving into a new flat in February.', 50, null, null, 5],
            ['mere', $sale, Category::FlatAndHome, 'Rice cooker', 'Makes enough for a flat of five.', 25, Condition::Good, null, 1],
            ['josh', $sale, Category::FlatAndHome, 'Box of kitchen gear', 'Pots, pans, cutlery and a few mugs. Perfect for a first flat.', 30, Condition::WellUsed, null, 9],
            ['ben', $sale, Category::FlatAndHome, 'Dehumidifier', 'Pulls a litre a day out of a North Dunedin bedroom.', 90, Condition::Good, null, 7],
            ['aroha', $sale, Category::FlatAndHome, 'Couch (three-seater)', 'Grey fabric, comfy, a little saggy in the middle. Free if you can carry it.', 0, Condition::WellUsed, null, 2],
            ['mei', $wanted, Category::FlatAndHome, 'Flat fridge', 'Any working fridge for a five-person flat on Castle Street.', null, null, null, 3],

            ['sam', $sale, Category::BikesAndTransport, 'Commuter bike with lights and lock', 'Medium frame, new tyres this year. Handles the hills fine.', 180, Condition::Good, null, 2],
            ['liam', $wanted, Category::BikesAndTransport, 'Bike helmet', 'Medium size, must be in good condition.', 25, null, null, 6],
            ['josh', $sale, Category::BikesAndTransport, 'Electric scooter', 'About 25 km range. Charger included.', 350, Condition::Good, null, 5],
            ['mere', $sale, Category::BikesAndTransport, 'Bike pump and repair kit', 'Track pump with a gauge, plus patches and tyre levers.', 20, Condition::Good, null, 10],

            ['priya', $sale, Category::ClothesAndOther, 'Puffer jacket (size L)', 'Warm and waterproof. Too big for me.', 70, Condition::LikeNew, null, 3],
            ['mei', $sale, Category::ClothesAndOther, 'Two tickets to the Toga Party', 'Can no longer make it, selling at face value.', 30, Condition::New, null, 0],
            ['ben', $sale, Category::ClothesAndOther, 'Tramping boots (size 10)', 'Done the Routeburn and the Kepler. Plenty of life left.', 85, Condition::Good, null, 9],
            ['aroha', $wanted, Category::ClothesAndOther, 'Graduation gown hire for December', 'Bachelor gown, I am about 170 cm.', 40, null, null, 4],
        ];

        $listings = [];
        foreach ($examples as $i => [$seller, $type, $category, $title, $description, $price, $condition, $book, $daysAgo]) {
            $listing = (new Listing())
                ->setType($type)
                ->setCategory($category)
                ->setTitle($title)
                ->setDescription($description)
                ->setPriceCents($price === null ? null : $price * 100)
                ->setCondition($condition)
                ->setSeller($users[$seller]);

            $createdAt = new \DateTimeImmutable(\sprintf('-%d days -%d minutes', $daysAgo, $i * 7));
            $listing->setCreatedAt($createdAt)->publish(SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS, $createdAt);

            if ($book !== null) {
                [$author, $edition, $isbn, $papers] = $book;
                $listing->setBook($this->book($author, $edition, $isbn, $papers));
            }

            // Most listings get stand-in photos and textbooks a second one; the rest show
            // an Open Library cover (if they have an ISBN) or the category icon.
            if ($i % 3 !== 2) {
                $this->addPhotos($listing, $book !== null ? 2 : 1);
            }

            $this->manager->persist($listing);
            $listings[$title] = $listing;
        }

        // Sam's listings in the other states, for "My listings".
        $lifetime = SiteSettings::DEFAULT_LISTING_LIFETIME_DAYS;
        $this->manager->persist((new Listing())
            ->setSeller($users['sam'])->setType($sale)->setCategory(Category::Textbooks)
            ->setTitle('Organic Chemistry (Clayden)')->setPriceCents(6500)->setCondition(Condition::Good)
            ->setBook($this->book('Clayden, Greeves & Warren', 2, '9780199270293', 'CHEM202')));
        $this->manager->persist((new Listing())
            ->setSeller($users['sam'])->setType($sale)->setCategory(Category::FlatAndHome)
            ->setTitle('Standing lamp')->setPriceCents(1500)->setCondition(Condition::Fair)
            ->publish($lifetime, new \DateTimeImmutable(\sprintf('-%d days', $lifetime - 2))));
        $this->manager->persist((new Listing())
            ->setSeller($users['sam'])->setType($sale)->setCategory(Category::StudyAndTech)
            ->setTitle('Bluetooth speaker')->setPriceCents(2000)->setCondition(Condition::Good)
            ->publish($lifetime, new \DateTimeImmutable(\sprintf('-%d days', $lifetime + 8))));
        $this->manager->persist((new Listing())
            ->setSeller($users['sam'])->setType($sale)->setCategory(Category::Textbooks)
            ->setTitle('Microeconomics (Pindyck)')->setPriceCents(4000)->setCondition(Condition::Good)
            ->setBook($this->book('Pindyck & Rubinfeld', 9, null, 'ECON201'))
            ->publish($lifetime, new \DateTimeImmutable('-20 days'))
            ->markSold());
        $this->manager->persist((new Listing())
            ->setSeller($users['sam'])->setType($sale)->setCategory(Category::ClothesAndOther)
            ->setTitle('Ski jacket')->setPriceCents(6000)->setCondition(Condition::Good)
            ->publish($lifetime, new \DateTimeImmutable('-40 days'))
            ->setStatus(ListingStatus::Archived));
        // Sold textbooks, so title suggestions have something to rank.
        foreach ([['aroha', 'Campbell Biology', 'Urry, Cain, Wasserman, Minorsky & Orr', 11, 'BIOL112', 70], ['liam', 'Principles of Economics', 'Mankiw', 8, 'ECON112', 45], ['josh', 'Calculus: Early Transcendentals', 'Stewart', 8, 'MATH160', 55]] as [$seller, $title, $author, $edition, $papers, $price]) {
            $this->manager->persist((new Listing())
                ->setSeller($users[$seller])->setType($sale)->setCategory(Category::Textbooks)
                ->setTitle($title)->setPriceCents($price * 100)->setCondition(Condition::Fair)
                ->setBook($this->book($author, $edition, null, $papers))
                ->publish($lifetime, new \DateTimeImmutable('-90 days'))
                ->markSold());
        }

        // Enquiries: [listing, sender (a user or null), name, email, phone, body, when, read].
        foreach ([
            ['Campbell Biology', null, 'Jess', 'jess@example.com', null, 'Hi! Is this still available? I could pick it up tomorrow after my 12 o’clock lecture.', '-2 hours', false],
            ['Desk and office chair', 'priya', 'Priya', 'priya@example.com', null, 'Would you take $50 for both? I can bring a car on Saturday.', '-1 day', true],
            ['Commuter bike with lights and lock', null, 'Tom', null, '021 555 0172', 'Keen on the bike, what size are the wheels? Text me.', '-3 days', true],
            ['Chemistry: The Central Science', 'mei', 'Mei', 'mei@example.com', null, 'Kia ora, is the access code definitely unused? Keen if so.', '-5 hours', false],
            ['Chemistry: The Central Science', null, 'Hamish', 'hamish@example.com', null, 'Could you do $80? Happy to meet at the Central Library.', '-2 days', true],
            ['Introduction to Algorithms', 'josh', 'Josh', 'josh@example.com', '022 555 0110', 'Is this the 4th edition with the green cover? I’ll take it.', '-30 minutes', false],
            ['Criminal Law in New Zealand', null, 'Georgia', 'georgia@example.com', null, 'Are the tabs removable? Still keen either way.', '-1 day', false],
            ['Electric scooter', null, 'Ruby', null, '027 555 0133', 'Does it make it up Dundas Street?', '-4 days', true],
        ] as [$title, $sender, $name, $email, $phone, $body, $when, $read]) {
            $listing = $listings[$title];
            $message = (new Message())
                ->setListing($listing)
                ->setRecipient($listing->getSeller() ?? throw new \LogicException($title.' has no seller.'))
                ->setSender($sender === null ? null : $users[$sender])
                ->setSenderName($name)
                ->setSenderEmail($email)
                ->setSenderPhone($phone)
                ->setBody($body)
                ->setCreatedAt(new \DateTimeImmutable($when));
            $this->manager->persist($read ? $message->markRead() : $message);
        }

        $this->manager->flush();

        return [
            'users'    => \count($users),
            'listings' => $this->manager->getRepository(Listing::class)->count(),
            'photos'   => $this->manager->getRepository(ListingPhoto::class)->count(),
            'messages' => $this->manager->getRepository(Message::class)->count(),
        ];
    }

    /** $papers: comma-separated codes, e.g. "HUBS191, HUBS192". */
    private function book(string $author, ?int $edition, ?string $isbn, ?string $papers): BookDetails
    {
        $book = (new BookDetails())->setAuthor($author)->setEdition($edition)->setIsbn($isbn);
        foreach (array_filter(array_map(trim(...), explode(',', (string) $papers))) as $code) {
            $book->addPaper($this->papers->get($code));
        }

        return $book;
    }

    private function addPhotos(Listing $listing, int $count): void
    {
        for ($variant = 0; $variant < $count; ++$variant) {
            $file = $this->placeholders->for($listing, $variant);
            $this->photos->generate($this->photos->add($listing, $file));
            unlink($file->getPathname());
        }
    }
}
