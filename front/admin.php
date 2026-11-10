<?php
include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight('config', UPDATE);

$self = (string) $CFG_GLPI['root_doc'] . '/plugins/dashure/front/admin.php';
$h = [PluginDashureHome::class, 'h'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'notice_save':
                PluginDashureStore::saveNotice($_POST);
                Session::addMessageAfterRedirect('Aviso salvo.');
                break;
            case 'notice_delete':
                PluginDashureStore::deleteNotice((int) ($_POST['id'] ?? 0));
                Session::addMessageAfterRedirect('Aviso excluído.');
                break;
            case 'link_save':
                PluginDashureStore::saveLink($_POST);
                Session::addMessageAfterRedirect('Atalho salvo.');
                break;
            case 'link_delete':
                PluginDashureStore::deleteLink((int) ($_POST['id'] ?? 0));
                Session::addMessageAfterRedirect('Atalho excluído.');
                break;
            default:
                throw new RuntimeException('Ação inválida.');
        }
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect($self);
}

$editNotice = isset($_GET['notice']) ? PluginDashureStore::getNotice((int) $_GET['notice']) : null;
$editLink = isset($_GET['link']) ? PluginDashureStore::getLink((int) $_GET['link']) : null;
$csrf = static fn (): string => Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
$dt = static fn ($v): string => $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : '';

