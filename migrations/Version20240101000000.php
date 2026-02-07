<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create subscriptions table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscriptions (
            id SERIAL PRIMARY KEY,
            telegram_chat_id BIGINT NOT NULL,
            telegram_username VARCHAR(255) DEFAULT NULL,
            telegram_first_name VARCHAR(255) DEFAULT NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_compliment_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        )');

        $this->addSql('CREATE INDEX idx_telegram_chat_id ON subscriptions (telegram_chat_id)');
        $this->addSql('CREATE INDEX idx_is_active ON subscriptions (is_active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE subscriptions');
    }
}
