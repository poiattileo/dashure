<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

/**
 * Gráficos do painel Zabbix (estilo dashboard do Zabbix): cada gráfico junta os itens
 * que casam com um padrão (chave ou nome) nos hosts escolhidos, uma série por item.
 */
class PluginDashureZabbixChart
{
    public const TABLE = 'glpi_plugin_dashure_zabbix_charts';
    public const MAX_SERIES = 12;
    public const MAX_POINTS = 300;
    /** Períodos: chave => [rótulo, segundos]. Até 2 dias usa histórico; acima, tendências (média por hora). */
    public const RANGES = [
        '1h'  => ['1 hora', 3600],
        '6h'  => ['6 horas', 21600],
        '12h' => ['12 horas', 43200],
        '1d'  => ['1 dia', 86400],
        '7d'  => ['7 dias', 604800],
        '30d' => ['30 dias', 2592000],
    ];
    public const FIELDS = ['key' => 'Chave do item', 'name' => 'Nome do item'];

    /** Gráficos criados na instalação (modelos padrão do Zabbix para Linux). */
    public const DEFAULTS = [
        ['CPU', 'key', 'system.cpu.util'],
        ['Memória RAM', 'name', '*Memory used*'],
        ['Disco', 'name', '*Disk utilization'],
        ['Velocidade de recebimento da rede', 'key', 'net.if.in[*]'],
        ['Velocidade de envio da rede', 'key', 'net.if.out[*]'],
    ];

    public static function installDefaults(): void
    {
        global $DB;

        if (countElementsInTable(self::TABLE) > 0) {
            return;
        }
        foreach (self::DEFAULTS as $rank => [$title, $field, $pattern]) {
            $DB->insert(self::TABLE, [
                'title' => $title, 'match_field' => $field, 'pattern' => $pattern,
                'hostids' => '', 'is_active' => 1, 'rank' => $rank * 10, 'date_mod' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public static function all(bool $onlyActive = false): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => $onlyActive ? ['is_active' => 1] : [],
            'ORDER' => ['rank ASC', 'id ASC'],
        ]), false);
    }

