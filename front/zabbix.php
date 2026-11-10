<?php
include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight(PluginDashureProfile::RIGHT_ZABBIX, UPDATE);

$self = PluginDashureZabbix::configUrl();
$h = [PluginDashureHome::class, 'h'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'chart_save') {
            $chartId = PluginDashureZabbixChart::save($_POST);
            $count = count(PluginDashureZabbixChart::items(PluginDashureZabbixChart::get($chartId)));
            Session::addMessageAfterRedirect('Gráfico salvo: ' . $count . ' item(ns) do Zabbix encontrado(s) com esse padrão.', false, $count > 0 ? INFO : WARNING);
            Html::redirect($self . '#graficos');
        }
        if ($action === 'chart_delete') {
            PluginDashureZabbixChart::delete((int) ($_POST['id'] ?? 0));
            Session::addMessageAfterRedirect('Gráfico excluído.');
            Html::redirect($self . '#graficos');
        }
        PluginDashureZabbix::saveConfig($_POST);
        if (isset($_POST['test'])) {
            $result = PluginDashureZabbix::test();
            Session::addMessageAfterRedirect('Conexão OK: Zabbix ' . $result['version'] . ', ' . $result['hosts'] . ' host(s) monitorado(s) visíveis para o token.');
        } else {
            Session::addMessageAfterRedirect('Configuração do Zabbix salva.');
        }
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect($self);
}

$config = PluginDashureZabbix::config();
$hasToken = (string) $config['token_encrypted'] !== '';
$selected = array_filter(array_map('intval', explode(',', (string) $config['groupids'])));
$groups = [];
$hosts = [];
$groupError = '';
if (PluginDashureZabbix::isConfigured()) {
    try {
        $groups = PluginDashureZabbix::hostGroups();
        $hosts = PluginDashureZabbixChart::hosts();
    } catch (Throwable $e) {
        $groupError = $e->getMessage();
    }
}
$editChart = isset($_GET['chart']) ? PluginDashureZabbixChart::get((int) $_GET['chart']) : null;
$chartHosts = $editChart ? array_filter(array_map('intval', explode(',', (string) $editChart['hostids']))) : [];
$csrf = static fn (): string => Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

