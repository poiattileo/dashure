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
            if (is_array($cached) && isset($cached['hosts'])) {
                return $cached;
            }
        } catch (Throwable $e) {
        }

        $groupids = array_filter(array_map('intval', explode(',', (string) $config['groupids'])));
        $groupFilter = $groupids !== [] ? ['groupids' => $groupids] : [];
        $min = (int) $config['min_severity'];

        $problems = (array) self::rpc($config, 'problem.get', $groupFilter + [
            'output'     => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'opdata'],
            'selectTags' => ['tag', 'value'],
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
                'output' => ['triggerid', 'comments'], 'triggerids' => $triggerIds, 'monitored' => true,
                'selectHosts' => ['hostid', 'name'],
            ]) as $trigger) {
                if (isset($trigger['hosts'][0])) {
                    $hostsByTrigger[(string) $trigger['triggerid']] = $trigger['hosts'][0] + ['comments' => (string) ($trigger['comments'] ?? '')];
                }
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
            $tags = [];
            foreach ((array) ($problem['tags'] ?? []) as $tag) {
                $tags[] = (string) $tag['tag'] . ((string) ($tag['value'] ?? '') !== '' ? ': ' . $tag['value'] : '');
            }
            $list[] = [
                'eventid'  => (string) $problem['eventid'],
                'hostid'   => (string) $host['hostid'],
                'host'     => (string) $host['name'],
                'name'     => (string) $problem['name'],
                'severity' => $severity,
                'clock'    => (int) $problem['clock'],
                'ack'      => (int) $problem['acknowledged'] === 1,
                'opdata'   => (string) ($problem['opdata'] ?? ''),
                'comments' => mb_substr(trim((string) $host['comments']), 0, 400),
                'tags'     => array_slice($tags, 0, 8),
            ];
        }
        usort($list, static fn ($a, $b) => [$b['severity'], $b['clock']] <=> [$a['severity'], $a['clock']]);

        // Hosts com interfaces, grupos, modelos e inventário básico.
        $rawHosts = (array) self::rpc($config, 'host.get', $groupFilter + [
            'output'                => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'description'],
            'monitored_hosts'       => true,
            'selectInterfaces'      => ['type', 'ip', 'dns', 'useip', 'port', 'available', 'error', 'main'],
            'selectHostGroups'      => ['name'],
            'selectParentTemplates' => ['name'],
            'selectInventory'       => ['os', 'hardware', 'location'],
        ]);

        // Últimos valores dos itens mais úteis (modelos Linux/Windows padrão do Zabbix).
        $metricKeys = [
            'system.cpu.util' => 'cpu', 'system.cpu.util[,,avg1]' => 'cpu',
            'vm.memory.utilization' => 'mem', 'vm.memory.util' => 'mem',
            'system.uptime' => 'uptime', 'system.sw.os' => 'os', 'system.sw.os[name]' => 'os',
            'agent.version' => 'agent', 'icmppingsec' => 'ping', 'system.cpu.num' => 'cpus',
            'vm.memory.size[total]' => 'memtotal',
        ];
        $metrics = [];
        $hostIds = array_column($rawHosts, 'hostid');
        if ($hostIds !== []) {
            foreach ((array) self::rpc($config, 'item.get', [
                'output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'units'], 'hostids' => $hostIds,
                'filter' => ['key_' => array_keys($metricKeys)], 'monitored' => true,
            ]) as $item) {
                if ((int) $item['lastclock'] > 0) {
                    $metrics[(string) $item['hostid']][$metricKeys[(string) $item['key_']]] = [(string) $item['lastvalue'], (string) $item['units']];
                }
            }
            foreach ((array) self::rpc($config, 'item.get', [
                'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'], 'hostids' => $hostIds, 'monitored' => true,
                'search' => ['key_' => ['vfs.fs.dependent.size[*,pused]', 'vfs.fs.size[*,pused]']],
                'searchWildcardsEnabled' => true, 'searchByAny' => true,
            ]) as $item) {
                if ((int) $item['lastclock'] > 0 && preg_match('/\[([^,\]]+),pused\]/', (string) $item['key_'], $m)) {
                    $metrics[(string) $item['hostid']]['fs'][trim($m[1], '"')] = (float) $item['lastvalue'];
                }
            }
        }

        $problemsByHost = [];
        foreach ($list as $problem) {
            $problemsByHost[$problem['hostid']][] = $problem;
        }

        $typeNames = [1 => 'Agente', 2 => 'SNMP', 3 => 'IPMI', 4 => 'JMX'];
        $hosts = [];
        $down = [];
        foreach ($rawHosts as $host) {
            $hostid = (string) $host['hostid'];
            $interfaces = [];
            $unavailable = 0;
            foreach ((array) ($host['interfaces'] ?? []) as $if) {
                $available = (int) ($if['available'] ?? 0);
                $unavailable += $available === 2 ? 1 : 0;
                $interfaces[] = [
                    'type'      => $typeNames[(int) $if['type']] ?? 'Interface',
                    'address'   => ((int) $if['useip'] === 1 ? (string) $if['ip'] : (string) $if['dns']) . ':' . (string) $if['port'],
                    'available' => $available,
                    'error'     => mb_substr((string) ($if['error'] ?? ''), 0, 200),
                ];
            }
            $isDown = $interfaces !== [] && $unavailable === count($interfaces);
            $hostProblems = $problemsByHost[$hostid] ?? [];
            $state = $isDown ? 'down' : ($hostProblems !== [] ? 'problem' : 'ok');
            $worst = $hostProblems !== [] ? max(array_column($hostProblems, 'severity')) : -1;
            $inventory = is_array($host['inventory'] ?? null) ? $host['inventory'] : [];
            $entry = [
                'hostid'      => $hostid,
                'name'        => (string) $host['name'],
                'technical'   => (string) $host['host'],
                'state'       => $state,
                'worst'       => $worst,
                'maintenance' => (int) ($host['maintenance_status'] ?? 0) === 1,
                'description' => mb_substr(trim((string) ($host['description'] ?? '')), 0, 300),
                'interfaces'  => $interfaces,
                'groups'      => array_column((array) ($host['hostgroups'] ?? []), 'name'),
                'templates'   => array_column((array) ($host['parentTemplates'] ?? []), 'name'),
                'os'          => trim((string) ($inventory['os'] ?? '')),
                'location'    => trim((string) ($inventory['location'] ?? '')),
                'metrics'     => $metrics[$hostid] ?? [],
                'problems'    => $hostProblems,
            ];
            $hosts[] = $entry;
            if ($isDown) {
                $down[] = ['hostid' => $hostid, 'name' => (string) $host['name']];
            }
        }
        $stateOrder = ['down' => 0, 'problem' => 1, 'ok' => 2];
        usort($hosts, static fn ($a, $b) => [$stateOrder[$a['state']], -$a['worst'], strtolower($a['name'])]
            <=> [$stateOrder[$b['state']], -$b['worst'], strtolower($b['name'])]);
        usort($down, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $summary = [
            'counts'      => $counts,
            'problems'    => array_slice($list, 0, (int) $config['max_problems']),
            'total'       => count($list),
            'hosts'       => $hosts,
            'hosts_total' => count($hosts),
            'hosts_down'  => $down,
            'hosts_with_problems' => count(array_filter($hosts, static fn ($h) => $h['state'] === 'problem')),
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
            . PluginDashureZabbixChart::renderGrid()
            . '<div data-zabbix-summary><p class="dashure-muted"><i class="ti ti-loader-2 dashure-spin"></i> Carregando dados do Zabbix…</p></div>'
            . '</section>';
    }

    public static function configUrl(): string
    {
        return PluginDashureHome::pluginUrlPublic('/front/zabbix.php');
    }

    private static function formatUptime(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        return $d > 0 ? $d . ' d ' . $h . ' h' : ($h > 0 ? $h . ' h ' . $m . ' min' : $m . ' min');
    }

    private static function problemItem(array $p, string $base, bool $showHost = true): string
    {
        $h = [PluginDashureHome::class, 'h'];
        [$label, $color] = self::SEVERITIES[$p['severity']];
        $extra = '';
        if ($p['opdata'] !== '') {
            $extra .= '<span class="dashure-zp-op"><i class="ti ti-gauge"></i> ' . $h($p['opdata']) . '</span>';
        }
        foreach ($p['tags'] as $tag) {
            $extra .= '<span class="dashure-zp-tag">' . $h($tag) . '</span>';
        }
        return '<li data-severity="' . (int) $p['severity'] . '"><a href="' . $h($base . '/zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D=' . rawurlencode($p['hostid'])) . '" target="_blank" rel="noopener noreferrer" style="--tone:' . $h($color) . '">'
            . '<span class="dashure-pill dashure-sev-pill">' . $h($label) . '</span>'
            . '<span class="dashure-list-main"><strong>' . $h($p['name']) . '</strong>'
            . ($showHost ? '<small>' . $h($p['host']) . '</small>' : '')
            . ($extra !== '' ? '<span class="dashure-zp-extra">' . $extra . '</span>' : '')
            . ($p['comments'] !== '' ? '<small class="dashure-zp-desc">' . $h($p['comments']) . '</small>' : '')
            . '</span>'
            . '<span class="dashure-problem-age">' . ($p['ack'] ? '<span class="dashure-zp-ack"><i class="ti ti-check"></i> Reconhecido</span> ' : '')
            . 'há ' . $h(self::ago($p['clock'])) . '</span></a></li>';
    }

    private static function hostCard(array $host, string $base): string
    {
        $h = [PluginDashureHome::class, 'h'];
        $states = ['ok' => ['Sem problemas', 'is-green'], 'problem' => ['Com problemas', 'is-amber'], 'down' => ['Indisponível', 'is-red']];
        [$stateLabel, $stateTone] = $states[$host['state']];
        $hid = rawurlencode($host['hostid']);

        $out = '<article class="dashure-zhost is-' . $h($host['state']) . '" data-host-state="' . $h($host['state']) . '">'
            . '<header><span class="dashure-zhost-dot ' . $h($stateTone) . '"></span>'
            . '<div class="dashure-zhost-title"><h4>' . $h($host['name']) . '</h4>'
            . ($host['technical'] !== $host['name'] ? '<small>' . $h($host['technical']) . '</small>' : '') . '</div>'
            . '<span class="dashure-pill ' . $h($stateTone) . '">' . $h($stateLabel) . '</span>'
            . ($host['maintenance'] ? '<span class="dashure-pill is-blue"><i class="ti ti-tool"></i> Manutenção</span>' : '')
            . '</header>';

        // Métricas
        $m = $host['metrics'];
        $chips = [];
        if (isset($m['cpu'])) {
            $chips[] = ['ti ti-cpu', 'CPU', number_format((float) $m['cpu'][0], 1, ',', '.') . ' %', (float) $m['cpu'][0]];
        }
        if (isset($m['mem'])) {
            $chips[] = ['ti ti-database', 'Memória', number_format((float) $m['mem'][0], 1, ',', '.') . ' %', (float) $m['mem'][0]];
        }
        foreach (array_slice($m['fs'] ?? [], 0, 3, true) as $fs => $pct) {
            $chips[] = ['ti ti-server-2', 'Disco ' . $fs, number_format($pct, 1, ',', '.') . ' %', $pct];
        }
        if (isset($m['uptime'])) {
            $chips[] = ['ti ti-clock-up', 'Ligado há', self::formatUptime((int) $m['uptime'][0]), null];
        }
        if (isset($m['ping'])) {
            $chips[] = ['ti ti-wave-sine', 'Ping', number_format((float) $m['ping'][0] * 1000, 1, ',', '.') . ' ms', null];
        }
        if (isset($m['cpus'])) {
            $chips[] = ['ti ti-cpu-2', 'Núcleos', (string) (int) $m['cpus'][0], null];
        }
        if (isset($m['memtotal'])) {
            $chips[] = ['ti ti-stack-2', 'RAM total', number_format((float) $m['memtotal'][0] / 1073741824, 1, ',', '.') . ' GB', null];
        }
        if ($chips !== []) {
            $out .= '<div class="dashure-zmetrics">';
            foreach ($chips as [$icon, $label, $value, $pct]) {
                $tone = $pct === null ? '' : ($pct >= 90 ? ' is-red' : ($pct >= 75 ? ' is-amber' : ' is-green'));
                $out .= '<div class="dashure-zmetric' . $tone . '"><i class="' . $h($icon) . '"></i><span>' . $h($label) . '</span><strong>' . $h($value) . '</strong>'
                    . ($pct !== null ? '<em style="width:' . max(2, min(100, (int) round($pct))) . '%"></em>' : '') . '</div>';
            }
            $out .= '</div>';
        }

        // Detalhes
        $out .= '<dl class="dashure-zinfo">';
        $os = $host['os'] !== '' ? $host['os'] : (string) ($m['os'][0] ?? '');
        if ($os !== '') {
            $out .= '<dt>Sistema</dt><dd>' . $h(mb_substr($os, 0, 160)) . '</dd>';
        }
        if ($host['interfaces'] !== []) {
            $out .= '<dt>Interfaces</dt><dd>';
            foreach ($host['interfaces'] as $if) {
                $avail = [0 => ['desconhecido', 'is-gray'], 1 => ['disponível', 'is-green'], 2 => ['indisponível', 'is-red']][$if['available']] ?? ['desconhecido', 'is-gray'];
                $out .= '<span class="dashure-zif"><span class="dashure-zhost-dot ' . $avail[1] . '"></span>' . $h($if['type']) . ' <code>' . $h($if['address']) . '</code> <small>' . $h($avail[0]) . '</small>'
                    . ($if['error'] !== '' ? '<small class="dashure-zif-err">' . $h($if['error']) . '</small>' : '') . '</span>';
            }
            $out .= '</dd>';
        }
        if ($host['groups'] !== []) {
            $out .= '<dt>Grupos</dt><dd>' . $h(implode(', ', $host['groups'])) . '</dd>';
        }
        if ($host['templates'] !== []) {
            $out .= '<dt>Modelos</dt><dd>' . $h(implode(', ', $host['templates'])) . '</dd>';
        }
        if (isset($m['agent'])) {
            $out .= '<dt>Agente</dt><dd>Zabbix ' . $h($m['agent'][0]) . '</dd>';
        }
        if ($host['location'] !== '') {
            $out .= '<dt>Local</dt><dd>' . $h($host['location']) . '</dd>';
        }
        if ($host['description'] !== '') {
            $out .= '<dt>Descrição</dt><dd>' . $h($host['description']) . '</dd>';
        }
        $out .= '</dl>';

        if ($host['problems'] !== []) {
            $out .= '<h5 class="dashure-subtitle">Problemas (' . count($host['problems']) . ')</h5><ul class="dashure-list dashure-problems">';
            foreach ($host['problems'] as $p) {
                $out .= self::problemItem($p, $base, false);
            }
            $out .= '</ul>';
        }

        $out .= '<footer>'
            . '<button type="button" class="dashure-btn dashure-zhost-graphs" data-zhost-graphs="' . $h($host['hostid']) . '" data-zhost-name="' . $h($host['name']) . '"><i class="ti ti-chart-histogram"></i> Ver gráficos</button>'
            . '<a href="' . $h($base . '/zabbix.php?action=latest.view&filter_set=1&hostids%5B%5D=' . $hid) . '" target="_blank" rel="noopener noreferrer"><i class="ti ti-list-details"></i> Dados recentes</a>'
            . '<a href="' . $h($base . '/zabbix.php?action=charts.view&filter_set=1&filter_hostids%5B%5D=' . $hid) . '" target="_blank" rel="noopener noreferrer"><i class="ti ti-chart-line"></i> Gráficos</a>'
            . '<a href="' . $h($base . '/zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D=' . $hid) . '" target="_blank" rel="noopener noreferrer"><i class="ti ti-alert-triangle"></i> Problemas</a>'
            . '</footer></article>';
        return $out;
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

        // Contadores clicáveis: filtram os detalhes logo abaixo.
        $out .= '<div class="dashure-stats dashure-sev">';
        for ($s = 5; $s >= (int) $config['min_severity']; $s--) {
            [$label, $color] = self::SEVERITIES[$s];
            $out .= '<button type="button" class="dashure-stat' . ($data['counts'][$s] === 0 ? ' is-zero' : '') . '" style="--tone:' . $h($color) . '"'
                . ' data-zfilter="sev:' . $s . '" aria-pressed="false">'
                . '<i class="ti ti-alert-triangle" aria-hidden="true"></i><strong>' . (int) $data['counts'][$s] . '</strong><span>' . $h($label) . '</span></button>';
        }
        $out .= '</div>';

        $okHosts = count(array_filter($data['hosts'], static fn ($x) => $x['state'] === 'ok'));
        $hostStats = [
            ['all', 'is-blue', 'ti ti-server', (int) $data['hosts_total'], 'Hosts monitorados'],
            ['ok', 'is-green', 'ti ti-circle-check', $okHosts, 'Sem problemas'],
            ['problem', 'is-amber', 'ti ti-alert-circle', (int) $data['hosts_with_problems'], 'Com problemas'],
            ['down', 'is-red', 'ti ti-plug-connected-x', count($data['hosts_down']), 'Indisponíveis'],
        ];
        $out .= '<div class="dashure-stats">';
        foreach ($hostStats as [$key, $tone, $icon, $value, $label]) {
            $out .= '<button type="button" class="dashure-stat ' . $tone . ($value === 0 ? ' is-zero' : '') . '" data-zfilter="host:' . $key . '" aria-pressed="false">'
                . '<i class="' . $icon . '"></i><strong>' . $value . '</strong><span>' . $h($label) . '</span></button>';
        }
        $out .= '</div>';

        // Visão de problemas (padrão)
        $out .= '<div class="dashure-zview" data-zview="problems">'
            . '<h4 class="dashure-subtitle" data-zview-title>Problemas ativos (' . (int) $data['total'] . ')</h4>';
        if ($data['problems'] === []) {
            $out .= '<p class="dashure-muted"><i class="ti ti-mood-smile"></i> Nenhum problema ativo.</p>';
        } else {
            $out .= '<ul class="dashure-list dashure-problems">';
            foreach ($data['problems'] as $p) {
                $out .= self::problemItem($p, $base);
            }
            $out .= '</ul><p class="dashure-muted" data-zview-empty hidden>Nenhum problema com essa gravidade.</p>';
        }
        $out .= '</div>';

        // Visão de hosts (ao clicar nos contadores de hosts)
        $out .= '<div class="dashure-zview" data-zview="hosts" hidden>'
            . '<h4 class="dashure-subtitle" data-zview-title>Hosts</h4><div class="dashure-zhost-grid">';
        foreach ($data['hosts'] as $host) {
            $out .= self::hostCard($host, $base);
        }
        $out .= '</div><p class="dashure-muted" data-zview-empty hidden>Nenhum host nessa situação.</p></div>';
        $out .= '<p class="dashure-muted dashure-zhint"><i class="ti ti-hand-click"></i> Clique nos contadores para ver os detalhes. Clique de novo para voltar.</p>';

        return '<div class="dashure-card">' . $out . '</div>';
    }
}
