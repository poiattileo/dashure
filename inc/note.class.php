<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

/**
 * Anotações da aba Início (logins e senhas).
 *
 * - Senha e observações ficam criptografadas com a chave do GLPI (GLPIKey).
 * - Cada anotação é liberada para perfis escolhidos; vale o perfil ATIVO da sessão.
 * - Quem criou e quem gerencia todas (UPDATE) sempre veem.
 * - Mostrar/copiar a senha e cada alteração ficam registrados no histórico.
 */
class PluginDashureNote
{
    public const TABLE    = 'glpi_plugin_dashure_notes';
    public const PROFILES = 'glpi_plugin_dashure_notes_profiles';
    public const LOGS     = 'glpi_plugin_dashure_notes_logs';
    public const COLORS   = ['default' => 'Padrão', 'blue' => 'Azul', 'green' => 'Verde', 'amber' => 'Amarelo', 'red' => 'Vermelho', 'purple' => 'Roxo'];
    public const ACTIONS  = [
        'create' => 'Criou', 'update' => 'Alterou', 'delete' => 'Excluiu',
        'reveal' => 'Mostrou a senha', 'copy' => 'Copiou a senha', 'copy_login' => 'Copiou o login',
    ];

    public static function canView(): bool
    {
        return Session::haveRight(PluginDashureProfile::RIGHT_NOTES, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(PluginDashureProfile::RIGHT_NOTES, CREATE);
    }

    public static function canManageAll(): bool
    {
        return Session::haveRight(PluginDashureProfile::RIGHT_NOTES, UPDATE);
    }

    private static function userId(): int
    {
        return (int) Session::getLoginUserID();
    }

    private static function activeProfile(): int
    {
        return (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
    }

    /** Anotações que o usuário pode ver (sem segredos). */
    public static function visible(): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $where = [];
        if (!self::canManageAll()) {
            $where[] = ['OR' => [
                'n.users_id' => self::userId(),
                'n.id'       => new \Glpi\DBAL\QuerySubQuery([
                    'SELECT' => 'notes_id', 'FROM' => self::PROFILES,
                    'WHERE'  => ['profiles_id' => self::activeProfile()],
                ]),
            ]];
        }
        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['n.id', 'n.title', 'n.category', 'n.url', 'n.login', 'n.color', 'n.is_pinned',
                'n.users_id', 'n.date_mod', 'n.password_encrypted', 'n.notes_encrypted'],
            'FROM'   => self::TABLE . ' AS n',
            'WHERE'  => $where,
            'ORDER'  => ['n.is_pinned DESC', 'n.title ASC'],
        ]) as $row) {
            $row['has_password'] = (string) $row['password_encrypted'] !== '';
            $row['has_notes'] = (string) $row['notes_encrypted'] !== '';
            unset($row['password_encrypted'], $row['notes_encrypted']);
            $row['can_edit'] = self::canEditRow($row);
            $rows[] = $row;
        }
        return $rows;
    }

    public static function get(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]]) as $row) {
            return $row;
        }
        return null;
    }

    public static function profileIds(int $id): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => 'profiles_id', 'FROM' => self::PROFILES, 'WHERE' => ['notes_id' => $id]]) as $row) {
            $ids[] = (int) $row['profiles_id'];
        }
        return $ids;
    }

    public static function canSeeRow(array $note): bool
    {
        if (!self::canView()) {
            return false;
        }
        if (self::canManageAll() || (int) $note['users_id'] === self::userId()) {
            return true;
        }
        return in_array(self::activeProfile(), self::profileIds((int) $note['id']), true);
    }

    public static function canEditRow(array $note): bool
    {
        return self::canManageAll()
            || (self::canCreate() && (int) $note['users_id'] === self::userId());
    }

    /** Busca a anotação garantindo que o usuário pode vê-la. */
    public static function getVisible(int $id): array
    {
        $note = self::get($id);
        if ($note === null || !self::canSeeRow($note)) {
            throw new RuntimeException('Anotação não encontrada ou sem permissão.');
        }
        return $note;
    }

    public static function decrypt(string $value): string
    {
        return $value === '' ? '' : (string) (new GLPIKey())->decrypt($value);
    }

    private static function encrypt(string $value): string
    {
        return $value === '' ? '' : (string) (new GLPIKey())->encrypt($value);
    }

    /** Devolve senha e observações em claro e registra quem pediu. */
    public static function reveal(int $id, string $action): array
    {
        if (!isset(self::ACTIONS[$action]) || !in_array($action, ['reveal', 'copy', 'copy_login'], true)) {
            throw new RuntimeException('Ação inválida.');
        }
        $note = self::getVisible($id);
        self::log($id, $action);
        if ($action === 'copy_login') {
            return ['login' => (string) $note['login']];
        }
        return [
            'password' => self::decrypt((string) $note['password_encrypted']),
            'notes'    => self::decrypt((string) $note['notes_encrypted']),
        ];
    }

    public static function save(array $input): int
    {
        global $DB;

        $id = (int) ($input['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            $current = self::getVisible($id);
            if (!self::canEditRow($current)) {
                throw new RuntimeException('Você não pode editar esta anotação.');
            }
        } elseif (!self::canCreate()) {
            throw new RuntimeException('Seu perfil não pode criar anotações.');
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Informe o título da anotação.');
        }
        $url = trim((string) ($input['url'] ?? ''));
        if (preg_match('/^\s*(javascript|data|vbscript):/i', $url)) {
            throw new RuntimeException('Endereço inválido.');
        }
        $color = (string) ($input['color'] ?? 'default');
        if (!isset(self::COLORS[$color])) {
            $color = 'default';
        }

        $profiles = array_values(array_unique(array_filter(array_map('intval', (array) ($input['profiles'] ?? [])))));
        if ($profiles === []) {
            throw new RuntimeException('Marque pelo menos um perfil que pode ver a anotação.');
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'title'     => mb_substr($title, 0, 255),
            'category'  => mb_substr(trim((string) ($input['category'] ?? '')), 0, 100),
            'url'       => mb_substr($url, 0, 500),
            'login'     => mb_substr(trim((string) ($input['login'] ?? '')), 0, 255),
            'color'     => $color,
            'is_pinned' => !empty($input['is_pinned']) ? 1 : 0,
            'users_id_lastupdater' => self::userId(),
            'date_mod'  => $now,
        ];
        // Senha: em branco na edição mantém a atual; "remover senha" apaga.
        $password = (string) ($input['password'] ?? '');
        if ($current === null || $password !== '' || !empty($input['clear_password'])) {
            $data['password_encrypted'] = !empty($input['clear_password']) ? '' : self::encrypt($password);
        }
        $data['notes_encrypted'] = self::encrypt(trim((string) ($input['notes'] ?? '')));

        $DB->beginTransaction();
        try {
            if ($current === null) {
                $DB->insert(self::TABLE, $data + ['users_id' => self::userId(), 'date_creation' => $now]);
                $id = (int) $DB->insertId();
            } else {
                $DB->update(self::TABLE, $data, ['id' => $id]);
            }
            $DB->delete(self::PROFILES, ['notes_id' => $id]);
            foreach ($profiles as $profileId) {
                $DB->insert(self::PROFILES, ['notes_id' => $id, 'profiles_id' => $profileId]);
            }
            self::log($id, $current === null ? 'create' : 'update');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        return $id;
    }

    public static function delete(int $id): void
    {
        global $DB;

        $note = self::getVisible($id);
        if (!self::canEditRow($note)) {
            throw new RuntimeException('Você não pode excluir esta anotação.');
        }
        $DB->delete(self::PROFILES, ['notes_id' => $id]);
        $DB->delete(self::TABLE, ['id' => $id]);
        // O histórico fica, com o título, para auditoria.
        self::log($id, 'delete', (string) $note['title']);
    }

    public static function log(int $id, string $action, string $title = ''): void
    {
        global $DB;

        if ($title === '') {
            $title = (string) (self::get($id)['title'] ?? '');
        }
        $DB->insert(self::LOGS, [
            'notes_id' => $id, 'note_title' => mb_substr($title, 0, 255), 'users_id' => self::userId(),
            'profiles_id' => self::activeProfile(), 'action' => $action,
            'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), 'date' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function history(int $id, int $limit = 100): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM' => self::LOGS, 'WHERE' => ['notes_id' => $id], 'ORDER' => 'id DESC', 'LIMIT' => $limit,
        ]), false);
    }

    /** Perfis da interface padrão (central) para marcar na anotação. */
    public static function profileChoices(): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'interface'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name']) as $row) {
            $rows[(int) $row['id']] = (string) $row['name'] . ((string) $row['interface'] === 'helpdesk' ? ' (simplificada)' : '');
        }
        return $rows;
    }
}