Html::header('Zabbix — Dashure', $_SERVER['PHP_SELF'], 'config', 'plugin');
?>
<div class="dashure dashure-admin">
    <header class="dashure-hero">
        <?= PluginDashureHome::brandLogo() ?>
        <div class="dashure-hero-text"><h2>Conexão com o Zabbix</h2>
            <p>Painel somente leitura na aba Início. Use um usuário do Zabbix só de leitura com token de API.</p></div>
        <a class="dashure-btn dashure-btn-ghost" href="<?= $h($CFG_GLPI['root_doc']) ?>/front/central.php"><i class="ti ti-home"></i> Ver Início</a>
    </header>

    <form method="post" action="<?= $h($self) ?>" class="dashure-grid" autocomplete="off">
        <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
        <div class="dashure-main">
            <section class="dashure-card dashure-form">
                <header class="dashure-card-head"><h3><i class="ti ti-plug-connected"></i> Acesso</h3></header>
                <label>Endereço do Zabbix <small>(ex.: https://zabbix.exemplo/zabbix)</small>
                    <input class="form-control" name="url" maxlength="500" value="<?= $h($config['url']) ?>" placeholder="https://…"></label>
                <label>Token da API
                    <input class="form-control" type="password" name="token" autocomplete="new-password"
                           placeholder="<?= $hasToken ? 'Token salvo — deixe em branco para manter' : 'Cole aqui o token gerado no Zabbix' ?>"></label>
                <?php if ($hasToken): ?>
                    <label class="dashure-check"><input type="checkbox" name="clear_token" value="1"> Remover o token salvo</label>
                <?php endif; ?>
                <label class="dashure-check"><input type="checkbox" name="verify_ssl" value="1"<?= (int) $config['verify_ssl'] === 1 ? ' checked' : '' ?>> Verificar certificado HTTPS</label>
                <small class="dashure-muted">No Zabbix: <em>Usuários → Tokens de API → Criar token</em>. O token fica criptografado com a chave do GLPI.</small>
            </section>

            <section class="dashure-card dashure-form">
                <header class="dashure-card-head"><h3><i class="ti ti-adjustments"></i> Painel</h3></header>
                <div class="dashure-form-row">
                    <label>Gravidade mínima<select class="form-select" name="min_severity">
                        <?php foreach (PluginDashureZabbix::SEVERITIES as $value => [$label]): ?>
                            <option value="<?= (int) $value ?>"<?= (int) $config['min_severity'] === $value ? ' selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Atualizar a cada (segundos)<input class="form-control" type="number" min="30" max="900" name="refresh" value="<?= (int) $config['refresh'] ?>"></label>
                    <label>Problemas na lista<input class="form-control" type="number" min="5" max="200" name="max_problems" value="<?= (int) $config['max_problems'] ?>"></label>
                </div>
            </section>
        </div>

        <aside class="dashure-side">
            <section class="dashure-card dashure-form">
                <header class="dashure-card-head"><h3><i class="ti ti-folders"></i> Grupos de hosts</h3></header>
                <?php if ($groupError !== ''): ?>
                    <p class="dashure-muted"><?= $h($groupError) ?></p>
                <?php elseif ($groups === []): ?>
                    <p class="dashure-muted">Salve o endereço e o token para listar os grupos.</p>
                <?php else: ?>
                    <small class="dashure-muted">Nenhum marcado = todos os grupos.</small>
                    <div class="dashure-group-list">
                        <?php foreach ($groups as $groupId => $name): ?>
                            <label class="dashure-check"><input type="checkbox" name="groupids[]" value="<?= (int) $groupId ?>"<?= in_array($groupId, $selected, true) ? ' checked' : '' ?>> <?= $h($name) ?></label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <div class="dashure-form-actions">
                <button class="dashure-btn" type="submit" name="save" value="1"><i class="ti ti-device-floppy"></i> Salvar</button>
                <button class="dashure-btn dashure-btn-ghost" type="submit" name="test" value="1"><i class="ti ti-plug-connected"></i> Salvar e testar</button>
            </div>
        </aside>
    </form>

    <div class="dashure-grid" id="graficos">
        <div class="dashure-main">
            <section class="dashure-card">
                <header class="dashure-card-head"><h3><i class="ti ti-chart-area-line"></i> Gráficos</h3></header>
                <?php $charts = PluginDashureZabbixChart::all(); ?>
                <?php if ($charts === []): ?>
                    <p class="dashure-muted">Nenhum gráfico cadastrado.</p>
                <?php else: ?>
                    <table class="table table-sm dashure-table">
                        <thead><tr><th>Ordem</th><th>Título</th><th>Busca</th><th>Hosts</th><th>Situação</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($charts as $c): ?>
                            <?php $ids = array_filter(array_map('intval', explode(',', (string) $c['hostids']))); ?>
                            <tr>
                                <td><?= (int) $c['rank'] ?></td>
                                <td><?= $h($c['title']) ?></td>
                                <td><small><?= $h(PluginDashureZabbixChart::FIELDS[$c['match_field']] ?? $c['match_field']) ?>:</small> <code><?= $h($c['pattern']) ?></code></td>
                                <td><?= $ids === [] ? 'Todos' : $h(implode(', ', array_map(static fn ($i) => $hosts[$i] ?? ('#' . $i), $ids))) ?></td>
                                <td><?= (int) $c['is_active'] === 1 ? 'Ativo' : 'Inativo' ?></td>
                                <td class="dashure-actions">
                                    <a class="dashure-btn dashure-btn-ghost" href="<?= $h($self . '?chart=' . (int) $c['id'] . '#graficos') ?>" aria-label="Editar"><i class="ti ti-pencil"></i></a>
                                    <form method="post" action="<?= $h($self) ?>" onsubmit="return confirm('Excluir este gráfico?');">
                                        <?= $csrf() ?><input type="hidden" name="action" value="chart_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="dashure-btn dashure-btn-danger" type="submit" aria-label="Excluir"><i class="ti ti-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <small class="dashure-muted">Cada gráfico junta os itens numéricos do Zabbix cuja <strong>chave</strong> ou <strong>nome</strong> casa com o padrão
                    (use <code>*</code> como coringa, ex.: <code>net.if.in[*]</code> ou <code>*Disk utilization</code>). Uma linha por item, até <?= PluginDashureZabbixChart::MAX_SERIES ?> (as de maior média).</small>
            </section>
        </div>
        <aside class="dashure-side">
            <section class="dashure-card">
                <header class="dashure-card-head"><h3><i class="ti ti-plus"></i> <?= $editChart ? 'Editar gráfico' : 'Novo gráfico' ?></h3>
                    <?php if ($editChart): ?><a href="<?= $h($self . '#graficos') ?>">Cancelar</a><?php endif; ?></header>
                <form method="post" action="<?= $h($self) ?>" class="dashure-form">
                    <?= $csrf() ?>
                    <input type="hidden" name="action" value="chart_save">
                    <input type="hidden" name="id" value="<?= (int) ($editChart['id'] ?? 0) ?>">
                    <label>Título<input class="form-control" name="title" maxlength="150" required value="<?= $h($editChart['title'] ?? '') ?>" placeholder="Ex.: CPU"></label>
                    <div class="dashure-form-row">
                        <label>Buscar por<select class="form-select" name="match_field">
                            <?php foreach (PluginDashureZabbixChart::FIELDS as $key => $label): ?>
                                <option value="<?= $h($key) ?>"<?= ($editChart['match_field'] ?? 'key') === $key ? ' selected' : '' ?>><?= $h($label) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label>Ordem<input class="form-control" type="number" name="rank" value="<?= (int) ($editChart['rank'] ?? 0) ?>"></label>
                    </div>
                    <label>Padrão<input class="form-control" name="pattern" maxlength="255" required value="<?= $h($editChart['pattern'] ?? '') ?>" placeholder="system.cpu.util"></label>
                    <?php if ($hosts !== []): ?>
                        <fieldset class="dashure-profiles">
                            <legend>Hosts <small>(nenhum = todos)</small></legend>
                            <?php foreach ($hosts as $hostId => $name): ?>
                                <label class="dashure-check"><input type="checkbox" name="hostids[]" value="<?= (int) $hostId ?>"<?= in_array($hostId, $chartHosts, true) ? ' checked' : '' ?>> <?= $h($name) ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endif; ?>
                    <label class="dashure-check"><input type="checkbox" name="is_active" value="1"<?= (int) ($editChart['is_active'] ?? 1) === 1 ? ' checked' : '' ?>> Ativo</label>
                    <div><button class="dashure-btn" type="submit"><i class="ti ti-device-floppy"></i> Salvar gráfico</button></div>
                </form>
            </section>
        </aside>
    </div>
</div>
<?php Html::footer(); ?>
