<?php

/*
 * This file is part of Monsieur Biz' Homepage plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scheduled homepage switches, their history and transactional Messenger queue.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monsieurbiz_homepage_homepage ADD enabled TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('CREATE TABLE monsieurbiz_homepage_write_lock (id INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE monsieurbiz_homepage_switch (
            id INT AUTO_INCREMENT NOT NULL,
            channel_id INT NOT NULL,
            target_id INT NOT NULL,
            previous_id INT DEFAULT NULL,
            scheduled_at BIGINT NOT NULL,
            scheduling_key VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL,
            reason VARCHAR(32) DEFAULT NULL,
            executed_at BIGINT DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX homepage_switch_channel_instant (channel_id, scheduled_at),
            INDEX IDX_5CDAB7C372F5A1AA (channel_id),
            INDEX IDX_5CDAB7C3158E0B66 (target_id),
            INDEX homepage_switch_due (channel_id, status, scheduled_at),
            INDEX homepage_switch_target_status (target_id, status),
            INDEX homepage_switch_previous (previous_id),
            CONSTRAINT FK_5CDAB7C372F5A1AA FOREIGN KEY (channel_id) REFERENCES sylius_channel (id),
            CONSTRAINT FK_5CDAB7C3158E0B66 FOREIGN KEY (target_id) REFERENCES monsieurbiz_homepage_homepage (id),
            CONSTRAINT FK_5CDAB7C32DE62210 FOREIGN KEY (previous_id) REFERENCES monsieurbiz_homepage_homepage (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE monsieurbiz_homepage_switch_messages (
            id BIGINT AUTO_INCREMENT NOT NULL,
            body LONGTEXT NOT NULL,
            headers LONGTEXT NOT NULL,
            queue_name VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id),
            INDEX IDX_DC442F42FB7336F0 (queue_name),
            INDEX IDX_DC442F42E3BD61CE (available_at),
            INDEX IDX_DC442F4216BA31DB (delivered_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Rolling back would discard homepage switch history and queued messages.');
    }
}
