<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

/** Avisos e atalhos da aba Início. */
class PluginDashureStore
{
    public const NOTICES = 'glpi_plugin_dashure_notices';
    public const LINKS   = 'glpi_plugin_dashure_links';
    public const LEVELS  = ['info' => 'Informação', 'success' => 'Novidade', 'warning' => 'Atenção', 'danger' => 'Urgente'];

    public static function canManage(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /** Avisos visíveis agora (ativos e dentro do período), fixados primeiro. */
    public static function activeNotices(): array
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::NOTICES,
            'WHERE' => [
                'is_active' => 1,
                ['OR' => [['date_begin' => null], ['date_begin' => ['<=', $now]]]],
                ['OR' => [['date_end' => null], ['date_end' => ['>=', $now]]]],
            ],
            'ORDER' => ['is_pinned DESC', 'date_creation DESC'],
            'LIMIT' => 20,
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function allNotices(): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM' => self::NOTICES, 'ORDER' => ['is_active DESC', 'is_pinned DESC', 'date_creation DESC'],
        ]), false);
    }

    public static function getNotice(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::NOTICES, 'WHERE' => ['id' => $id]]) as $row) {
            return $row;
        }
        return null;
    }

    public static function saveNotice(array $input): int
    {
        global $DB;

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Informe o título do aviso.');
        }
        $level = (string) ($input['level'] ?? 'info');
        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }
        $now = date('Y-m-d H:i:s');
        $data = [
            'title'      => mb_substr($title, 0, 255),
            'body'       => trim((string) ($input['body'] ?? '')),
            'level'      => $level,
            'is_pinned'  => !empty($input['is_pinned']) ? 1 : 0,
            'is_active'  => !empty($input['is_active']) ? 1 : 0,
            'date_begin' => self::parseDate((string) ($input['date_begin'] ?? ''), false),
            'date_end'   => self::parseDate((string) ($input['date_end'] ?? ''), true),
            'date_mod'   => $now,
        ];
        if ($data['date_begin'] !== null && $data['date_end'] !== null && $data['date_end'] < $data['date_begin']) {
            throw new RuntimeException('O fim do aviso é antes do início.');
        }

        $id = (int) ($input['id'] ?? 0);
        if ($id > 0) {
            if (self::getNotice($id) === null) {
                throw new RuntimeException('Aviso não encontrado.');
            }
            $DB->update(self::NOTICES, $data, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::NOTICES, $data + [
            'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $now,
        ]);
        return (int) $DB->insertId();
    }

    public static function deleteNotice(int $id): void
    {
        global $DB;
        $DB->delete(self::NOTICES, ['id' => $id]);
    }

    public static function links(): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::LINKS, 'ORDER' => ['rank ASC', 'id ASC']]), false);
    }

    public static function getLink(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::LINKS, 'WHERE' => ['id' => $id]]) as $row) {
            return $row;
        }
        return null;
    }

    public static function saveLink(array $input): int
    {
        global $DB;

        $name = trim((string) ($input['name'] ?? ''));
        $url  = trim((string) ($input['url'] ?? ''));
        if ($name === '' || $url === '') {
            throw new RuntimeException('Informe o nome e o endereço do atalho.');
        }
        // Só endereços web ou caminhos do próprio GLPI (evita javascript: e afins).
        if (!preg_match('#^(https?://|/)#i', $url)) {
            throw new RuntimeException('O endereço deve começar com http://, https:// ou / (página do GLPI).');
        }
        $icon = trim((string) ($input['icon'] ?? ''));
        if (!preg_match('/^ti ti-[a-z0-9-]+$/', $icon)) {
            $icon = 'ti ti-link';
        }
        $data = [
            'name'       => mb_substr($name, 0, 100),
            'url'        => mb_substr($url, 0, 500),
            'icon'       => $icon,
            'new_window' => !empty($input['new_window']) ? 1 : 0,
            'rank'       => (int) ($input['rank'] ?? 0),
            'date_mod'   => date('Y-m-d H:i:s'),
        ];
        $id = (int) ($input['id'] ?? 0);
        if ($id > 0) {
            if (self::getLink($id) === null) {
                throw new RuntimeException('Atalho não encontrado.');
            }
            $DB->update(self::LINKS, $data, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::LINKS, $data);
        return (int) $DB->insertId();
    }

    public static function deleteLink(int $id): void
    {
        global $DB;
        $DB->delete(self::LINKS, ['id' => $id]);
    }

    /** Converte o valor de <input type="datetime-local"> / date para DATETIME. */
    private static function parseDate(string $value, bool $endOfDay): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', $value));
        if ($ts === false) {
            throw new RuntimeException('Data inválida: ' . $value);
        }
        if (strlen($value) === 10 && $endOfDay) {
            return date('Y-m-d 23:59:59', $ts);
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
