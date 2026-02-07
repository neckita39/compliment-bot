<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260207200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add role field to subscriptions table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE subscriptions ADD role VARCHAR(50) DEFAULT 'wife' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP role');
    }
}
