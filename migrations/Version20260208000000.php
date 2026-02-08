<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260208000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create compliment_history table for deduplication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE compliment_history (
            id SERIAL PRIMARY KEY,
            subscription_id INT NOT NULL,
            compliment_text TEXT NOT NULL,
            sent_at TIMESTAMP NOT NULL,
            CONSTRAINT fk_compliment_history_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_subscription_sent_at ON compliment_history (subscription_id, sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE compliment_history');
    }
}
