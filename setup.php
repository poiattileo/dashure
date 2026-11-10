<?php
/**
 * [URE] Dashure — aba "Início" na página inicial do GLPI 12: anotações (logins e senhas,
 * por perfil), resumo do Tralla e do Assignat-ure, avisos e atalhos.
 */

define('PLUGIN_DASHURE_VERSION', '1.3.0');
define('PLUGIN_DASHURE_MIN_GLPI', '12.0.0');
define('PLUGIN_DASHURE_MAX_GLPI', '12.0.99');

/** Chave da aba registrada na Central (itemtype$número). */
define('PLUGIN_DASHURE_TAB', 'PluginDashureHome$1');

function plugin_init_dashure(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass('PluginDashureProfile', ['addtabon' => 'Profile']);
    Plugin::registerClass('PluginDashureHome', ['addtabon' => 'Central']);

    $PLUGIN_HOOKS['change_profile']['dashure'] = ['PluginDashureProfile', 'changeProfile'];
    $PLUGIN_HOOKS['secured_fields']['dashure'] = [
        'glpi_plugin_dashure_notes.password_encrypted',
        'glpi_plugin_dashure_notes.notes_encrypted',
        'glpi_plugin_dashure_zabbix.token_encrypted',
    ];

    if (Session::haveRight('plugin_dashure', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['dashure'] = 'front/admin.php';
    }

    $versioned = static function (string $path): string {
        $mtime = @filemtime(__DIR__ . '/' . $path);
        return $path . '?v=' . ($mtime ?: PLUGIN_DASHURE_VERSION);
    };
    $PLUGIN_HOOKS['add_css']['dashure'] = [$versioned('public/css/dashure.css')];
    $PLUGIN_HOOKS['add_javascript']['dashure'] = [$versioned('public/js/dashure.js')];

    // A cada login a página inicial abre na aba "Início" (para quem tem acesso a ela).
    // Se a pessoa trocar de aba, o GLPI guarda a escolha até o fim da sessão.
    if (Session::getLoginUserID() && empty($_SESSION['plugin_dashure_tab_set'])
        && Session::haveRight('plugin_dashure', READ)) {
        $_SESSION['plugin_dashure_tab_set'] = 1;
        Session::setActiveTab('Central', PLUGIN_DASHURE_TAB);
    }
}

function plugin_version_dashure(): array
{
    return [
        'name'         => '[URE] Dashure',
        'version'      => PLUGIN_DASHURE_VERSION,
        'author'       => 'Leonardo Poiatti Fação',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_DASHURE_MIN_GLPI, 'max' => PLUGIN_DASHURE_MAX_GLPI],
            'php'  => ['min' => '8.2.0'],
        ],
    ];
}

function plugin_dashure_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_DASHURE_MIN_GLPI, 'lt')) {
        echo 'Este plugin requer GLPI >= ' . PLUGIN_DASHURE_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_dashure_check_config(): bool
{
    return true;
}
