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
        if ($item instanceof Central && Session::haveRight('plugin_dashure', READ)) {
            return [1 => self::createTabEntry('Início', 0, null, 'ti ti-home')];
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Central && Session::haveRight('plugin_dashure', READ)) {
            echo self::render();
        }
        return true;
    }

    public static function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function pluginUrlPublic(string $path = ''): string
    {
        return self::pluginUrl('dashure', $path);
    }

    private static function pluginUrl(string $plugin, string $path = ''): string
    {
        global $CFG_GLPI;
        return (string) $CFG_GLPI['root_doc'] . '/plugins/' . $plugin . $path;
    }

    public static function render(): string
    {
        $userId = (int) Session::getLoginUserID();

        // Sub-abas: [chave, título, ícone, renderizador]. As vazias (sem acesso) não aparecem.
        $definitions = [
            ['zabbix', 'Zabbix', 'ti ti-activity-heartbeat', static fn (): string => PluginDashureZabbix::canView() ? PluginDashureZabbix::renderShell() : ''],
            ['preventiva', 'Preventiva', 'ti ti-clipboard-check', static fn (): string => self::renderPreventiva()],
            ['notes', 'Anotações', 'ti ti-lock', static fn (): string => PluginDashureNote::canView() ? self::renderNotes() : ''],
            ['tralla', 'Tralla', 'ti ti-layout-kanban', static fn (): string => self::renderTralla($userId)],
            ['assignature', 'Assignat-ure', 'ti ti-signature', static fn (): string => self::renderAssignature($userId)],
            ['notices', 'Avisos e atalhos', 'ti ti-speakerphone', static fn (): string => self::renderLinks() . self::renderNotices()],
        ];
        $tabs = [];
        foreach ($definitions as [$key, $label, $icon, $renderer]) {
            try {
                $html = $renderer();
            } catch (Throwable $e) {
                // Um plugin com problema não pode derrubar a página inicial inteira.
                $html = '<section class="dashure-card"><p class="dashure-muted">Não foi possível carregar esta seção.</p></section>';
                trigger_error('[dashure] ' . $key . ': ' . $e->getMessage(), E_USER_WARNING);
            }
            if ($html !== '') {
                $tabs[$key] = [$label, $icon, $html];
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
        $out .= '<header class="dashure-hero">' . self::brandLogo() . '<div class="dashure-hero-text"><h2>' . self::h($greeting . ', ' . $firstName) . '</h2>'
            . '<p>' . self::h(ucfirst($today)) . '</p></div>';
        if (PluginDashureStore::canManage()) {
            $out .= '<a class="dashure-btn dashure-btn-ghost" href="' . self::h(self::pluginUrl('dashure', '/front/admin.php')) . '">'
                . '<i class="ti ti-settings" aria-hidden="true"></i> Avisos e atalhos</a>';
        }
        $out .= '</header>';

        $out .= '<div class="dashure-tabs" role="tablist" data-dashure-tabs>';
        $first = true;
        foreach ($tabs as $key => [$label, $icon]) {
            $out .= '<button type="button" role="tab" class="dashure-tab' . ($first ? ' is-active' : '') . '"'
                . ' aria-selected="' . ($first ? 'true' : 'false') . '" data-dashure-tab="' . self::h($key) . '">'
                . '<i class="' . self::h($icon) . '" aria-hidden="true"></i> ' . self::h($label) . '</button>';
            $first = false;
        }
        $out .= '</div>';
        $first = true;
        foreach ($tabs as $key => [, , $html]) {
            $out .= '<div class="dashure-pane" role="tabpanel" data-dashure-pane="' . self::h($key) . '"' . ($first ? '' : ' hidden') . '>'
                . $html . '</div>';
            $first = false;
        }

        return $out . '</div>';
    }

    /** Logo do plugin (public/logo.png), com cache pela data do arquivo. */
    public static function brandLogo(): string
    {
        $file = GLPI_ROOT . '/plugins/dashure/public/logo.png';
        return '<img class="dashure-brand-logo" src="' . self::h(self::pluginUrl('dashure', '/logo.png?v=' . (int) @filemtime($file)))
            . '" alt="" width="60" height="60">';
    }

    private static function stat(string $label, int $value, string $tone, string $icon, string $url = ''): string
    {
        $tag = $url !== '' ? 'a href="' . self::h($url) . '"' : 'div';
        $close = $url !== '' ? 'a' : 'div';
        return '<' . $tag . ' class="dashure-stat is-' . self::h($tone) . ($value === 0 ? ' is-zero' : '') . '">'
            . '<i class="' . self::h($icon) . '" aria-hidden="true"></i>'
            . '<strong>' . $value . '</strong><span>' . self::h($label) . '</span></' . $close . '>';
    }

    /* -------------------------------------------------------------- Anotações */

    private static function renderNotes(): string
    {
        $notes = PluginDashureNote::visible();
        $formUrl = self::pluginUrl('dashure', '/front/note.form.php');
        $ajaxUrl = self::pluginUrl('dashure', '/front/ajax.php');

        $categories = [];
        foreach ($notes as $note) {
            if ((string) $note['category'] !== '') {
                $categories[(string) $note['category']] = true;
            }
        }
        ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);

        $out = '<section class="dashure-notes" data-dashure-notes data-ajax="' . self::h($ajaxUrl) . '">'
            . '<div class="dashure-notes-bar">'
            . '<label class="dashure-search"><i class="ti ti-search" aria-hidden="true"></i>'
            . '<input type="search" class="form-control" placeholder="Buscar por título, categoria, login ou endereço" data-dashure-search></label>';
        if (PluginDashureNote::canCreate()) {
            $out .= '<a class="dashure-btn" href="' . self::h($formUrl) . '"><i class="ti ti-plus" aria-hidden="true"></i> Nova anotação</a>';
        }
        $out .= '</div>';

        if ($categories !== []) {
            $out .= '<div class="dashure-filter" data-dashure-filter><button type="button" class="is-active" data-category="">Todas</button>';
            foreach (array_keys($categories) as $category) {
                $out .= '<button type="button" data-category="' . self::h($category) . '">' . self::h($category) . '</button>';
            }
            $out .= '</div>';
        }

        if ($notes === []) {
            return $out . '<p class="dashure-muted dashure-empty"><i class="ti ti-lock" aria-hidden="true"></i> Nenhuma anotação liberada para o seu perfil.</p></section>';
        }

        $out .= '<div class="dashure-note-grid">';
        foreach ($notes as $note) {
            $id = (int) $note['id'];
            $url = (string) $note['url'];
            $search = mb_strtolower(implode(' ', [$note['title'], $note['category'], $note['login'], $url]));
            $out .= '<article class="dashure-note is-' . self::h($note['color']) . '" data-note-id="' . $id . '"'
                . ' data-search="' . self::h($search) . '" data-category="' . self::h($note['category']) . '">'
                . '<header><h4>' . ((int) $note['is_pinned'] === 1 ? '<i class="ti ti-pin" title="Fixada" aria-label="Fixada"></i> ' : '')
                . self::h($note['title']) . '</h4>'
                . ((string) $note['category'] !== '' ? '<span class="dashure-pill">' . self::h($note['category']) . '</span>' : '')
                . '</header>';
            if ($url !== '') {
                $out .= '<div class="dashure-field"><span>Endereço</span>'
                    . (preg_match('#^https?://#i', $url)
                        ? '<a href="' . self::h($url) . '" target="_blank" rel="noopener noreferrer">' . self::h($url) . '</a>'
                        : '<code>' . self::h($url) . '</code>')
                    . '</div>';
            }
            if ((string) $note['login'] !== '') {
                $out .= '<div class="dashure-field"><span>Login</span><code>' . self::h($note['login']) . '</code>'
                    . '<button type="button" class="dashure-icon-btn" data-note-action="copy_login" title="Copiar login" aria-label="Copiar login"><i class="ti ti-copy"></i></button></div>';
            }
            if ($note['has_password']) {
                $out .= '<div class="dashure-field"><span>Senha</span><code data-note-password>••••••••</code>'
                    . '<button type="button" class="dashure-icon-btn" data-note-action="reveal" title="Mostrar senha" aria-label="Mostrar senha"><i class="ti ti-eye"></i></button>'
                    . '<button type="button" class="dashure-icon-btn" data-note-action="copy" title="Copiar senha" aria-label="Copiar senha"><i class="ti ti-copy"></i></button></div>';
            }
            if ($note['has_notes']) {
                $out .= '<div class="dashure-note-extra" data-note-extra hidden></div>';
                if (!$note['has_password']) {
                    $out .= '<button type="button" class="dashure-link-btn" data-note-action="reveal"><i class="ti ti-eye"></i> Ver observações</button>';
                }
            }
            $out .= '<footer><small>Atualizada em ' . self::h(date('d/m/Y', strtotime((string) $note['date_mod']))) . '</small>';
            if ($note['can_edit']) {
                $out .= '<a class="dashure-icon-btn" href="' . self::h($formUrl . '?id=' . $id) . '" title="Editar" aria-label="Editar"><i class="ti ti-pencil"></i></a>';
            }
            $out .= '</footer></article>';
        }
        $out .= '</div><p class="dashure-muted" data-dashure-noresult hidden>Nenhuma anotação encontrada.</p>';

        return $out . '</section>';
    }

    /* -------------------------------------------------------------- Preventiva */

    /** Quem já respondeu o formulário de Preventiva e quem falta (escolas = entidades sem filhas). */
    public static function renderPreventiva(?string $month = null): string
    {
        global $DB;

        if (!Plugin::isPluginActive('tralla') || !Session::haveRight('plugin_tralla', READ)) {
            return '';
        }
        if (!class_exists('PluginTrallaPreventiva')) {
            $file = GLPI_ROOT . '/plugins/tralla/inc/preventiva.class.php';
            if (!is_file($file)) {
                return '';
            }
            require_once $file;
        }
        $all = PluginTrallaPreventiva::boards();
        if ($all === []) {
            return '';
        }

        // Meses disponíveis: do primeiro quadro até o mês atual (os futuros só aparecem quando chegarem).
        $current = date('Y-m');
        $first = $current;
        foreach ($all as $candidate) {
            $period = PluginTrallaPreventiva::normalizePeriod((string) $candidate['period']);
            if ($period !== '' && $period < $first) {
                $first = $period;
            }
        }
        $months = [];
        for ($cursor = new DateTimeImmutable($current . '-01'); $cursor->format('Y-m') >= $first; $cursor = $cursor->modify('-1 month')) {
            $months[] = $cursor->format('Y-m');
        }
        $selected = $month !== null ? PluginTrallaPreventiva::normalizePeriod($month) : '';
        if ($selected === '' || !in_array($selected, $months, true)) {
            $selected = $current;
        }
        $names = ['01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril', '05' => 'Maio', '06' => 'Junho',
            '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'];
        $picker = '<label class="dashure-prev-month"><i class="ti ti-calendar-month" aria-hidden="true"></i>'
            . '<select class="form-select" data-prev-month aria-label="Mês">';
        foreach ($months as $m) {
            $picker .= '<option value="' . $m . '"' . ($m === $selected ? ' selected' : '') . '>'
                . $names[substr($m, 5, 2)] . '/' . substr($m, 0, 4) . '</option>';
        }
        $picker .= '</select></label>';
        $wrapOpen = '<section class="dashure-card dashure-prev" data-dashure-prev data-ajax="'
            . self::h(self::pluginUrlPublic('/front/ajax.php')) . '">';

        $board = PluginTrallaPreventiva::targetBoard($selected);
        if ($board === null || !PluginTrallaBoard::canViewBoard((int) $board['id'])) {
            return $wrapOpen . '<header class="dashure-card-head"><h3><i class="ti ti-clipboard-check" aria-hidden="true"></i> Preventiva</h3>'
                . $picker . '</header>'
                . '<p class="dashure-muted">Não há quadro Preventiva de ' . self::h($names[substr($selected, 5, 2)] . '/' . substr($selected, 0, 4))
                . '. Crie o quadro no Tralla para receber as respostas desse mês.</p></section>';
        }
        $boardId = (int) $board['id'];

        // Respostas = cards do quadro Preventiva que não foram excluídos.
        $answers = [];
        foreach ($DB->request([
            'SELECT'    => ['c.id', 'c.entities_id', 'c.name', 'c.date_creation', 'a.answered_at', 'a.has_problems'],
            'FROM'      => 'glpi_plugin_tralla_cards AS c',
            'LEFT JOIN' => ['glpi_plugin_tralla_preventiva_answers AS a' => ['ON' => ['a' => 'plugin_tralla_cards_id', 'c' => 'id']]],
            'WHERE'     => ['c.plugin_tralla_boards_id' => $boardId, 'c.stage' => 1, 'c.is_deleted' => 0],
            'ORDER'     => 'c.id DESC',
        ]) as $row) {
            $entityId = (int) $row['entities_id'];
            $when = (string) ($row['answered_at'] ?: $row['date_creation']);
            if (!isset($answers[$entityId])) {
                $answers[$entityId] = ['name' => (string) $row['name'], 'count' => 0, 'last' => $when, 'card' => (int) $row['id'], 'problems' => false];
            }
            $answers[$entityId]['count']++;
            if ($when > $answers[$entityId]['last']) {
                $answers[$entityId]['last'] = $when;
                $answers[$entityId]['card'] = (int) $row['id'];
            }
            if ($row['has_problems'] !== null && (int) $row['has_problems'] === 1) {
                $answers[$entityId]['problems'] = true;
            }
        }

        $schools = [];
        foreach (PluginTrallaPreventiva::schools() as $school) {
            $schools[(int) $school['id']] = $school['nome'];
        }
        $missing = array_diff_key($schools, $answers);
        $responded = [];
        foreach ($answers as $entityId => $info) {
            $responded[$entityId] = $info + ['listed' => isset($schools[$entityId])];
        }
        uasort($responded, static fn ($a, $b) => strcmp($b['last'], $a['last']));
        $respondedSchools = count(array_intersect_key($answers, $schools));
        $withProblems = count(array_filter(array_intersect_key($answers, $schools), static fn ($a) => $a['problems']));
        $total = count($schools);
        $pct = $total > 0 ? (int) round($respondedSchools * 100 / $total) : 0;
        $kanban = self::pluginUrl('tralla', '/front/kanban.php?boards_id=' . $boardId);

        $out = $wrapOpen
            . '<header class="dashure-card-head"><h3><i class="ti ti-clipboard-check" aria-hidden="true"></i> '
            . self::h((string) $board['name']) . '</h3>' . $picker
            . '<a href="' . self::h($kanban) . '">Abrir quadro <i class="ti ti-arrow-right" aria-hidden="true"></i></a></header>';

        // Contadores clicáveis: filtram as listas abaixo (clicar de novo volta a mostrar tudo).
        $out .= '<div class="dashure-stats">';
        foreach ([
            ['all', 'Escolas', $total, 'blue', 'ti ti-building-community'],
            ['responded', 'Responderam', $respondedSchools, 'green', 'ti ti-circle-check'],
            ['missing', 'Faltam responder', count($missing), 'amber', 'ti ti-hourglass'],
            ['problems', 'Relataram problemas', $withProblems, 'red', 'ti ti-alert-triangle'],
            ['noproblems', 'Não relataram problemas', $respondedSchools - $withProblems, 'neutral', 'ti ti-mood-smile'],
        ] as [$key, $label, $value, $tone, $icon]) {
            $out .= '<button type="button" class="dashure-stat is-' . $tone . ($value === 0 ? ' is-zero' : '') . '" data-prev-filter="' . $key . '" aria-pressed="false">'
                . '<i class="' . $icon . '" aria-hidden="true"></i><strong>' . (int) $value . '</strong><span>' . self::h($label) . '</span></button>';
        }
        $out .= '</div>';

        $out .= '<div class="dashure-prev-progress" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100">'
            . '<span style="width:' . $pct . '%"></span><strong>' . $pct . '% das escolas responderam</strong></div>';

        $out .= '<div class="dashure-prev-bar"><label class="dashure-search"><i class="ti ti-search" aria-hidden="true"></i>'
            . '<input type="search" class="form-control" placeholder="Buscar escola" data-prev-search></label>'
            . '<button type="button" class="dashure-btn dashure-btn-ghost" data-prev-chart-toggle aria-expanded="false">'
            . '<i class="ti ti-chart-pie" aria-hidden="true"></i> Gráfico</button>'
            . '<button type="button" class="dashure-btn dashure-btn-ghost" data-prev-copy' . ($missing === [] ? ' disabled' : '') . '>'
            . '<i class="ti ti-copy" aria-hidden="true"></i> Copiar lista de quem falta</button></div>';

        // Pizza: das escolas que responderam, quantas relataram problemas.
        $noProblems = $respondedSchools - $withProblems;
        $pProblems = $respondedSchools > 0 ? round($withProblems * 100 / $respondedSchools, 1) : 0;
        $pie = $respondedSchools > 0
            ? 'conic-gradient(#dc2626 0 ' . $pProblems . '%, #16a34a ' . $pProblems . '% 100%)'
            : 'conic-gradient(#cbd5e1 0 100%)';
        $out .= '<div class="dashure-prev-chart" data-prev-chart hidden>'
            . '<div class="dashure-prev-pie" style="background:' . $pie . '" role="img" aria-label="Relataram problemas: ' . $withProblems
            . ' de ' . $respondedSchools . '"><span><strong>' . $respondedSchools . '</strong>responderam</span></div>'
            . '<ul class="dashure-prev-legend">'
            . '<li><i style="background:#dc2626"></i> Relataram problemas <strong>' . $withProblems . '</strong> (' . $pProblems . '%)</li>'
            . '<li><i style="background:#16a34a"></i> Não relataram problemas <strong>' . $noProblems . '</strong> ('
            . ($respondedSchools > 0 ? round(100 - $pProblems, 1) : 0) . '%)</li></ul></div>';

        $out .= '<div class="dashure-prev-grid">';

        // Faltam
        $out .= '<div data-prev-col="missing"><h4 class="dashure-subtitle" data-prev-title="Faltam responder">Faltam responder (' . count($missing) . ')</h4>';
        if ($missing === []) {
            $out .= '<p class="dashure-muted"><i class="ti ti-mood-smile"></i> Todas as escolas já responderam.</p>';
        } else {
            $out .= '<ul class="dashure-list dashure-prev-list">';
            foreach ($missing as $name) {
                $out .= '<li data-prev-name="' . self::h(mb_strtolower($name)) . '" data-prev-missing="' . self::h($name) . '">'
                    . '<div class="dashure-list-row"><i class="ti ti-clock-hour-4" aria-hidden="true"></i>'
                    . '<span class="dashure-list-main"><strong>' . self::h($name) . '</strong></span></div></li>';
            }
            $out .= '</ul>';
        }
        $out .= '</div>';

        // Responderam
        $out .= '<div data-prev-col="responded"><h4 class="dashure-subtitle" data-prev-title="Já responderam">Já responderam (' . count($responded) . ')</h4>';
        if ($responded === []) {
            $out .= '<p class="dashure-muted">Nenhuma resposta ainda.</p>';
        } else {
            $out .= '<ul class="dashure-list dashure-prev-list">';
            foreach ($responded as $entityId => $info) {
                $cardUrl = $kanban . '&card=' . (int) $info['card'];
                $out .= '<li data-prev-name="' . self::h(mb_strtolower($info['name'])) . '" data-prev-problems="' . ($info['problems'] ? '1' : '0') . '"><a href="' . self::h($cardUrl) . '" data-prev-card="' . self::h($cardUrl . '&embed=1') . '">'
                    . '<span class="dashure-pill ' . ($info['problems'] ? 'is-red' : 'is-green') . '">' . ($info['problems'] ? 'Com problemas' : 'Sem problemas') . '</span>'
                    . '<span class="dashure-list-main"><strong>' . self::h($info['name']) . '</strong>'
                    . '<small>' . self::h(date('d/m/Y H:i', strtotime($info['last'])))
                    . ($info['count'] > 1 ? ' · ' . (int) $info['count'] . ' respostas' : '')
                    . (!$info['listed'] ? ' · fora da lista do formulário' : '') . '</small></span></a></li>';
            }
            $out .= '</ul>';
        }
        $out .= '</div></div></section>';

        return $out;
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
