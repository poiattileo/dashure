<?php
include('../../../inc/includes.php');

Session::checkRightsOr('profile', [CREATE, UPDATE, PURGE]);

$profileId = (int) ($_POST['profiles_id'] ?? 0);
if ($profileId > 0 && isset($_POST['update'])) {
    $home = (int) ($_POST['home_rights'] ?? 0);
    $notes = (int) ($_POST['notes_rights'] ?? 0);
    if (!isset(PluginDashureProfile::HOME_OPTIONS[$home]) || !isset(PluginDashureProfile::NOTES_OPTIONS[$notes])) {
        Session::addMessageAfterRedirect('Valor de permissão inválido.', false, ERROR);
        Html::back();
    }
    PluginDashureProfile::setRight($profileId, PluginDashureProfile::RIGHT_HOME, $home);
    PluginDashureProfile::setRight($profileId, PluginDashureProfile::RIGHT_NOTES, $notes);
    if ($profileId === (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0)) {
        PluginDashureProfile::changeProfile();
    }
    Session::addMessageAfterRedirect('Permissões do Dashure salvas.');
}

Html::back();
