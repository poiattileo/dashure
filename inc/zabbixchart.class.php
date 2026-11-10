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

    public static function delete(int $id): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['id' => $id]);
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
    public static function data(int $id, string $range): array
    {
        global $GLPI_CACHE;

        $chart = self::get($id);
        if ($chart === null || (int) $chart['is_active'] !== 1) {
            throw new RuntimeException('Gráfico não encontrado.');
        }
        if (!isset(self::RANGES[$range])) {
            $range = '1d';
        }
        $cacheKey = 'plugin_dashure_zbxchart_' . $id . '_' . $range . '_' . md5((string) $chart['date_mod']);
        try {
            $cached = $GLPI_CACHE?->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable $e) {
        }

        $config = PluginDashureZabbix::config();
        $seconds = self::RANGES[$range][1];
        $from = time() - $seconds;
        $items = self::items($chart);

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
        foreach ($items as $item) {
            $points = $byItem[(string) $item['itemid']] ?? [];
            if ($points === []) {
                continue;
            }
            usort($points, static fn ($a, $b) => $a[0] <=> $b[0]);
            // Reduz para ~MAX_POINTS pontos (média por intervalo).
            $buckets = [];
            foreach ($points as [$clock, $value]) {
                $key = intdiv($clock, $bucket);
                $buckets[$key][0] = ($buckets[$key][0] ?? 0) + $value;
                $buckets[$key][1] = ($buckets[$key][1] ?? 0) + 1;
            }
            $data = [];
            $sum = 0.0;
            // Buraco nos dados (host desligado, sem coleta): quebra a linha em vez de ligar os pontos.
            $gap = max(3 * $bucket, $seconds > 2 * 86400 ? 3 * 3600 : 900);
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
            $host = (string) ($item['hosts'][0]['name'] ?? '');
            $series[] = [
                'name'  => ($host !== '' ? $host . ': ' : '') . (string) $item['name'],
                'units' => (string) $item['units'],
                'data'  => $data,
                'mean'  => $sum / max(1, count($data)),
            ];
        }
        // Muitas séries poluem o gráfico: fica com as de maior média.
        usort($series, static fn ($a, $b) => $b['mean'] <=> $a['mean']);
        $truncated = count($series) > self::MAX_SERIES;
        $series = array_slice($series, 0, self::MAX_SERIES);
        usort($series, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        foreach ($series as &$s) {
            unset($s['mean']);
        }
        unset($s);

        $result = [
            'title'     => (string) $chart['title'],
            'units'     => (string) ($series[0]['units'] ?? ''),
            'series'    => $series,
            'truncated' => $truncated,
            'from'      => $from * 1000,
            'to'        => time() * 1000,
        ];
        try {
            $GLPI_CACHE?->set($cacheKey, $result, $seconds <= 86400 ? 60 : 600);
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
