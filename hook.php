<?php
/** Instalação / desinstalação do [URE] Dashure. Idempotente. */

require_once __DIR__ . '/inc/profile.class.php';

function plugin_dashure_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_dashure_notices')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashure_notices` (
            `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
            `title`         VARCHAR(255) NOT NULL DEFAULT '',
            `body`          TEXT DEFAULT NULL,
            `level`         VARCHAR(10) NOT NULL DEFAULT 'info' COMMENT 'info|success|warning|danger',
            `is_pinned`     TINYINT(1) NOT NULL DEFAULT 0,
            `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
            `date_begin`    TIMESTAMP NULL DEFAULT NULL,
            `date_end`      TIMESTAMP NULL DEFAULT NULL,
            `users_id`      INT {$sign} NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_dashure_links')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashure_links` (
            `id`         INT {$sign} NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(100) NOT NULL DEFAULT '',
            `url`        VARCHAR(500) NOT NULL DEFAULT '',
            `icon`       VARCHAR(60) NOT NULL DEFAULT 'ti ti-link',
            `new_window` TINYINT(1) NOT NULL DEFAULT 0,
            `rank`       INT NOT NULL DEFAULT 0,
            `date_mod`   TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }

    // Anotações (logins e senhas). Senha e observações criptografadas com a chave do GLPI.
    if (!$DB->tableExists('glpi_plugin_dashure_notes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashure_notes` (
            `id`                   INT {$sign} NOT NULL AUTO_INCREMENT,
            `title`                VARCHAR(255) NOT NULL DEFAULT '',
            `category`             VARCHAR(100) NOT NULL DEFAULT '',
            `url`                  VARCHAR(500) NOT NULL DEFAULT '',
            `login`                VARCHAR(255) NOT NULL DEFAULT '',
            `password_encrypted`   TEXT DEFAULT NULL,
            `notes_encrypted`      MEDIUMTEXT DEFAULT NULL,
            `color`                VARCHAR(20) NOT NULL DEFAULT 'default',
            `is_pinned`            TINYINT(1) NOT NULL DEFAULT 0,
            `users_id`             INT {$sign} NOT NULL DEFAULT 0,
            `users_id_lastupdater` INT {$sign} NOT NULL DEFAULT 0,
            `date_creation`        TIMESTAMP NULL DEFAULT NULL,
            `date_mod`             TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }
    if (!$DB->tableExists('glpi_plugin_dashure_notes_profiles')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashure_notes_profiles` (
            `id`          INT {$sign} NOT NULL AUTO_INCREMENT,
            `notes_id`    INT {$sign} NOT NULL,
            `profiles_id` INT {$sign} NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `note_profile` (`notes_id`, `profiles_id`), KEY `profiles_id` (`profiles_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }
    if (!$DB->tableExists('glpi_plugin_dashure_notes_logs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashure_notes_logs` (
            `id`          INT {$sign} NOT NULL AUTO_INCREMENT,
            `notes_id`    INT {$sign} NOT NULL,
            `note_title`  VARCHAR(255) NOT NULL DEFAULT '',
            `users_id`    INT {$sign} NOT NULL DEFAULT 0,
            `profiles_id` INT {$sign} NOT NULL DEFAULT 0,
            `action`      VARCHAR(20) NOT NULL DEFAULT '',
            `ip`          VARCHAR(45) NOT NULL DEFAULT '',
            `date`        TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `notes_id` (`notes_id`), KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }

    // Conexão com o Zabbix (linha única, id = 1). Token criptografado com a chave do GLPI.
    if (!$DB->tableExists('glpi_plugin_dashure_zabbix')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashure_zabbix` (
            `id`              INT {$sign} NOT NULL AUTO_INCREMENT,
            `url`             VARCHAR(500) NOT NULL DEFAULT '',
            `token_encrypted` TEXT DEFAULT NULL,
            `min_severity`    TINYINT NOT NULL DEFAULT 2,
            `groupids`        TEXT DEFAULT NULL,
            `verify_ssl`      TINYINT(1) NOT NULL DEFAULT 1,
            `refresh`         INT NOT NULL DEFAULT 60,
            `max_problems`    INT NOT NULL DEFAULT 30,
            `date_mod`        TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }

    PluginDashureProfile::install();

    return true;
}

function plugin_dashure_uninstall(): bool
{
    global $DB;

    PluginDashureProfile::uninstall();

    foreach ([
        'glpi_plugin_dashure_notices', 'glpi_plugin_dashure_links', 'glpi_plugin_dashure_notes',
        'glpi_plugin_dashure_notes_profiles', 'glpi_plugin_dashure_notes_logs', 'glpi_plugin_dashure_zabbix',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`") or die($DB->error());
        }
    }

    return true;
}
