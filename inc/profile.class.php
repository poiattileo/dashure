<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

/** Direitos do [URE] Dashure no perfil do GLPI (aba "Dashure" em Administração → Perfis). */
class PluginDashureProfile extends CommonDBTM
{
    public static string $rightname = 'profile';

    /** READ = vê a aba Início; UPDATE = gerencia avisos e atalhos. */
    public const RIGHT_HOME = 'plugin_dashure';
    /** READ = vê anotações liberadas ao perfil; CREATE = cria (e edita as próprias); UPDATE = gerencia todas. */
    public const RIGHT_NOTES = 'plugin_dashure_notes';

    public const HOME_OPTIONS = [
        0               => '— Sem acesso —',
        READ            => 'Ver a aba Início',
        READ | UPDATE   => 'Ver e gerenciar avisos e atalhos',
    ];
    public const NOTES_OPTIONS = [
        0                        => '— Sem acesso —',
        READ                     => 'Ver as anotações liberadas para o perfil',
        READ | CREATE            => 'Ver e criar (edita as próprias)',
        READ | CREATE | UPDATE   => 'Gerenciar todas (vê e edita todas, vê o histórico)',
    ];

    public static function getTypeName($nb = 0)
    {
        return 'Permissões Dashure';
    }

    /** Usado ao instalar: perfis que administram a configuração do GLPI recebem tudo, os demais nada. */
    public static function install(): void
    {
        global $DB;

        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $profile) {
            $profileId = (int) $profile['id'];
            $isAdmin = (self::rightValue($profileId, 'config') & UPDATE) === UPDATE;
            self::addIfMissing($profileId, self::RIGHT_HOME, $isAdmin ? READ | UPDATE : 0);
            self::addIfMissing($profileId, self::RIGHT_NOTES, $isAdmin ? READ | CREATE | UPDATE : 0);
        }
        self::changeProfile();
    }

    public static function uninstall(): void
    {
        global $DB;

        foreach ([self::RIGHT_HOME, self::RIGHT_NOTES] as $name) {
            $DB->delete(ProfileRight::getTable(), ['name' => $name]);
            unset($_SESSION['glpiactiveprofile'][$name]);
        }
    }

    public static function rightValue(int $profileId, string $name): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['rights'], 'FROM' => ProfileRight::getTable(),
            'WHERE'  => ['profiles_id' => $profileId, 'name' => $name], 'LIMIT' => 1,
        ])->current();

        return is_array($row) ? (int) $row['rights'] : 0;
    }

    private static function addIfMissing(int $profileId, string $name, int $value): void
    {
        global $DB;

        if (!countElementsInTable(ProfileRight::getTable(), ['profiles_id' => $profileId, 'name' => $name])) {
            $DB->insert(ProfileRight::getTable(), ['profiles_id' => $profileId, 'name' => $name, 'rights' => $value]);
        }
    }

    public static function setRight(int $profileId, string $name, int $value): void
    {
        global $DB;

        if (countElementsInTable(ProfileRight::getTable(), ['profiles_id' => $profileId, 'name' => $name])) {
            $DB->update(ProfileRight::getTable(), ['rights' => $value], ['profiles_id' => $profileId, 'name' => $name]);
        } else {
            $DB->insert(ProfileRight::getTable(), ['profiles_id' => $profileId, 'name' => $name, 'rights' => $value]);
        }
    }

    /** Recarrega os direitos do plugin no perfil ativo da sessão. */
    public static function changeProfile(): void
    {
        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profileId <= 0) {
            return;
        }
        foreach ([self::RIGHT_HOME, self::RIGHT_NOTES] as $name) {
            $_SESSION['glpiactiveprofile'][$name] = self::rightValue($profileId, $name);
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('id')) {
            return self::createTabEntry('Dashure', 0, null, 'ti ti-home');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        if (!($item instanceof Profile) || !$item->canView()) {
            return false;
        }

        $profileId = (int) $item->getID();
        $home = self::rightValue($profileId, self::RIGHT_HOME);
        $notes = self::rightValue($profileId, self::RIGHT_NOTES);
        $canEdit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        $h = [PluginDashureHome::class, 'h'];

        echo "<form method='post' action='" . $h($CFG_GLPI['root_doc'] . '/plugins/dashure/front/profile.form.php') . "'>";
        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr class='headerRow'><th colspan='2'>[URE] Dashure — Permissões do perfil</th></tr>";

        $rows = [
            ['home_rights', 'Aba Início', 'Página inicial do GLPI → aba Início', self::HOME_OPTIONS, $home],
            ['notes_rights', 'Anotações (logins e senhas)', 'Cada anotação só aparece para os perfis marcados nela', self::NOTES_OPTIONS, $notes],
        ];
        foreach ($rows as $i => [$field, $label, $hint, $options, $value]) {
            echo "<tr class='tab_bg_" . ($i % 2 + 1) . "'><td width='45%'><strong>" . $h($label) . '</strong><br><small>' . $h($hint) . '</small></td><td>';
            if ($canEdit) {
                Dropdown::showFromArray($field, $options, ['value' => isset($options[$value]) ? $value : 0]);
            } else {
                echo $h($options[$value] ?? (string) $value);
            }
            echo '</td></tr>';
        }

        if ($canEdit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center' style='padding:12px'>";
            echo Html::hidden('profiles_id', ['value' => $profileId]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'><i class='ti ti-device-floppy'></i> Salvar permissões</button>";
            echo '</td></tr>';
        }
        echo '</table></div>';
        Html::closeForm();

        return true;
    }
}
