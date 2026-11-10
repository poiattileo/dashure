<?php
include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight(PluginDashureProfile::RIGHT_NOTES, READ);

$self = (string) $CFG_GLPI['root_doc'] . '/plugins/dashure/front/note.form.php';
$home = (string) $CFG_GLPI['root_doc'] . '/front/central.php';
$h = [PluginDashureHome::class, 'h'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if (isset($_POST['delete'])) {
            PluginDashureNote::delete($id);
            Session::addMessageAfterRedirect('Anotação excluída.');
            Html::redirect($home);
        }
        $id = PluginDashureNote::save($_POST);
        Session::addMessageAfterRedirect('Anotação salva.');
        Html::redirect($home);
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        Html::redirect($self . ($id > 0 ? '?id=' . $id : ''));
    }
}

$id = (int) ($_GET['id'] ?? 0);
$note = null;
$profiles = [(int) ($_SESSION['glpiactiveprofile']['id'] ?? 0)];
if ($id > 0) {
    try {
        $note = PluginDashureNote::getVisible($id);
    } catch (Throwable $e) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    if (!PluginDashureNote::canEditRow($note)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $profiles = PluginDashureNote::profileIds($id);
} elseif (!PluginDashureNote::canCreate()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$hasPassword = $note !== null && (string) $note['password_encrypted'] !== '';
$notesText = $note !== null ? PluginDashureNote::decrypt((string) $note['notes_encrypted']) : '';

Html::header($note ? 'Editar anotação' : 'Nova anotação', $_SERVER['PHP_SELF'], 'central', 'central');
?>
<div class="dashure dashure-admin">
    <header class="dashure-hero">
        <?= PluginDashureHome::brandLogo() ?>
        <div class="dashure-hero-text"><h2><?= $note ? $h('Editar: ' . $note['title']) : 'Nova anotação' ?></h2>
            <p>A senha e as observações ficam criptografadas. Só os perfis marcados enxergam a anotação.</p></div>
        <a class="dashure-btn dashure-btn-ghost" href="<?= $h($home) ?>"><i class="ti ti-arrow-left"></i> Voltar ao Início</a>
    </header>

    <div class="dashure-grid">
        <div class="dashure-main">
            <section class="dashure-card">
                <form method="post" action="<?= $h($self) ?>" class="dashure-form" autocomplete="off">
                    <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <div class="dashure-form-row">
                        <label>Título<input class="form-control" name="title" maxlength="255" required value="<?= $h($note['title'] ?? '') ?>" placeholder="Ex.: Roteador da sala 3"></label>
                        <label>Categoria<input class="form-control" name="category" maxlength="100" list="dashure-categories" value="<?= $h($note['category'] ?? '') ?>" placeholder="Ex.: Rede, Sistemas, E-mail"></label>
                    </div>
                    <datalist id="dashure-categories">
                        <?php foreach (array_unique(array_filter(array_column(PluginDashureNote::visible(), 'category'))) as $cat): ?>
                            <option value="<?= $h($cat) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <label>Endereço <small>(site, IP ou caminho)</small><input class="form-control" name="url" maxlength="500" value="<?= $h($note['url'] ?? '') ?>"></label>
                    <div class="dashure-form-row">
                        <label>Login<input class="form-control" name="login" maxlength="255" autocomplete="off" value="<?= $h($note['login'] ?? '') ?>"></label>
                        <label>Senha
                            <span class="dashure-password-input">
                                <input class="form-control" type="password" name="password" autocomplete="new-password" data-dashure-password
                                       placeholder="<?= $hasPassword ? 'Deixe em branco para manter a atual' : '' ?>">
                                <button type="button" class="dashure-icon-btn" data-dashure-toggle-password title="Mostrar/ocultar" aria-label="Mostrar ou ocultar senha"><i class="ti ti-eye"></i></button>
                                <button type="button" class="dashure-icon-btn" data-dashure-generate title="Gerar senha forte" aria-label="Gerar senha forte"><i class="ti ti-wand"></i></button>
                            </span>
                        </label>
                    </div>
                    <?php if ($hasPassword): ?>
                        <label class="dashure-check"><input type="checkbox" name="clear_password" value="1"> Remover a senha salva</label>
                    <?php endif; ?>
                    <label>Observações <small>(criptografadas)</small><textarea class="form-control" name="notes" rows="5"><?= $h($notesText) ?></textarea></label>
                    <div class="dashure-form-row">
                        <label>Cor<select class="form-select" name="color">
                            <?php foreach (PluginDashureNote::COLORS as $key => $label): ?>
                                <option value="<?= $h($key) ?>"<?= ($note['color'] ?? 'default') === $key ? ' selected' : '' ?>><?= $h($label) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label class="dashure-check"><input type="checkbox" name="is_pinned" value="1"<?= (int) ($note['is_pinned'] ?? 0) === 1 ? ' checked' : '' ?>> Fixar no topo</label>
                    </div>

                    <fieldset class="dashure-profiles">
                        <legend>Quem pode ver <small>(perfis)</small></legend>
                        <?php foreach (PluginDashureNote::profileChoices() as $profileId => $name): ?>
                            <label class="dashure-check"><input type="checkbox" name="profiles[]" value="<?= (int) $profileId ?>"<?= in_array($profileId, $profiles, true) ? ' checked' : '' ?>> <?= $h($name) ?></label>
                        <?php endforeach; ?>
                        <small class="dashure-muted">O perfil também precisa da permissão "Anotações" (Administração → Perfis → Dashure). Quem criou e quem gerencia todas sempre veem.</small>
                    </fieldset>

                    <div class="dashure-form-actions">
                        <button class="dashure-btn" type="submit" name="save" value="1"><i class="ti ti-device-floppy"></i> Salvar</button>
                        <?php if ($note): ?>
                            <button class="dashure-btn dashure-btn-danger" type="submit" name="delete" value="1" formnovalidate
                                    onclick="return confirm('Excluir esta anotação? Não dá para desfazer.');"><i class="ti ti-trash"></i> Excluir</button>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
        </div>

        <?php if ($note): ?>
            <aside class="dashure-side">
                <section class="dashure-card" id="historico">
                    <header class="dashure-card-head"><h3><i class="ti ti-history"></i> Histórico</h3></header>
                    <?php $logs = PluginDashureNote::history($id); ?>
                    <?php if ($logs === []): ?>
                        <p class="dashure-muted">Sem registros.</p>
                    <?php else: ?>
                        <ul class="dashure-log">
                            <?php foreach ($logs as $log): ?>
                                <li><strong><?= $h(strip_tags((string) getUserName((int) $log['users_id']))) ?></strong>
                                    <?= $h(mb_strtolower(PluginDashureNote::ACTIONS[$log['action']] ?? $log['action'])) ?>
                                    <small><?= $h(date('d/m/Y H:i', strtotime((string) $log['date']))) ?></small></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </aside>
        <?php endif; ?>
    </div>
</div>
<?php Html::footer(); ?>
