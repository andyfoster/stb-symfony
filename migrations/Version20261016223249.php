<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016223249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_at/updated_at from the Base entity to listing and book_details';
    }

    public function up(Schema $schema): void
    {
        // Add as nullable, backfill existing rows, then enforce NOT NULL.
        $this->addSql('ALTER TABLE book_details ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE book_details ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE listing ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql('UPDATE listing SET updated_at = created_at');
        $this->addSql('UPDATE book_details b SET created_at = l.created_at, updated_at = l.created_at FROM listing l WHERE l.book_id = b.id');
        $this->addSql('UPDATE book_details SET created_at = NOW(), updated_at = NOW() WHERE created_at IS NULL');

        $this->addSql('ALTER TABLE book_details ALTER created_at SET NOT NULL');
        $this->addSql('ALTER TABLE book_details ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE listing ALTER updated_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_details DROP created_at');
        $this->addSql('ALTER TABLE book_details DROP updated_at');
        $this->addSql('ALTER TABLE listing DROP updated_at');
    }
}