    public static function get(int $id): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]])->current();
        return is_array($row) ? $row : null;
    }

    public static function save(array $input): int
    {
        global $DB;

        $title = trim((string) ($input['title'] ?? ''));
        $pattern = trim((string) ($input['pattern'] ?? ''));
        if ($title === '' || $pattern === '') {
            throw new RuntimeException('Informe o título e o padrão do gráfico.');
        }
        $field = (string) ($input['match_field'] ?? 'key');
        if (!isset(self::FIELDS[$field])) {
            $field = 'key';
        }
        $hostids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['hostids'] ?? [])))));
        $data = [
            'title'       => mb_substr($title, 0, 150),
            'match_field' => $field,
            'pattern'     => mb_substr($pattern, 0, 255),
            'hostids'     => implode(',', $hostids),
            'is_active'   => !empty($input['is_active']) ? 1 : 0,
            'rank'        => (int) ($input['rank'] ?? 0),
            'total_text'  => self::validTotal((string) ($input['total_text'] ?? '')),
            'aggregate'   => !empty($input['aggregate']) ? 1 : 0,
            'combine_toggle' => !empty($input['combine_toggle']) ? 1 : 0,
            'date_mod'    => date('Y-m-d H:i:s'),
        ];
        $id = (int) ($input['id'] ?? 0);
        if ($id > 0) {
            if (self::get($id) === null) {
                throw new RuntimeException('Gráfico não encontrado.');
            }
            $DB->update(self::TABLE, $data, ['id' => $id]);
        } else {
            $DB->insert(self::TABLE, $data);
            $id = (int) $DB->insertId();
        }
        return $id;
    }

    /** "54 GB", "1 Gbps", "100 %", "512" → valor na unidade base do item (B usa 1024; bps, 1000). */
    public static function parseTotal(string $text): ?float
    {
        $text = trim(str_replace(',', '.', $text));
        if ($text === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([kmgtp]?)(i?)(b|bps|b\/s|%)?$/i', $text, $m)) {
            return null;
        }
        $value = (float) $m[1];
        $prefix = strtolower($m[2]);
        if ($prefix !== '') {
            $base = (isset($m[4]) && stripos($m[4], 'bps') !== false) ? 1000 : 1024;
            $value *= $base ** (strpos('kmgtp', $prefix) + 1);
        }
        return $value > 0 ? $value : null;
    }

    /**
     * Total do eixo para a escala "Total". Número sem unidade vale na unidade "natural" do gráfico
     * (GB para memória, Mbps para rede). Sem total configurado: % usa 100 e rede usa 1 Gbps.
     */
    public static function totalFor(string $text, string $units): ?float
    {
        $text = trim($text);
        if ($text === '') {
            return match ($units) {
                '%'     => 100.0,
                'bps'   => 1000000000.0,
                default => null,
            };
        }
        $value = self::parseTotal($text);
        if ($value !== null && preg_match('/^\d+(?:[.,]\d+)?$/', $text)) {
            $value *= match ($units) {
                'B'     => 1024 ** 3,
                'bps'   => 1000000,
                'Bps'   => 1024 ** 2,
                default => 1,
            };
        }
        return $value;
    }

    private static function validTotal(string $text): string
    {
        $text = trim($text);
        if ($text !== '' && self::parseTotal($text) === null) {
            throw new RuntimeException('Total inválido. Use algo como 54 GB, 1 Gbps ou 100 %.');
        }
        return mb_substr($text, 0, 50);
    }

    public static function delete(int $id): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['id' => $id]);
    }

    /** Nomes dos itens encontrados (para conferir o padrão na configuração). */
    public static function itemLabels(array $chart, int $limit = 8): array
    {
        $labels = [];
        foreach (array_slice(self::items($chart), 0, $limit) as $item) {
            $labels[] = (string) ($item['hosts'][0]['name'] ?? '?') . ': ' . (string) $item['name'] . ' [' . (string) $item['key_'] . ']';
        }
        return $labels;
    }

    public static function hosts(): array
    {
        $config = PluginDashureZabbix::config();
        $groupids = array_filter(array_map('intval', explode(',', (string) $config['groupids'])));
        $out = [];
        foreach ((array) PluginDashureZabbix::rpc($config, 'host.get', ($groupids !== [] ? ['groupids' => $groupids] : []) + [
            'output' => ['hostid', 'name'], 'monitored_hosts' => true, 'sortfield' => 'name',
        ]) as $host) {
            $out[(int) $host['hostid']] = (string) $host['name'];
        }
        return $out;
    }

    /** Itens numéricos que casam com o padrão do gráfico. */
    public static function items(array $chart): array
    {
        $config = PluginDashureZabbix::config();
        $hostids = array_filter(array_map('intval', explode(',', (string) $chart['hostids'])));
        $groupids = array_filter(array_map('intval', explode(',', (string) $config['groupids'])));
        $scope = $hostids !== [] ? ['hostids' => $hostids] : ($groupids !== [] ? ['groupids' => $groupids] : []);

        return (array) PluginDashureZabbix::rpc($config, 'item.get', $scope + [
            'output'      => ['itemid', 'hostid', 'name', 'key_', 'units', 'value_type'],
            'selectHosts' => ['name'],
            'monitored'   => true,
            'filter'      => ['value_type' => [0, 3], 'status' => 0],
            'search'      => [(string) $chart['match_field'] === 'name' ? 'name' : 'key_' => (string) $chart['pattern']],
            'searchWildcardsEnabled' => true,
            'sortfield'   => 'name',
            'limit'       => 60,
        ]);
    }

    /** Séries prontas para o ECharts: [[timestamp_ms, valor], ...] por item. */
    public static function data(int $id, string $range, bool $combine = false): array
    {
        global $GLPI_CACHE;

        $chart = self::get($id);
        if ($chart === null || (int) $chart['is_active'] !== 1) {
            throw new RuntimeException('Gráfico não encontrado.');
        }
        if (!isset(self::RANGES[$range])) {
            $range = '1d';
        }
        $configured = (int) ($chart['aggregate'] ?? 0) === 1;
        $combine = ($combine && (int) ($chart['combine_toggle'] ?? 0) === 1) || $configured;
        $cacheKey = 'plugin_dashure_zbxchart_' . $id . '_' . $range . '_' . ($combine ? 'c' : 's') . '_' . md5((string) $chart['date_mod']);
        try {
            $cached = $GLPI_CACHE?->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable $e) {
        }

        $items = self::items($chart);
        $result = ['title' => (string) $chart['title']]
            + self::seriesFor($items, $range, true, [], self::MAX_SERIES, $combine ? (string) $chart['title'] : '');
        // Botão "Separado / Juntos" só faz sentido com mais de um item e quando o gráfico não é sempre somado.
        $result['combinable'] = !$configured && (int) ($chart['combine_toggle'] ?? 0) === 1 && count($items) > 1;
        $result['combined'] = $combine;
        $result['total'] = self::totalFor((string) ($chart['total_text'] ?? ''), (string) $result['units']);
        $seconds = self::RANGES[$range][1];
        try {
            $GLPI_CACHE?->set($cacheKey, $result, $seconds <= 86400 ? 60 : 600);
        } catch (Throwable $e) {
        }
        return $result;
    }

    /**
     * Busca histórico (até 2 dias) ou tendências (acima) dos itens e devolve as séries reduzidas.
     * $colors: itemid => cor (gráficos do próprio Zabbix).
     */
    public static function seriesFor(array $items, string $range, bool $withHost, array $colors = [], int $maxSeries = self::MAX_SERIES, string $sumAs = ''): array
    {
        $config = PluginDashureZabbix::config();
        if (!isset(self::RANGES[$range])) {
            $range = '1d';
        }
        $seconds = self::RANGES[$range][1];
        $from = time() - $seconds;

        $byItem = [];
        if ($items !== []) {
            if ($seconds <= 2 * 86400) {
                $byType = [];
                foreach ($items as $item) {
                    $byType[(int) $item['value_type']][] = (string) $item['itemid'];
                }
                foreach ($byType as $type => $ids) {
                    foreach ((array) PluginDashureZabbix::rpc($config, 'history.get', [
                        'output' => ['itemid', 'clock', 'value'], 'history' => $type, 'itemids' => $ids,
                        'time_from' => $from, 'sortfield' => 'clock', 'sortorder' => 'ASC', 'limit' => 200000,
                    ]) as $row) {
                        $byItem[(string) $row['itemid']][] = [(int) $row['clock'], (float) $row['value']];
                    }
                }
            } else {
                foreach ((array) PluginDashureZabbix::rpc($config, 'trend.get', [
                    'output' => ['itemid', 'clock', 'value_avg'], 'itemids' => array_column($items, 'itemid'),
                    'time_from' => $from, 'limit' => 200000,
                ]) as $row) {
                    $byItem[(string) $row['itemid']][] = [(int) $row['clock'], (float) $row['value_avg']];
                }
            }
        }

        $series = [];
        $bucket = max(1, (int) ceil($seconds / self::MAX_POINTS));
        // Buraco nos dados (host desligado, sem coleta): quebra a linha em vez de ligar os pontos.
        $gap = max(3 * $bucket, $seconds > 2 * 86400 ? 3 * 3600 : 900);
        foreach ($items as $item) {
            $points = $byItem[(string) $item['itemid']] ?? [];
            if ($points === []) {
                continue;
            }
            usort($points, static fn ($x, $y) => $x[0] <=> $y[0]);
            $buckets = [];
            foreach ($points as [$clock, $value]) {
                $key = intdiv($clock, $bucket);
                $buckets[$key][0] = ($buckets[$key][0] ?? 0) + $value;
                $buckets[$key][1] = ($buckets[$key][1] ?? 0) + 1;
            }
            $data = [];
            $sum = 0.0;
            $previous = null;
            foreach ($buckets as $key => [$total, $count]) {
                $clock = $key * $bucket;
                if ($previous !== null && $clock - $previous > $gap) {
                    $data[] = [($previous + $bucket) * 1000, null];
                }
                $avg = $total / $count;
                $data[] = [$clock * 1000, round($avg, 4)];
                $sum += $avg;
                $previous = $clock;
            }
            $host = $withHost ? (string) ($item['hosts'][0]['name'] ?? '') : '';
            $entry = [
                'name'  => ($host !== '' ? $host . ': ' : '') . (string) $item['name'],
                'units' => (string) $item['units'],
                'data'  => $data,
                'mean'  => $sum / max(1, count($data)),
            ];
            if (isset($colors[(string) $item['itemid']])) {
                $entry['color'] = $colors[(string) $item['itemid']];
            }
            $series[] = $entry;
        }
        // "Somar as séries": uma linha só com a soma de todos os itens em cada instante.
        if ($sumAs !== '' && $series !== []) {
            // Cada item coleta num horário: repete o último valor de cada um (até o limite de "buraco")
            // para a soma não ficar serrilhada.
            $stamps = [];
            foreach ($series as $one) {
                foreach ($one['data'] as [$ts]) {
                    $stamps[$ts] = true;
                }
            }
            ksort($stamps);
            $stamps = array_keys($stamps);
            $total = array_fill_keys($stamps, null);
            $counts = [];
            foreach ($series as $one) {
                $values = [];
                foreach ($one['data'] as [$ts, $value]) {
                    $values[$ts] = $value;
                }
                $last = null;
                $lastTs = null;
                foreach ($stamps as $ts) {
                    if (array_key_exists($ts, $values)) {
                        $last = $values[$ts];
                        $lastTs = $ts;
                    } elseif ($lastTs !== null && ($ts - $lastTs) > $gap * 1000) {
                        $last = null;
                    }
                    if ($last !== null) {
                        $total[$ts] = ($total[$ts] ?? 0) + $last;
                        $counts[$ts] = ($counts[$ts] ?? 0) + 1;
                    }
                }
            }
            $points = [];
            $isPercent = (string) $series[0]['units'] === '%';
            foreach ($total as $ts => $value) {
                if ($value !== null && $isPercent) {
                    $value /= max(1, $counts[$ts] ?? 1);
                }
                $points[] = [$ts, $value === null ? null : round($value, 4)];
            }
            $series = [[
                'name' => $sumAs . ($isPercent ? ' — média de ' : ' — soma de ') . count($series) . ' itens', 'units' => $series[0]['units'],
                'data' => $points, 'mean' => 0,
            ]];
        }
        // Muitas séries poluem o gráfico: fica com as de maior média.
        usort($series, static fn ($x, $y) => $y['mean'] <=> $x['mean']);
        $truncated = count($series) > $maxSeries;
        $series = array_slice($series, 0, $maxSeries);
        usort($series, static fn ($x, $y) => strnatcasecmp($x['name'], $y['name']));
        foreach ($series as &$one) {
            unset($one['mean']);
        }
        unset($one);

        return [
            'units'     => (string) ($series[0]['units'] ?? ''),
            'series'    => $series,
            'truncated' => $truncated,
            'from'      => $from * 1000,
            'to'        => time() * 1000,
        ];
    }

    /** Gráficos configurados no Zabbix para o host (CPU, memória, discos, interfaces…). */
    public static function hostGraphs(string $hostid): array
    {
        $config = PluginDashureZabbix::config();
        $host = (array) PluginDashureZabbix::rpc($config, 'host.get', [
            'output' => ['hostid', 'name'], 'hostids' => [$hostid],
        ]);
        if ($host === []) {
            throw new RuntimeException('Host não encontrado no Zabbix.');
        }
        $graphs = [];
        foreach ((array) PluginDashureZabbix::rpc($config, 'graph.get', [
            'output' => ['graphid', 'name'], 'hostids' => [$hostid], 'sortfield' => 'name',
        ]) as $graph) {
            $graphs[] = ['id' => (string) $graph['graphid'], 'name' => (string) $graph['name']];
        }
        return ['host' => (string) $host[0]['name'], 'graphs' => $graphs];
    }

    /** Dados de um gráfico do Zabbix (com as cores definidas nele). */
    public static function graphData(string $graphid, string $range): array
    {
        global $GLPI_CACHE;

        if (!isset(self::RANGES[$range])) {
            $range = '1d';
        }
        $cacheKey = 'plugin_dashure_zbxgraph_' . preg_replace('/\D/', '', $graphid) . '_' . $range;
        try {
            $cached = $GLPI_CACHE?->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable $e) {
        }
        $config = PluginDashureZabbix::config();
        $graph = (array) PluginDashureZabbix::rpc($config, 'graph.get', [
            'output' => ['graphid', 'name'], 'graphids' => [$graphid],
            'selectGraphItems' => ['itemid', 'color'],
        ]);
        if ($graph === []) {
            throw new RuntimeException('Gráfico não encontrado no Zabbix.');
        }
        $colors = [];
        foreach ((array) $graph[0]['gitems'] as $gitem) {
            $colors[(string) $gitem['itemid']] = '#' . ltrim((string) $gitem['color'], '#');
        }
        $items = $colors === [] ? [] : (array) PluginDashureZabbix::rpc($config, 'item.get', [
            'output' => ['itemid', 'name', 'units', 'value_type'], 'itemids' => array_keys($colors),
            'filter' => ['value_type' => [0, 3]],
        ]);
        $result = ['title' => (string) $graph[0]['name']] + self::seriesFor($items, $range, false, $colors, 20);
        try {
            $GLPI_CACHE?->set($cacheKey, $result, self::RANGES[$range][1] <= 86400 ? 60 : 600);
        } catch (Throwable $e) {
        }
        return $result;
    }

    /** Área dos gráficos na sub-aba Zabbix (os dados vêm por AJAX). */
    public static function renderGrid(): string
    {
        $h = [PluginDashureHome::class, 'h'];
        $charts = self::all(true);
        if ($charts === []) {
            return '';
        }
        $out = '<section class="dashure-card dashure-zcharts" data-zabbix-charts>'
            . '<header class="dashure-card-head"><h3><i class="ti ti-chart-area-line" aria-hidden="true"></i> Gráficos</h3>'
            . '<div class="dashure-range" role="group" aria-label="Período">';
        foreach (self::RANGES as $key => [$label]) {
            $out .= '<button type="button" data-zabbix-range="' . $h($key) . '"' . ($key === '1d' ? ' class="is-active"' : '') . '>' . $h($label) . '</button>';
        }
        $out .= '</div></header><div class="dashure-zchart-grid">';
        foreach ($charts as $chart) {
            $out .= '<figure class="dashure-zchart" data-zabbix-chart="' . (int) $chart['id'] . '">'
                . '<figcaption>' . $h($chart['title']) . '</figcaption>'
                . '<div class="dashure-zchart-canvas" data-chart-canvas></div>'
                . '<p class="dashure-muted dashure-zchart-msg" data-chart-msg>Carregando…</p></figure>';
        }
        return $out . '</div></section>';
    }
}