Html::header('Início — avisos e atalhos', $_SERVER['PHP_SELF'], 'config', 'plugin');
?>
<div class="dashure dashure-admin">
    <header class="dashure-hero">
        <div><h2>Avisos e atalhos</h2><p>O que aparece na aba Início da página inicial.</p></div>
        <a class="dashure-btn dashure-btn-ghost" href="<?= $h($CFG_GLPI['root_doc']) ?>/front/central.php"><i class="ti ti-home"></i> Ver Início</a>
    </header>

    <div class="dashure-grid">
        <div class="dashure-main">
            <section class="dashure-card">
                <header class="dashure-card-head"><h3><i class="ti ti-speakerphone"></i> <?= $editNotice ? 'Editar aviso' : 'Novo aviso' ?></h3>
                    <?php if ($editNotice): ?><a href="<?= $h($self) ?>">Cancelar edição</a><?php endif; ?></header>
                <form method="post" action="<?= $h($self) ?>" class="dashure-form">
                    <?= $csrf() ?>
                    <input type="hidden" name="action" value="notice_save">
                    <input type="hidden" name="id" value="<?= (int) ($editNotice['id'] ?? 0) ?>">
                    <label>Título<input class="form-control" name="title" maxlength="255" required value="<?= $h($editNotice['title'] ?? '') ?>"></label>
                    <label>Texto<textarea class="form-control" name="body" rows="4"><?= $h($editNotice['body'] ?? '') ?></textarea></label>
                    <div class="dashure-form-row">
                        <label>Tipo<select class="form-select" name="level">
                            <?php foreach (PluginDashureStore::LEVELS as $key => $label): ?>
                                <option value="<?= $h($key) ?>"<?= ($editNotice['level'] ?? 'info') === $key ? ' selected' : '' ?>><?= $h($label) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label>Mostrar a partir de<input class="form-control" type="datetime-local" name="date_begin" value="<?= $h($dt($editNotice['date_begin'] ?? null)) ?>"></label>
                        <label>Mostrar até<input class="form-control" type="datetime-local" name="date_end" value="<?= $h($dt($editNotice['date_end'] ?? null)) ?>"></label>
                    </div>
                    <div class="dashure-form-row">
                        <label class="dashure-check"><input type="checkbox" name="is_active" value="1"<?= (int) ($editNotice['is_active'] ?? 1) === 1 ? ' checked' : '' ?>> Ativo</label>
                        <label class="dashure-check"><input type="checkbox" name="is_pinned" value="1"<?= (int) ($editNotice['is_pinned'] ?? 0) === 1 ? ' checked' : '' ?>> Fixar no topo</label>
                    </div>
                    <div><button class="dashure-btn" type="submit"><i class="ti ti-device-floppy"></i> Salvar aviso</button></div>
                </form>
            </section>

            <section class="dashure-card">
                <header class="dashure-card-head"><h3><i class="ti ti-list"></i> Avisos cadastrados</h3></header>
                <?php $notices = PluginDashureStore::allNotices(); ?>
                <?php if ($notices === []): ?>
                    <p class="dashure-muted">Nenhum aviso cadastrado.</p>
                <?php else: ?>
                    <table class="table table-sm dashure-table">
                        <thead><tr><th>Título</th><th>Tipo</th><th>Período</th><th>Situação</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($notices as $n): ?>
                            <tr>
                                <td><?= (int) $n['is_pinned'] === 1 ? '<i class="ti ti-pin"></i> ' : '' ?><?= $h($n['title']) ?></td>
                                <td><span class="dashure-pill is-<?= $h($n['level']) ?>"><?= $h(PluginDashureStore::LEVELS[$n['level']] ?? $n['level']) ?></span></td>
                                <td><?= $h(($n['date_begin'] ? date('d/m/Y H:i', strtotime($n['date_begin'])) : 'sempre') . ' → ' . ($n['date_end'] ? date('d/m/Y H:i', strtotime($n['date_end'])) : 'sem fim')) ?></td>
                                <td><?= (int) $n['is_active'] === 1 ? 'Ativo' : 'Inativo' ?></td>
                                <td class="dashure-actions">
                                    <a class="dashure-btn dashure-btn-ghost" href="<?= $h($self . '?notice=' . (int) $n['id']) ?>"><i class="ti ti-pencil"></i></a>
                                    <form method="post" action="<?= $h($self) ?>" onsubmit="return confirm('Excluir este aviso?');">
                                        <?= $csrf() ?><input type="hidden" name="action" value="notice_delete"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                        <button class="dashure-btn dashure-btn-danger" type="submit" aria-label="Excluir"><i class="ti ti-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>

        <aside class="dashure-side">
            <section class="dashure-card">
                <header class="dashure-card-head"><h3><i class="ti ti-link"></i> <?= $editLink ? 'Editar atalho' : 'Novo atalho' ?></h3>
                    <?php if ($editLink): ?><a href="<?= $h($self) ?>">Cancelar</a><?php endif; ?></header>
                <form method="post" action="<?= $h($self) ?>" class="dashure-form">
                    <?= $csrf() ?>
                    <input type="hidden" name="action" value="link_save">
                    <input type="hidden" name="id" value="<?= (int) ($editLink['id'] ?? 0) ?>">
                    <label>Nome<input class="form-control" name="name" maxlength="100" required value="<?= $h($editLink['name'] ?? '') ?>"></label>
                    <label>Endereço<input class="form-control" name="url" maxlength="500" required placeholder="https://… ou /plugins/…" value="<?= $h($editLink['url'] ?? '') ?>"></label>
                    <div class="dashure-form-row">
                        <label>Ícone <small>(<a href="https://tabler.io/icons" target="_blank" rel="noopener">Tabler</a>)</small><input class="form-control" name="icon" placeholder="ti ti-link" value="<?= $h($editLink['icon'] ?? 'ti ti-link') ?>"></label>
                        <label>Ordem<input class="form-control" type="number" name="rank" value="<?= (int) ($editLink['rank'] ?? 0) ?>"></label>
                    </div>
                    <label class="dashure-check"><input type="checkbox" name="new_window" value="1"<?= (int) ($editLink['new_window'] ?? 0) === 1 ? ' checked' : '' ?>> Abrir em nova aba</label>
                    <div><button class="dashure-btn" type="submit"><i class="ti ti-device-floppy"></i> Salvar atalho</button></div>
                </form>
            </section>

            <section class="dashure-card">
                <header class="dashure-card-head"><h3><i class="ti ti-apps"></i> Atalhos</h3></header>
                <?php $links = PluginDashureStore::links(); ?>
                <?php if ($links === []): ?>
                    <p class="dashure-muted">Nenhum atalho cadastrado.</p>
                <?php else: ?>
                    <ul class="dashure-list">
                        <?php foreach ($links as $l): ?>
                            <li><div class="dashure-list-row">
                                <i class="<?= $h($l['icon']) ?>"></i>
                                <span class="dashure-list-main"><strong><?= $h($l['name']) ?></strong><small><?= $h($l['url']) ?></small></span>
                                <span class="dashure-actions">
                                    <a class="dashure-btn dashure-btn-ghost" href="<?= $h($self . '?link=' . (int) $l['id']) ?>"><i class="ti ti-pencil"></i></a>
                                    <form method="post" action="<?= $h($self) ?>" onsubmit="return confirm('Excluir este atalho?');">
                                        <?= $csrf() ?><input type="hidden" name="action" value="link_delete"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                        <button class="dashure-btn dashure-btn-danger" type="submit" aria-label="Excluir"><i class="ti ti-trash"></i></button>
                                    </form>
                                </span>
                            </div></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>
<?php Html::footer(); ?>
