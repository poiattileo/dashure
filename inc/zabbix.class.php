<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

/**
 * Painel Zabbix: lê a API JSON-RPC do Zabbix (somente leitura) pelo servidor do GLPI.
 * O token fica criptografado com a chave do GLPI; nada da configuração vai para o código.
 */
class PluginDashureZabbix
{
    public const TABLE = 'glpi_plugin_dashure_zabbix';
    public const SEVERITIES = [
        0 => ['Não classificado', '#97aab3'],
        1 => ['Informação', '#7499ff'],
        2 => ['Aviso', '#ffc859'],
        3 => ['Média', '#ffa059'],
        4 => ['Alta', '#e97659'],
        5 => ['Desastre', '#e45959'],
    ];

    public static function canView(): bool
    {
        return Session::haveRight(PluginDashureProfile::RIGHT_ZABBIX, READ);
    }

    public static function canConfigure(): bool
    {
        return Session::haveRight(PluginDashureProfile::RIGHT_ZABBIX, UPDATE);
    }

    public static function config(): array
    {
        global $DB;

        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => 1]])->current();
        $defaults = [
            'url' => '', 'token_encrypted' => '', 'min_severity' => 2, 'groupids' => '',
            'verify_ssl' => 1, 'refresh' => 60, 'max_problems' => 30,
        ];
        return is_array($row) ? array_merge($defaults, $row) : $defaults;
    }

    public static function isConfigured(): bool
    {
        $config = self::config();
        return trim((string) $config['url']) !== '' && (string) $config['token_encrypted'] !== '';
    }

    public static function saveConfig(array $input): void
    {
        global $DB;

        $url = rtrim(trim((string) ($input['url'] ?? '')), '/');
        $url = preg_replace('#/api_jsonrpc\.php$#i', '', $url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('O endereço do Zabbix deve começar com http:// ou https://.');
        }
        $groupids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['groupids'] ?? [])))));
        $data = [
            'url'          => mb_substr($url, 0, 500),
            'min_severity' => max(0, min(5, (int) ($input['min_severity'] ?? 2))),
            'groupids'     => implode(',', $groupids),
            'verify_ssl'   => !empty($input['verify_ssl']) ? 1 : 0,
            'refresh'      => max(30, min(900, (int) ($input['refresh'] ?? 60))),
            'max_problems' => max(5, min(200, (int) ($input['max_problems'] ?? 30))),
            'date_mod'     => date('Y-m-d H:i:s'),
        ];
        $token = trim((string) ($input['token'] ?? ''));
        if ($token !== '') {
            $data['token_encrypted'] = (new GLPIKey())->encrypt($token);
        } elseif (!empty($input['clear_token'])) {
            $data['token_encrypted'] = '';
        }
        if (countElementsInTable(self::TABLE, ['id' => 1])) {
            $DB->update(self::TABLE, $data, ['id' => 1]);
        } else {
            $DB->insert(self::TABLE, $data + ['id' => 1]);
        }
        self::clearCache();
    }

    /* ---------------------------------------------------------------- API */

    private static ?string $version = null;

    public static function rpc(array $config, string $method, array $params, bool $auth = true)
    {
        $endpoint = rtrim((string) $config['url'], '/') . '/api_jsonrpc.php';
        $payload = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1];
        $headers = ['Content-Type: application/json-rpc'];
        $token = $auth ? (string) (new GLPIKey())->decrypt((string) $config['token_encrypted']) : '';
        if ($auth) {
            if ($token === '') {
                throw new RuntimeException('Token da API do Zabbix não configurado.');
            }
            // Zabbix 6.4+ aceita o token no cabeçalho; versões antigas só no corpo ("auth").
            if (version_compare(self::version($config), '6.4', '>=')) {
                $headers[] = 'Authorization: Bearer ' . $token;
            } else {
                $payload['auth'] = $token;
            }
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => (int) $config['verify_ssl'] === 1,
            CURLOPT_SSL_VERIFYHOST => (int) $config['verify_ssl'] === 1 ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Não foi possível conectar ao Zabbix: ' . $error);
        }
        if ($status >= 400) {
            throw new RuntimeException('O Zabbix respondeu HTTP ' . $status . '.');
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Resposta inválida do Zabbix (confira o endereço).');
        }
        if (isset($json['error'])) {
            $detail = trim((string) ($json['error']['data'] ?? '') . ' ' . (string) ($json['error']['message'] ?? ''));
            throw new RuntimeException('Erro da API do Zabbix: ' . $detail);
        }
        return $json['result'] ?? null;
    }

    public static function version(?array $config = null): string
    {
        if (self::$version === null) {
            self::$version = (string) self::rpc($config ?? self::config(), 'apiinfo.version', [], false);
        }
        return self::$version;
    }

    /** Teste usado na tela de configuração. */
    public static function test(): array
    {
        $config = self::config();
        self::$version = null;
        $version = self::version($config);
        $hosts = (int) self::rpc($config, 'host.get', ['countOutput' => true, 'monitored_hosts' => true]);
        return ['version' => $version, 'hosts' => $hosts];
    }

    public static function hostGroups(): array
    {
        $config = self::config();
        $groups = (array) self::rpc($config, 'hostgroup.get', [
            'output' => ['groupid', 'name'], 'with_monitored_hosts' => true, 'sortfield' => 'name',
        ]);
        $out = [];
        foreach ($groups as $group) {
            $out[(int) $group['groupid']] = (string) $group['name'];
        }
        return $out;
    }

    private static function cacheKey(): string
    {
        return 'plugin_dashure_zabbix_summary';
    }

    public static function clearCache(): void
    {
        global $GLPI_CACHE;
        try {
            $GLPI_CACHE?->delete(self::cacheKey());
        } catch (Throwable $e) {
        }
    }

    /** Resumo do painel; fica em cache por ~metade do intervalo de atualização. */
    public static function summary(): array
    {
        global $GLPI_CACHE;

        $config = self::config();
        try {
            $cached = $GLPI_CACHE?->get(self::cacheKey());
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable $e) {
        }

        $groupids = array_filter(array_map('intval', explode(',', (string) $config['groupids'])));
        $groupFilter = $groupids !== [] ? ['groupids' => $groupids] : [];
        $min = (int) $config['min_severity'];

        $problems = (array) self::rpc($config, 'problem.get', $groupFilter + [
            'output'     => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'suppressed'],
            'source'     => 0,
            'object'     => 0,
            'suppressed' => false,
            'severities' => range($min, 5),
            'sortfield'  => ['eventid'],
            'sortorder'  => 'DESC',
            'limit'      => 500,
        ]);

        // Host de cada problema (via trigger) — só triggers e hosts monitorados.
        $hostsByTrigger = [];
        $triggerIds = array_values(array_unique(array_column($problems, 'objectid')));
        if ($triggerIds !== []) {
            foreach ((array) self::rpc($config, 'trigger.get', [
                'output' => ['triggerid'], 'triggerids' => $triggerIds, 'monitored' => true,
                'selectHosts' => ['hostid', 'name'],
            ]) as $trigger) {
                $hostsByTrigger[(string) $trigger['triggerid']] = $trigger['hosts'][0] ?? null;
            }
        }

        $counts = array_fill(0, 6, 0);
        $list = [];
        foreach ($problems as $problem) {
            $host = $hostsByTrigger[(string) $problem['objectid']] ?? null;
            if ($host === null) {
                continue;
            }
            $severity = (int) $problem['severity'];
            $counts[$severity]++;
            $list[] = [
                'eventid'  => (string) $problem['eventid'],
                'hostid'   => (string) $host['hostid'],
                'host'     => (string) $host['name'],
                'name'     => (string) $problem['name'],
                'severity' => $severity,
                'clock'    => (int) $problem['clock'],
                'ack'      => (int) $problem['acknowledged'] === 1,
            ];
        }
        usort($list, static fn ($a, $b) => [$b['severity'], $b['clock']] <=> [$a['severity'], $a['clock']]);

        // Disponibilidade dos hosts (interfaces com available = 2 → indisponível).
        $hosts = (array) self::rpc($config, 'host.get', $groupFilter + [
            'output' => ['hostid', 'name'], 'monitored_hosts' => true,
            'selectInterfaces' => ['available', 'type'],
        ]);
        $down = [];
        foreach ($hosts as $host) {
            $interfaces = (array) ($host['interfaces'] ?? []);
            if ($interfaces === []) {
                continue;
            }
            $bad = array_filter($interfaces, static fn ($i) => (int) ($i['available'] ?? 0) === 2);
            if (count($bad) === count($interfaces)) {
                $down[] = ['hostid' => (string) $host['hostid'], 'name' => (string) $host['name']];
            }
        }
        usort($down, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $summary = [
            'counts'      => $counts,
            'problems'    => array_slice($list, 0, (int) $config['max_problems']),
            'total'       => count($list),
            'hosts_total' => count($hosts),
            'hosts_down'  => $down,
            'hosts_with_problems' => count(array_unique(array_column($list, 'hostid'))),
            'fetched_at'  => time(),
        ];
        try {
            $GLPI_CACHE?->set(self::cacheKey(), $summary, max(15, intdiv((int) $config['refresh'], 2)));
        } catch (Throwable $e) {
        }
        return $summary;
    }

    /* ------------------------------------------------------------- Render */

    private static function ago(int $clock): string
    {
        $diff = max(0, time() - $clock);
        if ($diff < 3600) {
            return max(1, intdiv($diff, 60)) . ' min';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . ' h ' . intdiv($diff % 3600, 60) . ' min';
        }
        return intdiv($diff, 86400) . ' d ' . intdiv($diff % 86400, 3600) . ' h';
    }

    /** Casca da sub-aba; o conteúdo vem por AJAX (renderPanel). */
    public static function renderShell(): string
    {
        $h = [PluginDashureHome::class, 'h'];
        if (!self::isConfigured()) {
            $msg = '<p class="dashure-muted dashure-empty"><i class="ti ti-plug-connected-x"></i> O Zabbix ainda não foi configurado.';
            if (self::canConfigure()) {
                $msg .= ' <a href="' . $h(self::configUrl()) . '">Configurar agora</a>';
            }
            return '<section class="dashure-card">' . $msg . '</p></section>';
        }
        $config = self::config();
        global $CFG_GLPI;
        return '<section class="dashure-zabbix" data-dashure-zabbix data-refresh="' . (int) $config['refresh'] . '"'
            . ' data-ajax="' . $h(PluginDashureHome::pluginUrlPublic('/front/ajax.php')) . '"'
            . ' data-echarts="' . $h((string) $CFG_GLPI['root_doc'] . '/lib/echarts.min.js') . '">'
            . '<div data-zabbix-summary><p class="dashure-muted"><i class="ti ti-loader-2 dashure-spin"></i> Carregando dados do Zabbix…</p></div>'
            . PluginDashureZabbixChart::renderGrid()
            . '</section>';
    }

    public static function configUrl(): string
    {
        return PluginDashureHome::pluginUrlPublic('/front/zabbix.php');
    }

    public static function renderPanel(): string
    {
        $h = [PluginDashureHome::class, 'h'];
        $config = self::config();
        $data = self::summary();
        $base = rtrim((string) $config['url'], '/');
        $problemsUrl = $base . '/zabbix.php?action=problem.view';

        $out = '<div class="dashure-card-head"><h3><i class="ti ti-activity-heartbeat" aria-hidden="true"></i> Zabbix</h3>'
            . '<span class="dashure-zabbix-meta">Atualizado às ' . date('H:i:s', (int) $data['fetched_at'])
            . ' · <a href="' . $h($problemsUrl) . '" target="_blank" rel="noopener noreferrer">Abrir no Zabbix <i class="ti ti-external-link"></i></a>';
        if (self::canConfigure()) {
            $out .= ' · <a href="' . $h(self::configUrl()) . '">Configurar</a>';
        }
        $out .= '</span></div>';

        $out .= '<div class="dashure-stats dashure-sev">';
        for ($s = 5; $s >= (int) $config['min_severity']; $s--) {
            [$label, $color] = self::SEVERITIES[$s];
            $out .= '<a class="dashure-stat' . ($data['counts'][$s] === 0 ? ' is-zero' : '') . '" style="--tone:' . $h($color) . '"'
                . ' href="' . $h($problemsUrl . '&severities%5B' . $s . '%5D=' . $s) . '" target="_blank" rel="noopener noreferrer">'
                . '<i class="ti ti-alert-triangle" aria-hidden="true"></i><strong>' . (int) $data['counts'][$s] . '</strong><span>' . $h($label) . '</span></a>';
        }
        $out .= '</div>';

        $okHosts = max(0, (int) $data['hosts_total'] - (int) $data['hosts_with_problems']);
        $out .= '<div class="dashure-stats">'
            . '<div class="dashure-stat is-blue"><i class="ti ti-server"></i><strong>' . (int) $data['hosts_total'] . '</strong><span>Hosts monitorados</span></div>'
            . '<div class="dashure-stat is-green' . ($okHosts === 0 ? ' is-zero' : '') . '"><i class="ti ti-circle-check"></i><strong>' . $okHosts . '</strong><span>Sem problemas</span></div>'
            . '<div class="dashure-stat is-amber' . ($data['hosts_with_problems'] === 0 ? ' is-zero' : '') . '"><i class="ti ti-alert-circle"></i><strong>' . (int) $data['hosts_with_problems'] . '</strong><span>Com problemas</span></div>'
            . '<div class="dashure-stat is-red' . ($data['hosts_down'] === [] ? ' is-zero' : '') . '"><i class="ti ti-plug-connected-x"></i><strong>' . count($data['hosts_down']) . '</strong><span>Indisponíveis</span></div>'
            . '</div>';

        $out .= '<div class="dashure-grid">';
        $out .= '<div class="dashure-main"><h4 class="dashure-subtitle">Problemas ativos (' . (int) $data['total'] . ')</h4>';
        if ($data['problems'] === []) {
            $out .= '<p class="dashure-muted"><i class="ti ti-mood-smile"></i> Nenhum problema ativo.</p>';
        } else {
            $out .= '<ul class="dashure-list dashure-problems">';
            foreach ($data['problems'] as $p) {
                [$label, $color] = self::SEVERITIES[$p['severity']];
                $out .= '<li><a href="' . $h($base . '/zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D=' . rawurlencode($p['hostid'])) . '" target="_blank" rel="noopener noreferrer" style="--tone:' . $h($color) . '">'
                    . '<span class="dashure-pill dashure-sev-pill">' . $h($label) . '</span>'
                    . '<span class="dashure-list-main"><strong>' . $h($p['name']) . '</strong><small>' . $h($p['host']) . '</small></span>'
                    . '<span class="dashure-problem-age">' . ($p['ack'] ? '<i class="ti ti-check" title="Reconhecido" aria-label="Reconhecido"></i> ' : '')
                    . $h(self::ago($p['clock'])) . '</span></a></li>';
            }
            $out .= '</ul>';
        }
        $out .= '</div><aside class="dashure-side"><h4 class="dashure-subtitle">Hosts indisponíveis (' . count($data['hosts_down']) . ')</h4>';
        if ($data['hosts_down'] === []) {
            $out .= '<p class="dashure-muted">Todos os hosts respondendo.</p>';
        } else {
            $out .= '<ul class="dashure-chips dashure-down">';
            foreach (array_slice($data['hosts_down'], 0, 60) as $host) {
                $out .= '<li><a href="' . $h($base . '/zabbix.php?action=host.view&filter_set=1&filter_name=' . rawurlencode($host['name'])) . '" target="_blank" rel="noopener noreferrer">'
                    . '<i class="ti ti-point-filled"></i> ' . $h($host['name']) . '</a></li>';
            }
            $out .= '</ul>';
        }
        $out .= '</aside></div>';

        return '<div class="dashure-card">' . $out . '</div>';
    }
}
