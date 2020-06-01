<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20200601131409 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE mbiz_homepage_homepage (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mbiz_homepage_homepage_channels (page_id INT NOT NULL, channel_id INT NOT NULL, INDEX IDX_E224C76DC4663E4 (page_id), INDEX IDX_E224C76D72F5A1AA (channel_id), PRIMARY KEY(page_id, channel_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mbiz_homepage_homepage_translation (id INT AUTO_INCREMENT NOT NULL, translatable_id INT NOT NULL, name VARCHAR(255) DEFAULT NULL, content LONGTEXT DEFAULT NULL, metaTitle VARCHAR(255) DEFAULT NULL, metaKeywords VARCHAR(255) DEFAULT NULL, metaDescription LONGTEXT DEFAULT NULL, locale VARCHAR(255) DEFAULT NULL, INDEX IDX_FB9F7FE42C2AC5D3 (translatable_id), UNIQUE INDEX mbiz_homepage_homepage_translation_uniq_trans (translatable_id, locale), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE mbiz_homepage_homepage_channels ADD CONSTRAINT FK_E224C76DC4663E4 FOREIGN KEY (page_id) REFERENCES mbiz_homepage_homepage (id)');
        $this->addSql('ALTER TABLE mbiz_homepage_homepage_channels ADD CONSTRAINT FK_E224C76D72F5A1AA FOREIGN KEY (channel_id) REFERENCES sylius_channel (id)');
        $this->addSql('ALTER TABLE mbiz_homepage_homepage_translation ADD CONSTRAINT FK_FB9F7FE42C2AC5D3 FOREIGN KEY (translatable_id) REFERENCES mbiz_homepage_homepage (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE mbiz_homepage_homepage_channels DROP FOREIGN KEY FK_E224C76DC4663E4');
        $this->addSql('ALTER TABLE mbiz_homepage_homepage_translation DROP FOREIGN KEY FK_FB9F7FE42C2AC5D3');
        $this->addSql('DROP TABLE mbiz_homepage_homepage');
        $this->addSql('DROP TABLE mbiz_homepage_homepage_channels');
        $this->addSql('DROP TABLE mbiz_homepage_homepage_translation');
    }
}
