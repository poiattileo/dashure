<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

/** Aba "Início" da página inicial (Central). */
class PluginDashureHome extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Início';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Central) {
            return [1 => self::createTabEntry('Início', 0, null, 'ti ti-home')];
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Central) {
            echo self::render();
        }
        return true;
    }

    public static function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function pluginUrl(string $plugin, string $path = ''): string
    {
        global $CFG_GLPI;
        return (string) $CFG_GLPI['root_doc'] . '/plugins/' . $plugin . $path;
    }

    public static function render(): string
    {
        $userId = (int) Session::getLoginUserID();
        $sections = [];

        foreach ([[self::class, 'renderTralla'], [self::class, 'renderAssignature']] as $renderer) {
            try {
                $html = $renderer($userId);
            } catch (Throwable $e) {
                // Um plugin com problema não pode derrubar a página inicial inteira.
                $html = '<section class="dashure-card"><p class="dashure-muted">Não foi possível carregar esta seção.</p></section>';
                trigger_error('[dashure] ' . $e->getMessage(), E_USER_WARNING);
            }
            if ($html !== '') {
                $sections[] = $html;
            }
        }

        $hour = (int) date('G');
        $greeting = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
        $firstName = trim((string) ($_SESSION['glpifirstname'] ?? ''));
        if ($firstName === '') {
            $firstName = (string) ($_SESSION['glpiname'] ?? '');
        }
        $weekdays = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
        $months = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        $today = $weekdays[(int) date('w')] . ', ' . date('j') . ' de ' . $months[(int) date('n') - 1] . ' de ' . date('Y');

        $out = '<div class="dashure">';
        $out .= '<header class="dashure-hero"><div><h2>' . self::h($greeting . ', ' . $firstName) . '</h2>'
            . '<p>' . self::h(ucfirst($today)) . '</p></div>';
        if (PluginDashureStore::canManage()) {
            $out .= '<a class="dashure-btn dashure-btn-ghost" href="' . self::h(self::pluginUrl('dashure', '/front/admin.php')) . '">'
                . '<i class="ti ti-settings" aria-hidden="true"></i> Avisos e atalhos</a>';
        }
        $out .= '</header>';

        $out .= self::renderLinks();
        $out .= '<div class="dashure-grid">';
        $out .= '<div class="dashure-main">' . implode('', $sections) . '</div>';
        $out .= '<aside class="dashure-side">' . self::renderNotices() . '</aside>';
        $out .= '</div></div>';

        return $out;
    }

    private static function stat(string $label, int $value, string $tone, string $icon, string $url = ''): string
    {
        $tag = $url !== '' ? 'a href="' . self::h($url) . '"' : 'div';
        $close = $url !== '' ? 'a' : 'div';
        return '<' . $tag . ' class="dashure-stat is-' . self::h($tone) . ($value === 0 ? ' is-zero' : '') . '">'
            . '<i class="' . self::h($icon) . '" aria-hidden="true"></i>'
            . '<strong>' . $value . '</strong><span>' . self::h($label) . '</span></' . $close . '>';
    }

    /* ------------------------------------------------------------------ Tralla */

    private static function renderTralla(int $userId): string
    {
        global $DB;

        if (!Plugin::isPluginActive('tralla') || !class_exists('PluginTrallaBoard')
            || !Session::haveRight('plugin_tralla', READ)) {
            return '';
        }

        $boards = PluginTrallaBoard::getVisibleBoards(true);
        $boardNames = [];
        $maintenanceIds = [];
        foreach ($boards as $board) {
            $boardNames[(int) $board['id']] = (string) $board['name'];
            if ((string) $board['type'] === 'maintenance') {
                $maintenanceIds[] = (int) $board['id'];
            }
        }
        if ($boardNames === []) {
            return '';
        }
        $boardsUrl = self::pluginUrl('tralla', '/front/board.php');
        $kanban = static fn (int $boardId, int $cardId = 0): string => self::pluginUrl('tralla', '/front/kanban.php?boards_id=' . $boardId
            . ($cardId > 0 ? '&card=' . $cardId : ''));

        $stats = PluginTrallaBoard::getUserMaintenanceStats($userId, $maintenanceIds);

        // Pedidos aguardando decisão nos quadros em que o usuário aprova.
        $approverBoards = [];
        if (class_exists('PluginTrallaCard')) {
            foreach (array_keys($boardNames) as $boardId) {
                if (PluginTrallaCard::userIsApprover($userId, $boardId)) {
                    $approverBoards[] = $boardId;
                }
            }
        }
        $approvals = [];
        if ($approverBoards !== []) {
            foreach ($DB->request([
                'SELECT' => ['plugin_tralla_boards_id', 'COUNT' => 'id AS total'],
                'FROM'   => 'glpi_plugin_tralla_cards',
                'WHERE'  => [
                    'plugin_tralla_boards_id' => $approverBoards, 'stage' => 2,
                    'status' => 'waiting_approval', 'is_deleted' => 0, 'is_archived' => 0,
                ],
                'GROUPBY' => 'plugin_tralla_boards_id',
            ]) as $row) {
                $approvals[(int) $row['plugin_tralla_boards_id']] = (int) $row['total'];
            }
        }

        $ids = implode(',', array_map('intval', array_keys($boardNames)));
        $uid = (int) $userId;

        // Máquinas com backup pendente nos cards em andamento do usuário.
        $backupRow = $DB->doQuery(
            "SELECT COUNT(*) AS total FROM `glpi_plugin_tralla_machines` AS m
             INNER JOIN `glpi_plugin_tralla_cards` AS c ON c.`id` = m.`plugin_tralla_cards_id`
             WHERE m.`needs_backup` = 1 AND m.`backup_done_at` IS NULL
               AND c.`stage` = 1 AND c.`status` = 'in_progress' AND c.`is_deleted` = 0
               AND c.`technician_id` = {$uid} AND c.`plugin_tralla_boards_id` IN ({$ids})"
        )->fetch_assoc();
        $backupPending = (int) ($backupRow['total'] ?? 0);

        // Meus cards ativos.
        $cards = [];
        $result = $DB->doQuery(
            "SELECT c.`id`, c.`name`, c.`status`, c.`plugin_tralla_boards_id`, c.`machines_total`, c.`date_taken`,
                    (SELECT COUNT(*) FROM `glpi_plugin_tralla_machines` AS m
                      WHERE m.`plugin_tralla_cards_id` = c.`id` AND m.`needs_backup` = 1 AND m.`backup_done_at` IS NULL) AS backup_left
             FROM `glpi_plugin_tralla_cards` AS c
             WHERE c.`stage` = 1 AND c.`technician_id` = {$uid} AND c.`is_deleted` = 0 AND c.`is_archived` = 0
               AND c.`is_paused` = 0 AND c.`status` IN ('in_progress', 'withdrawal')
               AND c.`plugin_tralla_boards_id` IN ({$ids})
             ORDER BY c.`date_mod` DESC LIMIT 10"
        );
        while ($row = $result->fetch_assoc()) {
            $cards[] = $row;
        }

        $out = '<section class="dashure-card"><header class="dashure-card-head">'
            . '<h3><i class="ti ti-layout-kanban" aria-hidden="true"></i> Tralla</h3>'
            . '<a href="' . self::h($boardsUrl) . '">Abrir quadros <i class="ti ti-arrow-right" aria-hidden="true"></i></a></header>';

        if ($maintenanceIds !== []) {
            $out .= '<div class="dashure-stats">'
                . self::stat('Pendente', (int) $stats['pending'], 'neutral', 'ti ti-clock')
                . self::stat('Em andamento', (int) $stats['in_progress'], 'blue', 'ti ti-tool')
                . self::stat('Retirada', (int) $stats['withdrawal'], 'amber', 'ti ti-truck-delivery')
                . self::stat('Concluído', (int) $stats['done'], 'green', 'ti ti-circle-check')
                . self::stat('Backup pendente', $backupPending, 'purple', 'ti ti-database')
                . '</div>';
        }

        if ($approvals !== []) {
            $out .= '<div class="dashure-alert"><i class="ti ti-gavel" aria-hidden="true"></i><div><strong>Aguardando sua decisão</strong><ul>';
            foreach ($approvals as $boardId => $total) {
                $out .= '<li><a href="' . self::h($kanban($boardId)) . '">' . self::h($boardNames[$boardId] ?? ('Quadro #' . $boardId))
                    . '</a> — ' . $total . ' pedido' . ($total === 1 ? '' : 's') . '</li>';
            }
            $out .= '</ul></div></div>';
        }

        $out .= '<h4 class="dashure-subtitle">Meus cards ativos</h4>';
        if ($cards === []) {
            $out .= '<p class="dashure-muted">Nenhum card em andamento ou retirada com você.</p>';
        } else {
            $out .= '<ul class="dashure-list">';
            foreach ($cards as $card) {
                $isWithdrawal = (string) $card['status'] === 'withdrawal';
                $boardId = (int) $card['plugin_tralla_boards_id'];
                $out .= '<li><a href="' . self::h($kanban($boardId, (int) $card['id'])) . '">'
                    . '<span class="dashure-pill ' . ($isWithdrawal ? 'is-amber' : 'is-blue') . '">'
                    . ($isWithdrawal ? 'Retirada' : 'Em andamento') . '</span>'
                    . '<span class="dashure-list-main"><strong>' . self::h($card['name']) . '</strong>'
                    . '<small>' . self::h($boardNames[$boardId] ?? '')
                    . ((int) $card['machines_total'] > 0 ? ' · ' . (int) $card['machines_total'] . ' máquina(s)' : '')
                    . '</small></span>';
                if ((int) $card['backup_left'] > 0) {
                    $out .= '<span class="dashure-pill is-purple"><i class="ti ti-database" aria-hidden="true"></i> Backup ×' . (int) $card['backup_left'] . '</span>';
                }
                $out .= '</a></li>';
            }
            $out .= '</ul>';
        }

        return $out . '</section>';
    }

    /* ------------------------------------------------------------ Assignat-ure */

    private static function renderAssignature(int $userId): string
    {
        global $DB;

        if (!Plugin::isPluginActive('assignature') || !class_exists('PluginAssignatureScope')
            || !Session::haveRight('plugin_assignature_view', READ)) {
            return '';
        }

        $scopes = PluginAssignatureScope::forUser($userId);
        $seePending = [];
        $seeDone = [];
        foreach ($scopes as $sourceId => $scope) {
            if ((int) ($scope['see_pending'] ?? 0) === 1) {
                $seePending[] = (int) $sourceId;
            }
            if ((int) ($scope['see_done'] ?? 0) === 1) {
                $seeDone[] = (int) $sourceId;
            }
        }
        $indexUrl = self::pluginUrl('assignature', '/front/index.php');

        $pendingBySource = [];
        if ($seePending !== []) {
            foreach ($DB->request([
                'SELECT'    => ['s.id', 's.name', 'COUNT' => 'r.id AS total'],
                'FROM'      => 'glpi_plugin_assignature_requests AS r',
                'INNER JOIN' => ['glpi_plugin_assignature_sources AS s' => ['ON' => ['r' => 'sources_id', 's' => 'id']]],
                'WHERE'     => [
                    'r.sources_id' => $seePending, 'r.status' => 'pending', 'r.is_active' => 1,
                    'r.batches_id' => 0, 's.is_active' => 1,
                ],
                'GROUPBY'   => ['s.id', 's.name'],
                'ORDER'     => 's.name',
            ]) as $row) {
                $pendingBySource[] = $row;
            }
        }
        $pendingTotal = array_sum(array_map(static fn ($r) => (int) $r['total'], $pendingBySource));

        $awaiting = ['awaiting_remote' => 0, 'awaiting_upload' => 0];
        foreach ($DB->request([
            'SELECT' => ['status', 'COUNT' => 'id AS total'],
            'FROM'   => 'glpi_plugin_assignature_batches',
            'WHERE'  => ['status' => array_keys($awaiting), 'created_by_users_id' => $userId],
            'GROUPBY' => 'status',
        ]) as $row) {
            $awaiting[(string) $row['status']] = (int) $row['total'];
        }

        $signedToday = 0;
        if ($seeDone !== []) {
            $signedToday = countElementsInTable('glpi_plugin_assignature_requests', [
                'sources_id' => $seeDone, 'status' => 'done',
                'signed_at'  => ['>=', date('Y-m-d 00:00:00')],
            ]);
        }

        if ($seePending === [] && $seeDone === [] && array_sum($awaiting) === 0) {
            return '';
        }

        $out = '<section class="dashure-card"><header class="dashure-card-head">'
            . '<h3><i class="ti ti-signature" aria-hidden="true"></i> Assignat-ure</h3>'
            . '<a href="' . self::h($indexUrl) . '">Abrir assinaturas <i class="ti ti-arrow-right" aria-hidden="true"></i></a></header>'
            . '<div class="dashure-stats">'
            . self::stat('Aguardando assinatura', (int) $pendingTotal, 'amber', 'ti ti-writing-sign', $indexUrl)
            . self::stat('Enviados por e-mail', $awaiting['awaiting_remote'], 'blue', 'ti ti-mail-forward', $indexUrl)
            . self::stat('Papel sem upload', $awaiting['awaiting_upload'], 'red', 'ti ti-file-upload', $indexUrl)
            . self::stat('Assinados hoje', $signedToday, 'green', 'ti ti-circle-check', $indexUrl)
            . '</div>';

        if ($pendingBySource !== []) {
            $out .= '<ul class="dashure-chips">';
            foreach ($pendingBySource as $row) {
                $out .= '<li><a href="' . self::h($indexUrl) . '">' . self::h($row['name'])
                    . ' <b>' . (int) $row['total'] . '</b></a></li>';
            }
            $out .= '</ul>';
        }

        return $out . '</section>';
    }

    /* ---------------------------------------------------------- Avisos / atalhos */

    private static function renderNotices(): string
    {
        $notices = PluginDashureStore::activeNotices();
        $icons = ['info' => 'ti ti-info-circle', 'success' => 'ti ti-sparkles', 'warning' => 'ti ti-alert-triangle', 'danger' => 'ti ti-alert-octagon'];

        $out = '<section class="dashure-card"><header class="dashure-card-head">'
            . '<h3><i class="ti ti-speakerphone" aria-hidden="true"></i> Avisos</h3></header>';
        if ($notices === []) {
            return $out . '<p class="dashure-muted">Nenhum aviso no momento.</p></section>';
        }
        foreach ($notices as $notice) {
            $level = (string) $notice['level'];
            $out .= '<article class="dashure-notice is-' . self::h($level) . '">'
                . '<header><i class="' . self::h($icons[$level] ?? $icons['info']) . '" aria-hidden="true"></i>'
                . '<strong>' . self::h($notice['title']) . '</strong>'
                . ((int) $notice['is_pinned'] === 1 ? '<i class="ti ti-pin dashure-pin" title="Fixado" aria-label="Fixado"></i>' : '')
                . '</header>';
            if (trim((string) $notice['body']) !== '') {
                $out .= '<p>' . nl2br(self::h($notice['body'])) . '</p>';
            }
            $date = (string) ($notice['date_creation'] ?? '');
            $author = (int) $notice['users_id'] > 0 ? getUserName((int) $notice['users_id']) : '';
            $out .= '<footer>' . self::h(trim(($date !== '' ? date('d/m/Y', strtotime($date)) : '') . ($author !== '' ? ' · ' . strip_tags((string) $author) : ''), ' ·')) . '</footer>'
                . '</article>';
        }
        return $out . '</section>';
    }

    private static function renderLinks(): string
    {
        $links = PluginDashureStore::links();
        if ($links === []) {
            return '';
        }
        $out = '<nav class="dashure-links" aria-label="Atalhos">';
        foreach ($links as $link) {
            $out .= '<a href="' . self::h($link['url']) . '"'
                . ((int) $link['new_window'] === 1 ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
                . '<i class="' . self::h($link['icon']) . '" aria-hidden="true"></i><span>' . self::h($link['name']) . '</span></a>';
        }
        return $out . '</nav>';
    }
}
