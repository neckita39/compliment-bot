<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260220120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Bitrix24 subscriptions and compliment history tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bitrix24_subscriptions (
            id SERIAL PRIMARY KEY,
            bitrix24_user_id INT NOT NULL,
            bitrix24_user_name VARCHAR(255) DEFAULT NULL,
            portal_url VARCHAR(255) NOT NULL,
            is_active BOOLEAN NOT NULL DEFAULT true,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_compliment_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            weekday_time TIME(0) WITHOUT TIME ZONE NOT NULL,
            weekend_time TIME(0) WITHOUT TIME ZONE NOT NULL,
            history_context_size INT NOT NULL DEFAULT 1,
            weekend_enabled BOOLEAN NOT NULL DEFAULT false
        )');

        $this->addSql('CREATE INDEX idx_b24_user_id ON bitrix24_subscriptions (bitrix24_user_id)');
        $this->addSql('CREATE INDEX idx_b24_is_active ON bitrix24_subscriptions (is_active)');

        $this->addSql('CREATE TABLE bitrix24_compliment_history (
            id SERIAL PRIMARY KEY,
            subscription_id INT NOT NULL,
            compliment_text TEXT NOT NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT fk_b24_history_subscription FOREIGN KEY (subscription_id)
                REFERENCES bitrix24_subscriptions (id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_b24_subscription_sent_at ON bitrix24_compliment_history (subscription_id, sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bitrix24_compliment_history');
        $this->addSql('DROP TABLE bitrix24_subscriptions');
    }
}
