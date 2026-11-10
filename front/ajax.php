<?php
include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('Método inválido.');
    }
    if (!Session::getLoginUserID()) {
        http_response_code(403);
        throw new RuntimeException('Sessão expirada.');
    }
    switch ((string) ($_POST['action'] ?? '')) {
        case 'zabbix_panel':
            if (!PluginDashureZabbix::canView()) {
                http_response_code(403);
                throw new RuntimeException('Sem permissão.');
            }
            echo json_encode(['ok' => true, 'html' => PluginDashureZabbix::renderPanel()], JSON_UNESCAPED_UNICODE);
            break;
        case 'zabbix_chart':
            if (!PluginDashureZabbix::canView()) {
                http_response_code(403);
                throw new RuntimeException('Sem permissão.');
            }
            echo json_encode(['ok' => true] + PluginDashureZabbixChart::data(
                (int) ($_POST['id'] ?? 0),
                (string) ($_POST['range'] ?? '1d'),
                (string) ($_POST['combine'] ?? '') === '1'
            ), JSON_UNESCAPED_UNICODE);
            break;
        case 'zabbix_host_graphs':
        case 'zabbix_graph':
            if (!PluginDashureZabbix::canView()) {
                http_response_code(403);
                throw new RuntimeException('Sem permissão.');
            }
            $zid = preg_replace('/\D/', '', (string) ($_POST['id'] ?? ''));
            if ($zid === '') {
                throw new RuntimeException('Identificador inválido.');
            }
            $payload = $_POST['action'] === 'zabbix_host_graphs'
                ? PluginDashureZabbixChart::hostGraphs($zid)
                : PluginDashureZabbixChart::graphData($zid, (string) ($_POST['range'] ?? '1d'));
            echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE);
            break;
        case 'note_secret':
            if (!PluginDashureNote::canView()) {
                http_response_code(403);
                throw new RuntimeException('Sem permissão.');
            }
            $data = PluginDashureNote::reveal((int) ($_POST['id'] ?? 0), (string) ($_POST['mode'] ?? ''));
            echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
            break;
        default:
            throw new RuntimeException('Ação inválida.');
    }
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
