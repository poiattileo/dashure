<?php
include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('Método inválido.');
    }
    if (!Session::getLoginUserID() || !PluginDashureNote::canView()) {
        http_response_code(403);
        throw new RuntimeException('Sem permissão.');
    }
    switch ((string) ($_POST['action'] ?? '')) {
        case 'note_secret':
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
