<?php
$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Main include failed");

require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";

if (!$user->admin) accessforbidden();

$langs->load('repimport@repimport');
$action = GETPOST('action', 'aZ09');
$message = '';
$error = '';

$defaultPath = DOL_DOCUMENT_ROOT.'/custom/repimport/rechnungs_ki_split_v20_7.py';
$defaultUrl = 'http://192.168.178.32:1234/v1/chat/completions';
$defaultModel = 'gemma-4-e4b-it';
$defaultTimeout = 300;
$currentPath = !empty($conf->global->REPIMPORT_PARSER_PATH) ? $conf->global->REPIMPORT_PARSER_PATH : $defaultPath;
$currentImportDir = !empty($conf->global->REPIMPORT_IMPORT_DIR) ? $conf->global->REPIMPORT_IMPORT_DIR : '';
$currentSammellieferanten = !empty($conf->global->REPIMPORT_SAMMELLIEFERANTEN) ? $conf->global->REPIMPORT_SAMMELLIEFERANTEN : "Amazon\neBay\nZalando";
$currentUrl = !empty($conf->global->REPIMPORT_LM_URL) ? $conf->global->REPIMPORT_LM_URL : $defaultUrl;
$currentModel = !empty($conf->global->REPIMPORT_LM_MODEL) ? $conf->global->REPIMPORT_LM_MODEL : $defaultModel;
$currentTimeout = !empty($conf->global->REPIMPORT_LM_TIMEOUT) ? (int)$conf->global->REPIMPORT_LM_TIMEOUT : $defaultTimeout;

if ($action === 'save') {
    $path = trim(GETPOST('parser_path', 'restricthtml'));
    $importDir = trim(GETPOST('import_dir', 'restricthtml'));
    $sammel = trim(GETPOST('sammellieferanten', 'restricthtml'));
    $lmUrl = trim(GETPOST('lm_url', 'restricthtml'));
    $lmModel = trim(GETPOST('lm_model', 'restricthtml'));
    $lmTimeout = (int)GETPOST('lm_timeout', 'int');
    if ($lmUrl === '') $lmUrl = $defaultUrl;
    if ($lmModel === '') $lmModel = $defaultModel;
    if ($lmTimeout < 30) $lmTimeout = 30;
    if ($lmTimeout > 1800) $lmTimeout = 1800;
    if ($path === '') {
        $error = 'Der Pfad zum Rechnungsparser darf nicht leer sein.';
    } else {
        dolibarr_set_const($db, 'REPIMPORT_PARSER_PATH', $path, 'chaine', 0, 'Pfad zum PDF-Rechnungsparser', $conf->entity);
        $currentPath = $path;
        dolibarr_set_const($db, 'REPIMPORT_IMPORT_DIR', $importDir, 'chaine', 0, 'Verzeichnis mit PDF-Rechnungen für den Sammelimport', $conf->entity);
        dolibarr_set_const($db, 'REPIMPORT_SAMMELLIEFERANTEN', $sammel, 'chaine', 0, 'Sammellieferanten, je Zeile ein Suchbegriff', $conf->entity);
        dolibarr_set_const($db, 'REPIMPORT_LM_URL', $lmUrl, 'chaine', 0, 'LM Studio OpenAI-kompatibler Endpunkt', $conf->entity);
        dolibarr_set_const($db, 'REPIMPORT_LM_MODEL', $lmModel, 'chaine', 0, 'LM Studio Modell-ID', $conf->entity);
        dolibarr_set_const($db, 'REPIMPORT_LM_TIMEOUT', (string)$lmTimeout, 'chaine', 0, 'LM Studio Timeout in Sekunden', $conf->entity);
        $currentImportDir = $importDir;
        $currentSammellieferanten = $sammel;
        $currentUrl = $lmUrl; $currentModel = $lmModel; $currentTimeout = $lmTimeout;
        $message = 'Einstellungen gespeichert.';
    }
}

if ($action === 'test') {
    $path = !empty($conf->global->REPIMPORT_PARSER_PATH) ? $conf->global->REPIMPORT_PARSER_PATH : $defaultPath;
    if (!is_file($path)) $error = 'Parser-Datei nicht gefunden: '.$path;
    elseif (!is_readable($path)) $error = 'Parser-Datei ist für den Webserver nicht lesbar: '.$path;
    else $message = 'Parser-Datei ist für den Webserver erreichbar und lesbar.';
}

llxHeader('', 'REP Rechnungsimport – Einstellungen');
print load_fiche_titre('REP Rechnungsimport – Einstellungen');
if ($message) setEventMessages($message, null, 'mesgs');
if ($error) setEventMessages($error, null, 'errors');

print '<form method="post">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">Rechnungsparser</td><td><input class="flat minwidth500" type="text" name="parser_path" value="'.dol_escape_htmltag($currentPath).'">';
print '<tr><td class="titlefield">Importverzeichnis</td><td><input class="flat minwidth500" type="text" name="import_dir" value="'.dol_escape_htmltag($currentImportDir).'">';
print '<tr><td class="titlefield">LM Studio URL</td><td><input class="flat minwidth500" type="text" name="lm_url" value="'.dol_escape_htmltag($currentUrl).'"><div class="opacitymedium">OpenAI-kompatibler Endpunkt, z. B. http://192.168.178.32:1234/v1/chat/completions</div></td></tr>';
print '<tr><td class="titlefield">LM Studio Modell</td><td><input class="flat minwidth500" type="text" name="lm_model" value="'.dol_escape_htmltag($currentModel).'"></td></tr>';
print '<tr><td class="titlefield">LM Studio Timeout</td><td><input class="flat width100" type="number" min="30" max="1800" name="lm_timeout" value="'.((int)$currentTimeout).'"> Sekunden</td></tr>';
print '<tr><td class="titlefield">Sammellieferanten</td><td><textarea class="flat minwidth500" name="sammellieferanten" rows="6">'.dol_escape_htmltag($currentSammellieferanten).'</textarea><div class="opacitymedium">Ein Begriff pro Zeile. Wird irgendwo im PDF gefunden, wird dieser Lieferant automatisch verwendet. Der ursprünglich erkannte Rechnungsaussteller bleibt als Fremdlieferant in den Bemerkungen erhalten.</div></td></tr>';
print '<div class="opacitymedium">Alle PDFs in diesem Verzeichnis können gesammelt analysiert werden.</div></td></tr>';
print '<div class="opacitymedium">Standard: '.dol_escape_htmltag($defaultPath).'</div></td></tr>';
print '<tr><td></td><td class="opacitymedium">Standard: '.dol_escape_htmltag($defaultUrl).' · '.dol_escape_htmltag($defaultModel).' · 300 Sekunden</td></tr>';
print '</table>';
print '<div class="center"><input class="button button-save" type="submit" value="Pfad speichern"></div>';
print '</form>';

print '<br><form method="post">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test">';
print '<div class="center"><input class="button" type="submit" value="Parser-Zugriff testen"></div>';
print '</form>';

print '<br><div class="warning">Der Test erfolgt mit den Rechten des Dolibarr-Webservers. Wenn der Parser unter <code>/home/bbernd/...</code> liegt, muss der Webserver-Benutzer mindestens Leserechte auf die Datei und Ausführungsrechte auf die Verzeichnisse haben.</div>';

llxFooter();
$db->close();
