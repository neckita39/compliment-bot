<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260207165701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add weekday_time and weekend_time fields to subscriptions';
    }

    public function up(Schema $schema): void
    {
        // Add weekday and weekend time fields with defaults
        $this->addSql('ALTER TABLE subscriptions ADD weekday_time TIME(0) WITHOUT TIME ZONE DEFAULT \'07:00:00\' NOT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD weekend_time TIME(0) WITHOUT TIME ZONE DEFAULT \'09:00:00\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP weekday_time');
        $this->addSql('ALTER TABLE subscriptions DROP weekend_time');
    }
}
