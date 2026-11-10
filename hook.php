<?php
/** Instalação / desinstalação do [URE] Dashure. Idempotente. */

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

    return true;
}

function plugin_dashure_uninstall(): bool
{
    global $DB;

    foreach (['glpi_plugin_dashure_notices', 'glpi_plugin_dashure_links'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`") or die($DB->error());
        }
    }

    return true;
}
