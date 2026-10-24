<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024042123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep listings available for at least 365 days, including existing active listings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE site_settings SET listing_lifetime_days = 365 WHERE listing_lifetime_days < 365');

        // Never shorten a longer expiry or reopen sold/draft listings. Clear old reminders
        // only on listings that receive a longer window, so they get a reminder next year.
        $anniversary = $this->connection->getDatabasePlatform()->getDateAddDaysExpression('published_at', '365');
        $this->addSql("UPDATE listing SET expires_at = $anniversary, expiry_reminder_sent_at = NULL WHERE status = 'active' AND published_at IS NOT NULL AND (expires_at IS NULL OR expires_at < $anniversary)");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The previous expiry dates cannot be reconstructed safely.');
    }
}
