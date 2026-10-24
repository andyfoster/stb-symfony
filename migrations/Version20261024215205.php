<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261024215205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let people message sellers with a mobile number instead of, or as well as, an email';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message ADD sender_phone VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ALTER sender_email DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message DROP sender_phone');
        $this->addSql('ALTER TABLE message ALTER sender_email SET NOT NULL');
    }
}
