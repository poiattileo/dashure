<?php
include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight(PluginDashureProfile::RIGHT_ZABBIX, UPDATE);

$self = PluginDashureZabbix::configUrl();
$h = [PluginDashureHome::class, 'h'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
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
$groupError = '';
if (PluginDashureZabbix::isConfigured()) {
    try {
        $groups = PluginDashureZabbix::hostGroups();
    } catch (Throwable $e) {
        $groupError = $e->getMessage();
    }
}

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
</div>
<?php Html::footer(); ?>
