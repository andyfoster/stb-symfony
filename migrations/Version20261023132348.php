<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023132348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the old site’s listing_id on imported listings';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE listing ADD legacy_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CB0048D4184998FC ON listing (legacy_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_CB0048D4184998FC');
        $this->addSql('ALTER TABLE listing DROP legacy_id');
    }
}
