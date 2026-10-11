-- project_demo/database/schema.sql
--
-- Schema mínimo para o project_demo rodar de ponta a ponta. Só a tabela
-- `language` é lida no bootstrap (configs.php): o idioma padrão e a
-- quantidade de idiomas ativos. `user`/`user_type` (Login, ver
-- system/class/login.class.php) são opcionais - sem elas o framework segue
-- sem sessão autenticada, sem erro.
--
-- Uso:
--   mysql -h <host> -u <user> -p <database> < database/schema.sql

CREATE TABLE IF NOT EXISTS `language` (
   `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
   `code`    VARCHAR(5)   NOT NULL COMMENT 'ex.: pt_BR, en_US',
   `name`    VARCHAR(60)  NOT NULL,
   `default` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = idioma padrão do projeto',
   `status`  TINYINT      NOT NULL DEFAULT 2 COMMENT '0 pendente, 1 inativo, 2 ativo (ver STATUS_* em constants.php)',
   PRIMARY KEY (`id`),
   UNIQUE KEY `uk_language_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: um único idioma ativo e padrão, o suficiente para o bootstrap
-- encontrar `default_lang` e `lang` sem lançar exceção.
INSERT INTO `language` (`code`, `name`, `default`, `status`)
VALUES ('pt_BR', 'Português (Brasil)', 1, 2)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
