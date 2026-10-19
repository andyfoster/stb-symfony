<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019004218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merge eight listing categories into five';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE listing SET category = 'study_and_tech' WHERE category IN ('course_gear', 'electronics')");
        $this->addSql("UPDATE listing SET category = 'flat_and_home' WHERE category IN ('furniture', 'flat_and_kitchen')");
        $this->addSql("UPDATE listing SET category = 'other' WHERE category = 'clothing'");
    }

    public function down(Schema $schema): void
    {
        // Which old category each listing had is lost; split them back as best we can.
        $this->addSql("UPDATE listing SET category = 'course_gear' WHERE category = 'study_and_tech'");
        $this->addSql("UPDATE listing SET category = 'furniture' WHERE category = 'flat_and_home'");
    }
}
