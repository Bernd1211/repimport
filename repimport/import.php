<?php
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Main include failed");

require_once DOL_DOCUMENT_ROOT.'/custom/repimport/lib/repimport.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/repimport/class/repimport.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';

if (empty($user->rights->repimport->read)) accessforbidden();

$action = GETPOST('action', 'aZ09');
$json = null;
$error = '';
$success = '';
$parserOutput = '';
$sourcePdf = '';


function repimport_supplier_name($json)
{
    if (!is_array($json)) return '';
    $keys = array('lieferant_name', 'lieferant', 'supplier_name', 'supplier');
    foreach ($keys as $key) {
        if (isset($json[$key]) && is_scalar($json[$key])) {
            $v = trim((string) $json[$key]);
            if ($v !== '') return $v;
        }
    }
    if (!empty($json['lieferant']) && is_array($json['lieferant'])) {
        foreach (array('name', 'firma', 'bezeichnung') as $key) {
            if (!empty($json['lieferant'][$key])) return trim((string) $json['lieferant'][$key]);
        }
    }
    if (!empty($json['supplier']) && is_array($json['supplier'])) {
        foreach (array('name', 'company', 'label') as $key) {
            if (!empty($json['supplier'][$key])) return trim((string) $json['supplier'][$key]);
        }
    }
    return '';
}

function repimport_sammellieferanten()
{
    global $conf;
    $raw = !empty($conf->global->REPIMPORT_SAMMELLIEFERANTEN) ? (string)$conf->global->REPIMPORT_SAMMELLIEFERANTEN : '';
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $out = array();
    foreach ((array)$lines as $line) {
        $line = trim($line);
        if ($line !== '') $out[] = $line;
    }
    return $out;
}

function repimport_apply_sammellieferant(&$json)
{
    $keywords = repimport_sammellieferanten();
    if (!$keywords || !is_array($json)) return array();
    $text = (string)($json['_repimport_dokumenttext'] ?? '');
    $detected = repimport_supplier_name($json);
    // Primär wird der komplette PDF-Text geprüft. Zusätzlich wird der bereits
    // erkannte Rechnungsaussteller einbezogen. Das ist wichtig bei PDFs, bei
    // denen der Firmenkopf von pdftotext nicht vollständig geliefert wird.
    $searchText = $text;
    if ($detected !== '') $searchText .= "\n".$detected;
    if ($searchText === '') return array();
    foreach ($keywords as $keyword) {
        if (stripos($searchText, $keyword) !== false) {
            $remarks = is_array($json['bemerkungen'] ?? null) ? $json['bemerkungen'] : array();
            $remarks[] = 'Sammellieferant erkannt: '.$keyword;
            if ($detected !== '' && strcasecmp($detected, $keyword) !== 0) {
                $remarks[] = 'Rechnungsaussteller/Fremdlieferant: '.$detected;
                $json['fremdlieferant'] = $detected;
            }
            $json['sammellieferant'] = $keyword;
            $json['lieferant_vor_sammelregel'] = $detected;
            if (!is_array($json['lieferant'] ?? null)) $json['lieferant'] = array();
            $json['lieferant']['name'] = $keyword;
            $json['bemerkungen'] = array_values(array_unique($remarks));
            return $json['bemerkungen'];
        }
    }
    return array();
}

function repimport_pdf_text($pdf)
{
    if ($pdf === '' || !is_file($pdf)) return '';
    $cmd = 'pdftotext -layout '.escapeshellarg($pdf).' -';
    $out = array(); $rc = 1;
    @exec($cmd, $out, $rc);
    return $rc === 0 ? implode("\n", $out) : '';
}

function repimport_pdf_stream($jobId)
{
    $job = repimport_read_job($jobId);
    if (!$job || empty($job['pdf']) || !is_file($job['pdf'])) {
        http_response_code(404);
        exit;
    }
    $file = $job['pdf'];
    header('Content-Type: application/pdf');
    header('Content-Length: '.filesize($file));
    header('Content-Disposition: inline; filename="'.str_replace(array('"', "\\"), '_', basename($job['source_name'] ?? 'rechnung.pdf')).'"');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

function repimport_original_batch_pdf($jobId, $job)
{
    // Neue Jobs enthalten source_pdf direkt.
    if (is_array($job) && !empty($job['source_pdf']) && is_file($job['source_pdf'])) {
        return $job['source_pdf'];
    }

    // Ältere Jobs: Originalpfad aus der zugehörigen Batch-Datei ermitteln.
    $batchId = is_array($job) ? ($job['batch'] ?? '') : '';
    if ($batchId !== '') {
        $batch = repimport_batch_read($batchId);
        if (is_array($batch)) {
            foreach (($batch['files'] ?? array()) as $item) {
                if (($item['job'] ?? '') === $jobId && !empty($item['pdf']) && is_file($item['pdf'])) {
                    return $item['pdf'];
                }
            }
        }
    }
    return '';
}

function repimport_delete_job($jobId)
{
    $job = repimport_read_job($jobId);
    if (!$job) return false;

    $ok = true;
    $originalPdf = repimport_original_batch_pdf($jobId, $job);

    // Nur die vom Job selbst erzeugten Dateien löschen.
    foreach (array('pdf', 'json', 'log') as $key) {
        if (!empty($job[$key]) && is_file($job[$key]) && !@unlink($job[$key])) $ok = false;
    }

    if (!empty($job['workdir']) && is_dir($job['workdir'])) {
        $files = @scandir($job['workdir']);
        if (is_array($files)) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                @unlink($job['workdir'].'/'.$f);
            }
        }
        @rmdir($job['workdir']);
    }

    // Beim Sammelimport zusätzlich die Original-PDF aus dem eingestellten
    // Importverzeichnis löschen.
    if ($originalPdf !== '' && is_file($originalPdf)) {
        if (!@unlink($originalPdf)) $ok = false;
    }

    $jobFile = repimport_job_file($jobId);
    if (is_file($jobFile) && !@unlink($jobFile)) $ok = false;
    return $ok;
}

function repimport_delete_failed_job($jobId)
{
    $job = repimport_read_job($jobId);
    if (!$job || !in_array(($job['state'] ?? ''), array('error','aborted'), true)) return false;
    $ok = true;
    // Bei einer fehlerhaften Analyse bleibt die Original-PDF bewusst erhalten,
    // damit sie sofort erneut analysiert werden kann.
    foreach (array('json','log') as $key) {
        if (!empty($job[$key]) && is_file($job[$key]) && !@unlink($job[$key])) $ok = false;
    }
    if (!empty($job['workdir']) && is_dir($job['workdir'])) {
        foreach ((array)@scandir($job['workdir']) as $f) {
            if ($f === '.' || $f === '..' || $f === basename($job['pdf'] ?? '')) continue;
            @unlink($job['workdir'].'/'.$f);
        }
        // Die lokale Arbeitskopie der PDF darf ebenfalls entfernt werden.
        if (!empty($job['pdf']) && is_file($job['pdf'])) @unlink($job['pdf']);
        @rmdir($job['workdir']);
    }
    $jobFile = repimport_job_file($jobId);
    if (is_file($jobFile) && !@unlink($jobFile)) $ok = false;
    repimport_remove_batch_index_job($jobId);
    return $ok;
}

function repimport_value($array, $key)
{
    if (!is_array($array) || !array_key_exists($key, $array)) return '';
    return is_scalar($array[$key]) ? trim((string)$array[$key]) : '';
}

function repimport_json_from_post()
{
    $encoded = GETPOST('jsondata', 'none');
    if ($encoded === '') return null;
    $raw = base64_decode($encoded, true);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function repimport_supplier_post($key, $fallback = '')
{
    $v = GETPOST($key, 'restricthtml');
    return trim($v !== '' ? $v : $fallback);
}



function repimport_job_file($jobId)
{
    if (!preg_match('/^[a-f0-9]{32}$/', (string)$jobId)) return '';
    global $conf; $root=!empty($conf->fournisseur->facture->dir_output)?rtrim($conf->fournisseur->facture->dir_output,'/').'/repimport':''; return $root===''?'':$root.'/job_'.$jobId.'.json';
}

function repimport_read_job($jobId)
{
    $file = repimport_job_file($jobId);
    if ($file === '' || !is_file($file)) return null;
    $data = json_decode(@file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function repimport_write_job($jobId, $data)
{
    $file = repimport_job_file($jobId);
    if ($file === '') return false;
    return @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function repimport_process_alive($pid)
{
    $pid = (int)$pid;
    if ($pid <= 1) return false;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    $out = array();
    $rc = 1;
    @exec('kill -0 '.((int)$pid).' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

function repimport_kill_process($pid)
{
    $pid = (int)$pid;
    if ($pid <= 1) return false;

    if (function_exists('posix_kill')) {
        @posix_kill(-$pid, 15);
        usleep(300000);
        @posix_kill(-$pid, 9);
        @posix_kill($pid, 15);
        usleep(200000);
        @posix_kill($pid, 9);
        return true;
    }

    @exec('kill -TERM -- -'.((int)$pid).' 2>/dev/null');
    usleep(300000);
    @exec('kill -KILL -- -'.((int)$pid).' 2>/dev/null');
    return true;
}

function repimport_start_job($parser, $pdf, $workdir, $sourceName, $lmUrl='', $lmModel='', $lmTimeout=300)
{
    if (!is_file($parser)) return array('error' => 'Parser-Datei nicht gefunden: '.$parser);
    if (!is_readable($parser)) return array('error' => 'Parser-Datei für den Webserver nicht lesbar: '.$parser);
    if (!is_readable($pdf)) return array('error' => 'PDF-Datei für den Parser nicht lesbar: '.$pdf);

    $jobId = bin2hex(random_bytes(16));
    $log = $workdir.'/parser.log';
    $jsonPath = $workdir.'/rechnung_ergebnis.json';

    $cmd = 'cd '.escapeshellarg($workdir).' && setsid python3 '.escapeshellarg($parser).' '.escapeshellarg($pdf).' --url '.escapeshellarg($lmUrl).' --model '.escapeshellarg($lmModel).' --timeout '.((int)$lmTimeout).' > '.escapeshellarg($log).' 2>&1 & echo $!';
    $out = array();
    $rc = 1;
    @exec($cmd, $out, $rc);
    $pid = isset($out[0]) ? (int)trim($out[0]) : 0;

    if ($pid <= 1) {
        return array('error' => 'Der Rechnungsparser konnte nicht als Hintergrundprozess gestartet werden.');
    }

    repimport_write_job($jobId, array(
        'state' => 'running',
        'pid' => $pid,
        'pdf' => $pdf,
        'source_name' => $sourceName,
        'workdir' => $workdir,
        'log' => $log,
        'json' => $jsonPath,
        'created_at' => microtime(true),
        'created' => time()
    ));

    return array('job' => $jobId);
}

function repimport_batch_file($id){global $conf;if(!preg_match('/^[a-f0-9]{32}$/',$id))return ''; $r=rtrim($conf->fournisseur->facture->dir_output,'/').'/repimport';return $r.'/batch_'.$id.'.json';}
function repimport_batch_read($id){$f=repimport_batch_file($id);if(!is_file($f))return null;$d=json_decode(@file_get_contents($f),true);return is_array($d)?$d:null;}
function repimport_batch_write($id,$d){$f=repimport_batch_file($id);return @file_put_contents($f,json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;}
function repimport_batch_index_file(){global $conf;return rtrim($conf->fournisseur->facture->dir_output,'/').'/repimport/batch_index.json';}
function repimport_batch_index(){ $f=repimport_batch_index_file();if(!is_file($f))return []; $d=json_decode(@file_get_contents($f),true);return is_array($d)?$d:[];}
function repimport_remove_batch_index_job($jobId){
    $idx=repimport_batch_index();
    $changed=false;
    foreach($idx as $fp=>$r){
        if(($r['job']??'')===$jobId){
            unset($idx[$fp]);
            $changed=true;
        }
    }
    if($changed) repimport_batch_index_save($idx);
    return $changed;
}
function repimport_batch_index_save($d){return @file_put_contents(repimport_batch_index_file(),json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;}
function repimport_batch_fp($p){$st=@stat($p);return $st?sha1(realpath($p).'|'.$st['size'].'|'.$st['mtime']):'';}
function repimport_format_seconds($seconds){$seconds=max(0,(float)$seconds);$m=(int)floor($seconds/60);$s=$seconds-($m*60);return $m.' min '.number_format($s,1,',','').' s';}
if ($action === 'clear_history') {
    if (empty($user->rights->repimport->write)) accessforbidden();
    if (!GETPOST('confirm_clear', 'alpha')) {
        accessforbidden();
    }
    $root = !empty($conf->fournisseur->facture->dir_output) ? rtrim($conf->fournisseur->facture->dir_output, '/') . '/repimport' : '';
    $ok = true;
    if ($root !== '' && is_dir($root)) {
        foreach ((array)@scandir($root) as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $root.'/'.$f;
            if (is_dir($path)) {
                $files = @scandir($path);
                if (is_array($files)) foreach ($files as $sf) {
                    if ($sf === '.' || $sf === '..') continue;
                    @unlink($path.'/'.$sf);
                }
                if (!@rmdir($path)) $ok = false;
            } elseif (!@unlink($path)) {
                $ok = false;
            }
        }
    }
    if ($ok) setEventMessages('Bisherige REP-Import-Bearbeitungen wurden gelöscht. Die PDFs im eingestellten Importverzeichnis und bereits archivierte Rechnungen bleiben erhalten.', null, 'mesgs');
    else setEventMessages('Die bisherigen Bearbeitungen konnten nicht vollständig gelöscht werden.', null, 'errors');
    header('Location: import.php?action=batch_list');
    exit;
}

if ($action === 'delete_failed_jobs') {
    if (empty($user->rights->repimport->write)) accessforbidden();
    $idx = repimport_batch_index();
    $count = 0;
    foreach ($idx as $fp => $r) {
        $jobId = $r['job'] ?? '';
        if ($jobId === '') continue;
        $job = repimport_read_job($jobId);
        if (is_array($job) && in_array(($job['state'] ?? ($r['state'] ?? '')), array('error','aborted'), true)) {
            if (repimport_delete_failed_job($jobId)) $count++;
        }
    }
    // Auch Jobs berücksichtigen, die nicht mehr im Index stehen.
    $root = rtrim($conf->fournisseur->facture->dir_output,'/').'/repimport';
    foreach ((array)glob($root.'/job_*.json') as $jf) {
        $jid = basename($jf, '.json');
        if (strpos($jid, 'job_') === 0) {
            $id = substr($jid, 4); $job = repimport_read_job($id);
            if (is_array($job) && in_array(($job['state'] ?? ''), array('error','aborted'), true)) {
                if (repimport_delete_failed_job($id)) $count++;
            }
        }
    }
    setEventMessages($count.' fehlerhafte/abgebrochene Analyse(n) gelöscht. Die Original-PDFs bleiben erhalten.', null, 'mesgs');
    header('Location: import.php?action=batch_list'); exit;
}

if ($action === 'delete_job') {
    if (empty($user->rights->repimport->write)) accessforbidden();

    $jobId = GETPOST('job', 'alphanohtml');
    if ($jobId === '') {
        setEventMessages('Kein Analyseauftrag angegeben.', null, 'errors');
        header('Location: import.php?action=batch_list');
        exit;
    }

    $job = repimport_read_job($jobId);
    $deleted = false;
    if (is_array($job)) {
        $deleted = repimport_delete_job($jobId);
    }

    // Bei Sammelimporten zusätzlich den Warteschlangenindex entfernen.
    repimport_remove_batch_index_job($jobId);

    if ($deleted) {
        setEventMessages('Analyse und zugehörige Import-PDF wurden gelöscht.', null, 'mesgs');
    } else {
        setEventMessages('Analyseauftrag wurde nicht gefunden oder konnte nicht vollständig gelöscht werden.', null, 'errors');
    }
    header('Location: import.php?action=batch_list');
    exit;
}

if($action==='batch_start'){ $dir=trim($conf->global->REPIMPORT_IMPORT_DIR??''); if($dir===''||!is_dir($dir)||!is_readable($dir)){$error='Importverzeichnis ist nicht vorhanden oder nicht lesbar.';}else{$root=rtrim($conf->fournisseur->facture->dir_output,'/').'/repimport';dol_mkdir($root);$files=glob(rtrim($dir,'/').'/*.pdf')?:[];natcasesort($files);$idx=repimport_batch_index();$items=[];$skip=0;foreach($files as $pdf){$fp=repimport_batch_fp($pdf);if($fp!==''&&isset($idx[$fp])){$skip++;continue;}$items[]=['pdf'=>$pdf,'source_name'=>basename($pdf),'fingerprint'=>$fp,'state'=>'queued','job'=>bin2hex(random_bytes(16))];}if(!$items){$success='Keine neuen PDF-Rechnungen gefunden. Übersprungen: '.$skip.'.';}else{$bid=bin2hex(random_bytes(16));$b=['id'=>$bid,'state'=>'queued','import_dir'=>$dir,'files'=>$items,'total'=>count($items),'completed'=>0,'current'=>null,'created_at'=>microtime(true)];repimport_batch_write($bid,$b);foreach($items as $it)$idx[$it['fingerprint']]=['state'=>'queued','batch'=>$bid,'job'=>$it['job'],'source_name'=>$it['source_name']];repimport_batch_index_save($idx);$worker=DOL_DOCUMENT_ROOT.'/custom/repimport/batch_worker.php';$log=$root.'/batch_'.$bid.'.log';$parser=$conf->global->REPIMPORT_PARSER_PATH??(DOL_DOCUMENT_ROOT.'/custom/repimport/rechnungs_ki_split_v20_7.py');$lmUrl=$conf->global->REPIMPORT_LM_URL??'http://192.168.178.32:1234/v1/chat/completions';$lmModel=$conf->global->REPIMPORT_LM_MODEL??'gemma-4-e4b-it';$lmTimeout=max(30,min(1800,(int)($conf->global->REPIMPORT_LM_TIMEOUT??300)));$cmd='setsid php '.escapeshellarg($worker).' '.escapeshellarg($root.'/batch_'.$bid.'.json').' '.escapeshellarg($parser).' '.escapeshellarg($root).' '.escapeshellarg($lmUrl).' '.escapeshellarg($lmModel).' '.((int)$lmTimeout).' > '.escapeshellarg($log).' 2>&1 & echo $!';$o=[];$rc=1;@exec($cmd,$o,$rc);$b['pid']=isset($o[0])?(int)$o[0]:0;repimport_batch_write($bid,$b);llxHeader('','REP Rechnungsimport – Sammelimport');print load_fiche_titre('REP Rechnungsimport – Sammelimport');print '<div class="warning" id="batch-running"><strong>Sammelanalyse läuft ...</strong><br><span id="cnt">0</span> / '.count($items).' Rechnungen verarbeitet.<br><button type="button" class="button" id="batch-abort" style="margin-top:8px">ANALYSE ABBRECHEN</button></div><pre id="lst" style="border:1px solid #ccc;padding:10px;white-space:pre-wrap;">Warteschlange gestartet ...</pre>';print '<div class="ok" style="margin-top:6px;"><strong>Gesamte Berechnungszeit: <span id="batch-elapsed">00:00</span></strong></div>';print '<script>(function(){var id='.json_encode($bid).';var started=Date.now();var et=document.getElementById("batch-elapsed");function fmt(ms){var sec=Math.floor(ms/1000),m=Math.floor(sec/60),s=sec%60;return (m<10?"0":"")+m+":"+(s<10?"0":"")+s;}var etimer=setInterval(function(){et.textContent=fmt(Date.now()-started);},1000);var t=setInterval(function(){fetch("import.php?action=batch_status&batch="+id,{cache:"no-store"}).then(r=>r.json()).then(function(d){document.getElementById("cnt").textContent=d.completed||0;document.getElementById("lst").textContent=(d.files||[]).map(function(x){var z=x.duration_seconds!=null?" ("+x.duration_seconds.toFixed(1)+" s)":"";return (x.state==="done"?"✓ ":x.state==="error"?"⚠ ":x.state==="running"?"▶ ":x.state==="aborted"?"■ ":"… ")+x.source_name+z;}).join("\n");if(d.state==="done"||d.state==="aborted"){clearInterval(t);clearInterval(etimer);if(d.duration_seconds!=null)et.textContent=fmt(d.duration_seconds*1000);location="import.php?action=batch_list";}});},1000);var ab=document.getElementById("batch-abort");ab.addEventListener("click",function(){if(!confirm("Die laufende Sammelanalyse wirklich abbrechen?"))return;ab.disabled=true;ab.textContent="ABBRUCH WIRD AUSGEFÜHRT ...";var body=new URLSearchParams();body.set("token",'.json_encode(newToken()).');body.set("batch",id);fetch("import.php?action=batch_abort",{method:"POST",body:body,credentials:"same-origin"}).then(r=>r.json()).then(function(d){ab.textContent="ABGEBROCHEN";document.getElementById("batch-running").innerHTML="<strong>"+(d.message||"Sammelanalyse abgebrochen.")+"</strong>";}).catch(function(){ab.disabled=false;ab.textContent="ANALYSE ABBRECHEN";});});})();</script>';llxFooter();$db->close();exit;}}}
if($action==='batch_abort'){
    if (empty($user->rights->repimport->write)) accessforbidden();
    header('Content-Type: application/json; charset=utf-8');
    $bid=GETPOST('batch','alphanohtml');
    $b=repimport_batch_read($bid);
    if(!$b){ print json_encode(array('state'=>'error','message'=>'Sammelauftrag nicht gefunden.')); exit; }
    if(($b['state']??'')==='running'){
        if(!empty($b['pid'])) repimport_kill_process((int)$b['pid']);
        $b['state']='aborted'; $b['message']='Sammelanalyse wurde abgebrochen.'; $b['finished_at']=microtime(true);
        if(!empty($b['started_at'])) $b['duration_seconds']=round($b['finished_at']-$b['started_at'],1);
        $cur=$b['current']??null;
        if($cur!==null && isset($b['files'][$cur])){
            $b['files'][$cur]['state']='aborted';
            $jid=$b['files'][$cur]['job']??'';
            if($jid){$j=repimport_read_job($jid);if($j){$j['state']='aborted';$j['message']='Sammelanalyse wurde abgebrochen.';repimport_write_job($jid,$j);}}
        }
        repimport_batch_write($bid,$b);
        $idx=repimport_batch_index();
        foreach(($b['files']??array()) as $it){ if(!empty($it['fingerprint'])) $idx[$it['fingerprint']]=array_merge($idx[$it['fingerprint']]??array(),array('state'=>$it['state']??'aborted','batch'=>$bid,'job'=>$it['job']??'')); }
        repimport_batch_index_save($idx);
    }
    print json_encode(array('state'=>$b['state']??'aborted','message'=>$b['message']??'Sammelanalyse wurde beendet.')); exit;
}

if($action==='batch_status'){header('Content-Type: application/json; charset=utf-8');$b=repimport_batch_read(GETPOST('batch','alphanohtml'));print json_encode($b?:['state'=>'error'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($action==='batch_list'){
    $idx=repimport_batch_index();
    foreach($idx as $fp=>$r){
        if(!empty($r['batch']) && !empty($r['job'])){
            $bb=repimport_batch_read($r['batch']);
            if(is_array($bb)){
                foreach(($bb['files']??[]) as $bf){
                    if(($bf['job']??'')===$r['job']){
                        $idx[$fp]=array_merge($r,[
                            'state'=>$bf['state']??($r['state']??''),
                            'duration_seconds'=>$bf['duration_seconds']??($r['duration_seconds']??null),
                            'message'=>$bf['message']??($r['message']??null)
                        ]);
                        break;
                    }
                }
            }
        }
    }
    repimport_batch_index_save($idx);
    llxHeader('','REP Rechnungsimport – Warteschlange');
    print load_fiche_titre('REP Rechnungsimport – Warteschlange');
    print '<p>Bereits analysierte PDFs werden bei späteren Sammelläufen nicht erneut analysiert.</p>';
    print '<form method="post" action="import.php?action=clear_history" style="margin:10px 0 15px" onsubmit="return confirm(\"Alle bisherigen REP-Import-Bearbeitungen und Analyseergebnisse löschen? Die PDFs im Importverzeichnis bleiben erhalten.\");">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
    print '<input type="hidden" name="confirm_clear" value="yes">';
    print '<button type="submit" class="button" style="color:#a00">BISHERIGE BEARBEITUNGEN LÖSCHEN</button>';
    print '</form>';
    print '<form method="post" action="import.php?action=delete_failed_jobs" style="margin:10px 0 15px" onsubmit="return confirm(\"Alle fehlerhaften oder abgebrochenen Analysen löschen? Die Original-PDFs bleiben erhalten.\");">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
    print '<button type="submit" class="button" style="color:#a00">FEHLERHAFTE ERKENNUNGEN LÖSCHEN</button></form>';
    print '<table class="liste"><tr><th>Status</th><th>Datei</th><th>Erkannter Lieferant</th><th>Berechnungszeit</th><th>Aktion</th></tr>';
    foreach($idx as $r){
        $st=$r['state']??'';
        $dur=isset($r['duration_seconds'])?repimport_format_seconds($r['duration_seconds']):'–';
        $json=[];
        if(!empty($r['job'])){
            $job=repimport_read_job($r['job']);
            if(is_array($job) && !empty($job['json']) && is_file($job['json'])){
                $tmp=json_decode(@file_get_contents($job['json']),true);
                if(is_array($tmp)) $json=$tmp;
            }
        }
        if (is_array($json)) repimport_apply_sammellieferant($json);
        $supplier=repimport_supplier_name($json);
        $actionHtml='';
        if($st==='done'&&!empty($r['job'])){
            $actionHtml.='<a class="button" href="import.php?action=result&job='.rawurlencode($r['job']).'">BEARBEITEN</a>';
        }
        if(!empty($r['job'])){
            $actionHtml.='<form method="post" action="import.php?action=delete_job" style="display:inline;margin-left:6px" onsubmit="return confirm(&quot;Diese Analyse und die zugehörige PDF wirklich löschen?&quot;);">';
            $actionHtml.='<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
            $actionHtml.='<input type="hidden" name="job" value="'.dol_escape_htmltag($r['job']).'">';
            $actionHtml.='<button type="submit" class="button" style="color:#a00">LÖSCHEN</button></form>';
        }
        print '<tr><td>'.dol_escape_htmltag($st).'</td><td>'.dol_escape_htmltag($r['source_name']??'').'</td><td>'.dol_escape_htmltag($supplier).'</td><td>'.dol_escape_htmltag($dur).'</td><td>'.$actionHtml.'</td></tr>';
    }
    print '</table><br><a class="button" href="import.php">Zurück</a>';
    llxFooter();
    $db->close();
    exit;
}

if ($action === 'start') {
    if (empty($_FILES['pdf']['tmp_name']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Bitte eine PDF-Datei auswählen.';
    } else {
        $name = $_FILES['pdf']['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $error = 'Nur PDF-Dateien sind zulässig.';
        } else {
            // Die Upload-PDF darf nicht unter /tmp liegen, da sie dort durch
            // die Systembereinigung verschwinden kann, bevor die Rechnung
            // angelegt und das Original-Dokument verknüpft wurde.
            // Wir verwenden deshalb einen dauerhaften, von Dolibarr
            // beschreibbaren Bereich unter dem Dokumentenverzeichnis.
            $repimportRoot = rtrim($conf->fournisseur->facture->dir_output, '/').'/repimport';
            if ($repimportRoot === '/repimport' || $repimportRoot === '') {
                $error = 'Das Dolibarr-Dokumentverzeichnis für REP Import ist nicht gesetzt.';
            } elseif (dol_mkdir($repimportRoot) < 0) {
                $error = 'Das dauerhafte REP-Import-Verzeichnis konnte nicht angelegt werden: '.$repimportRoot;
            } else {
                $workdir = $repimportRoot.'/job_'.bin2hex(random_bytes(8));
                if (!@mkdir($workdir, 0700, true)) {
                    $error = 'Arbeitsverzeichnis für die PDF konnte nicht angelegt werden.';
                } else {
                    $pdfPath = $workdir.'/rechnung.pdf';
                if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfPath)) {
                    $error = 'Die PDF konnte nicht gespeichert werden.';
                } else {
                    $parser = !empty($conf->global->REPIMPORT_PARSER_PATH)
                        ? $conf->global->REPIMPORT_PARSER_PATH
                        : DOL_DOCUMENT_ROOT.'/custom/repimport/rechnungs_ki_split_v20_7.py';
                    $lmUrl = !empty($conf->global->REPIMPORT_LM_URL) ? $conf->global->REPIMPORT_LM_URL : 'http://192.168.178.32:1234/v1/chat/completions';
                    $lmModel = !empty($conf->global->REPIMPORT_LM_MODEL) ? $conf->global->REPIMPORT_LM_MODEL : 'gemma-4-e4b-it';
                    $lmTimeout = !empty($conf->global->REPIMPORT_LM_TIMEOUT) ? max(30, min(1800, (int)$conf->global->REPIMPORT_LM_TIMEOUT)) : 300;

                    $job = repimport_start_job($parser, $pdfPath, $workdir, $name, $lmUrl, $lmModel, $lmTimeout);
                    if (!empty($job['error'])) {
                        $error = $job['error'];
                    } else {
                        $jobId = $job['job'];

                        llxHeader('', 'REP Rechnungsimport');
                        print load_fiche_titre('REP Rechnungsimport');
                        print '<div class="warning" id="repimport-running" style="font-size:1.05em;">';
                        print '<strong>Rechnungsanalyse läuft ...</strong><br>';
                        print 'Der Parser arbeitet im Hintergrund. ';
                        print '<strong>Laufzeit: <span id="repimport-elapsed">00:00</span></strong>';
                        print '<br><button type="button" class="button" id="repimport-abort">ANALYSE ABBRECHEN</button>';
                        print '</div>';
                        print '<pre id="repimport-log" style="height:260px;overflow:auto;white-space:pre-wrap;border:1px solid #ccc;padding:10px;background:#f7f7f7;">Analyse wurde gestartet ...</pre>';

                        print '<script>
(function(){
  var job = '.json_encode($jobId).';
  var token = '.json_encode(newToken()).';
  var timer = null;
  var elapsedTimer = null;
  var startedAt = Date.now();
  var elapsed = document.getElementById("repimport-elapsed");
  var log = document.getElementById("repimport-log");
  var box = document.getElementById("repimport-running");
  var btn = document.getElementById("repimport-abort");

  function formatElapsed(ms){
    var sec = Math.floor(ms / 1000);
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    sec = sec % 60;
    return (h > 0 ? String(h).padStart(2,"0") + ":" : "") + String(m).padStart(2,"0") + ":" + String(sec).padStart(2,"0");
  }

  function updateElapsed(){
    if (elapsed) elapsed.textContent = formatElapsed(Date.now() - startedAt);
  }

  function updateElapsedFinal(seconds){
    if (elapsed) elapsed.textContent = formatElapsed(seconds * 1000);
  }

  function poll(){
    updateElapsed();
    fetch("import.php?action=status&job="+encodeURIComponent(job), {
      cache:"no-store", credentials:"same-origin"
    }).then(function(r){ return r.json(); }).then(function(d){
      if (d.log !== undefined) {
        log.textContent = d.log || "";
        log.scrollTop = log.scrollHeight;
      }
      if (d.state === "done") {
        if (d.duration_seconds !== null) updateElapsedFinal(d.duration_seconds);
        if (d.result_ready) {
          clearInterval(timer);
          clearInterval(elapsedTimer);
          if (box) box.innerHTML = "<strong>Analyse abgeschlossen.</strong> &nbsp; Berechnungszeit: <strong>" + (d.duration_seconds !== null ? d.duration_seconds.toFixed(1) + " Sekunden" : formatElapsed(Date.now() - startedAt)) + "</strong>";
          setTimeout(function(){ window.location = "import.php?action=result&job="+encodeURIComponent(job)+"&t="+Date.now(); }, 700);
        }
      } else if (d.state === "error" || d.state === "aborted") {
        clearInterval(timer);
        clearInterval(elapsedTimer);
        box.innerHTML = "<strong>"+(d.message || "Analyse beendet.")+"</strong>";
      }
    }).catch(function(){});
  }

  btn.addEventListener("click", function(){
    if (!confirm("Die laufende Rechnungsanalyse wirklich abbrechen?")) return;
    btn.disabled = true;
    btn.textContent = "ABBRUCH WIRD AUSGEFÜHRT ...";
    var body = new URLSearchParams();
    body.set("token", token);
    body.set("job", job);

    fetch("import.php?action=abort", {
      method:"POST", body:body, credentials:"same-origin"
    }).then(function(r){ return r.json(); }).then(function(d){
      clearInterval(timer);
      clearInterval(elapsedTimer);
      btn.textContent = "ABGEBROCHEN";
      box.innerHTML = "<strong>"+(d.message || "Analyse abgebrochen.")+"</strong>";
    }).catch(function(){
      btn.disabled = false;
      btn.textContent = "ANALYSE ABBRECHEN";
    });
  });

  poll();
  timer = setInterval(poll, 1000);
  elapsedTimer = setInterval(updateElapsed, 1000);
  updateElapsed();
})();
</script>';

                        llxFooter();
                        $db->close();
                        exit;
                    }
                }
            }
        }
    }
}

}

if ($action === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    $jobId = GETPOST('job', 'alphanohtml');
    $job = repimport_read_job($jobId);

    if (!$job) {
        print json_encode(array('state'=>'error','message'=>'Analyseauftrag nicht gefunden.'));
        exit;
    }

    $logText = '';
    if (!empty($job['log']) && is_file($job['log'])) {
        $logText = @file_get_contents($job['log']);
        if ($logText === false) $logText = '';
    }

    if (($job['state'] ?? '') === 'running' && !repimport_process_alive((int)$job['pid'])) {
        $job['finished_at'] = microtime(true);
        $createdAt = isset($job['created_at']) ? (float)$job['created_at'] : (float)($job['created'] ?? time());
        $job['duration_seconds'] = max(0, $job['finished_at'] - $createdAt);
        if (!empty($job['json']) && is_file($job['json'])) {
            $job['state'] = 'done';
        } else {
            $job['state'] = 'error';
            $job['message'] = 'Der Parser wurde beendet, aber es wurde keine Ergebnisdatei erzeugt.';
        }
        repimport_write_job($jobId, $job);
    }

    print json_encode(array(
        'state' => $job['state'] ?? 'error',
        'message' => $job['message'] ?? '',
        'log' => $logText,
        'duration_seconds' => isset($job['duration_seconds']) ? round((float)$job['duration_seconds'], 1) : null,
        'result_ready' => (($job['state'] ?? '') === 'done' && !empty($job['json']) && is_file($job['json']))
    ), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'abort') {
    header('Content-Type: application/json; charset=utf-8');
    $jobId = GETPOST('job', 'alphanohtml');
    $job = repimport_read_job($jobId);

    if (!$job) {
        print json_encode(array('state'=>'error','message'=>'Analyseauftrag nicht gefunden.'));
        exit;
    }

    if (($job['state'] ?? '') === 'running') {
        repimport_kill_process((int)($job['pid'] ?? 0));
        $job['state'] = 'aborted';
        $job['message'] = 'Rechnungsanalyse wurde abgebrochen.';
        repimport_write_job($jobId, $job);
    }

    print json_encode(array(
        'state' => $job['state'],
        'message' => $job['message'] ?? 'Analyse wurde beendet.'
    ));
    exit;
}

if ($action === 'pdf') {
    repimport_pdf_stream(GETPOST('job', 'alphanohtml'));
}

if ($action === 'result') {
    $jobId = GETPOST('job', 'alphanohtml');
    $job = repimport_read_job($jobId);

    if (!$job || ($job['state'] ?? '') !== 'done' || empty($job['json']) || !is_file($job['json'])) {
        $error = 'Das Analyseergebnis ist noch nicht verfügbar. Bitte die Vorschau erneut aufrufen.';
    } else {
        $raw = @file_get_contents($job['json']);
        $json = json_decode($raw, true);

        if (!is_array($json)) {
            $error = 'Die vom Parser erzeugte JSON-Datei konnte nicht gelesen werden.';
        } else {
            $json['quelldatei'] = $job['source_name'] ?? basename($job['pdf']);
            $json['_repimport_pdf'] = $job['pdf'];
            if (empty($json['_repimport_dokumenttext']) && !empty($job['pdf'])) {
                $json['_repimport_dokumenttext'] = repimport_pdf_text($job['pdf']);
            }
            repimport_apply_sammellieferant($json);
            $json['_repimport_workdir'] = $job['workdir'];
            if (isset($job['duration_seconds'])) {
                $json['_repimport_berechnungszeit'] = round((float)$job['duration_seconds'], 1);
            }
        }
    }
}

if ($action === 'preview') {
    $error = 'Bitte die PDF-Analyse über den VORSCHAU-Button starten.';
}

if ($action === 'create') {
    if (empty($user->rights->repimport->write)) accessforbidden();
    $json = repimport_json_from_post();
    if (!$json) $error = 'Die übermittelten Rechnungsdaten sind ungültig.';
    else {
        $lieferant = is_array($json['lieferant'] ?? null) ? $json['lieferant'] : array();
        $rechnung = is_array($json['rechnung'] ?? null) ? $json['rechnung'] : array();
        $positions = is_array($json['positionen'] ?? null) ? $json['positionen'] : array();
        $vat = str_replace(',', '.', trim(GETPOST('vat_rate', 'alpha')));
        $vatValue = is_numeric($vat) ? (float)$vat : null;
        $rep = new RepImport($db);
        $supplierId = function_exists('GETPOSTINT') ? GETPOSTINT('supplier_id') : (int)GETPOST('supplier_id', 'int');
        $supplier = $supplierId > 0 ? $rep->getSupplierById($supplierId) : null;
        // Sicherheitsnetz: wenn der Benutzer keinen Eintrag gewählt hat,
        // darf ein exakter, bereits erkannter Lieferant weiterhin automatisch
        // verwendet werden. Eine manuelle Auswahl hat aber immer Vorrang.
        if (!$supplier && $supplierId <= 0) {
            $supplier = $rep->findSupplierByName(repimport_supplier_post('supplier_name', repimport_value($lieferant, 'name')));
        }
        if (!$supplier && GETPOSTINT('new_supplier') === 1 && !empty($user->rights->societe->creer)) {
            $newSupplier = new Fournisseur($db);
            $newSupplier->name = repimport_supplier_post('supplier_name', repimport_value($lieferant, 'name'));
            $newSupplier->nom = $newSupplier->name;
            $newSupplier->address = repimport_supplier_post('supplier_address', repimport_value($lieferant, 'adresse'));
            $newSupplier->zip = repimport_supplier_post('supplier_zip', repimport_value($lieferant, 'plz'));
            $newSupplier->town = repimport_supplier_post('supplier_town', repimport_value($lieferant, 'ort'));
            $newSupplier->phone = repimport_supplier_post('supplier_phone', repimport_value($lieferant, 'telefon'));
            $newSupplier->email = repimport_supplier_post('supplier_email', repimport_value($lieferant, 'email'));
            $newSupplier->url = repimport_supplier_post('supplier_url', repimport_value($lieferant, 'webseite'));
            $newSupplier->tva_intra = repimport_supplier_post('supplier_tva_intra', repimport_value($lieferant, 'ust_id'));
            $newSupplier->fournisseur = 1;
            if ($newSupplier->name === '') $error = 'Der Firmenname des neuen Lieferanten darf nicht leer sein.';
            else { $nid = $newSupplier->create($user); if ($nid <= 0) $error = 'Neuer Lieferant konnte nicht angelegt werden: '.($newSupplier->error ?: $db->lasterror()); else $supplier = $newSupplier; }
        }
        $invoiceDate = $rep->parseDate(repimport_value($rechnung, 'rechnungsdatum'));
        $dueDate = $rep->parseDate(repimport_value($rechnung, 'faelligkeitsdatum'));
        $netTotal = $rep->sumPositionTotals($positions);
        $invoiceTaxBase = isset($json['rechnungsummen']['steuerbemessungsgrundlage'])
            ? (float)$json['rechnungsummen']['steuerbemessungsgrundlage'] : 0.0;
        if ($invoiceTaxBase > 0) {
            $netTotal = round($invoiceTaxBase, 2);
        }
        if (!$error && !$supplier) $error = $rep->error ?: 'Kein Lieferant ausgewählt.';
        elseif (!$error && !$invoiceDate) $error = 'Rechnungsdatum konnte nicht erkannt werden.';
        elseif (!$error && !$positions) $error = 'Keine Rechnungspositionen vorhanden.';
        elseif (!$error && ($vatValue === null || $vatValue < 0 || $vatValue > 100)) $error = 'Bitte einen gültigen Mehrwertsteuersatz eingeben.';
        elseif (!$error && $netTotal <= 0) $error = 'Die Summe der übernommenen Rechnungspositionen ist nicht gültig.';
        else if (!$error) {
            $repRef = $rep->getNextRep($invoiceDate);
            if ($repRef === -1) $error = 'REP-Nummer konnte nicht vergeben werden: '.$rep->error;
            else {
                $object = new FactureFournisseur($db);
                $object->socid = (int)$supplier->id;
                $object->ref_supplier = repimport_value($rechnung, 'rechnungsnummer');
                $object->date = $invoiceDate;
                $object->date_echeance = $dueDate ?: '';
                $object->label = 'REP '.$repRef.' – '.repimport_value($rechnung, 'rechnungsnummer');
                $noteParts = array(
    'REP-Import: '.$repRef,
    'Quelldatei: '.repimport_value($json, 'quelldatei'),
    'Die erkannten Rechnungspositionen wurden als eine Freitextposition übernommen.'
);
if (!empty($json['bemerkungen']) && is_array($json['bemerkungen'])) {
    foreach ($json['bemerkungen'] as $remark) {
        $remark = trim((string)$remark);
        if ($remark !== '') $noteParts[] = 'Bemerkung: '.$remark;
    }
}
$manualRemarks = trim(GETPOST('bemerkungen', 'restricthtml'));
if ($manualRemarks !== '') {
    foreach (preg_split('/\r\n|\r|\n/', $manualRemarks) as $remark) {
        $remark = trim($remark);
        if ($remark !== '') $noteParts[] = 'Bemerkung: '.$remark;
    }
}
if (!empty($json['zusatzkosten']) && is_array($json['zusatzkosten'])) {
    foreach ($json['zusatzkosten'] as $extra) {
        $noteParts[] = 'Zusatzkosten laut Rechnung (keine Artikelposition): '.($extra['bezeichnung'] ?? '').' = '.($extra['betrag'] ?? '');
    }
}
if (!empty($json['rechnungsummen']) && is_array($json['rechnungsummen'])) {
    foreach ($json['rechnungsummen'] as $k => $v) {
        $noteParts[] = 'Original-Rechnungssumme '.$k.': '.$v;
    }
}
$object->note_private = implode("\n", $noteParts);
                $id = $object->create($user);
                if ($id <= 0) { $rep->releaseRep($repRef); $error = 'Lieferantenrechnung konnte nicht angelegt werden: '.($object->error ?: $db->lasterror()); }
                else {
                    $desc = repimport_build_description($positions, $repRef);
                    $lineResult = $object->addline($desc, $netTotal, $vatValue, 0, 0, 1, 0, 0, 0, 0, 0, 0, 'HT', 0, 1, 0, array(), null, 0, 0, '', 0, 0, 0);
                    if ($lineResult < 0) { $object->delete($user); $rep->releaseRep($repRef); $error = 'Rechnungsposition konnte nicht angelegt werden: '.($object->error ?: $db->lasterror()); }
                    else {
                        $rep->linkRepToInvoice($repRef, $object->id);

                        // Original-PDF sauber archivieren:
                        // 1) dauerhaft nach fournisseur/facture/YYYY/MM/Originalname.pdf
                        // 2) zusätzlich als Hardlink im von Dolibarr erwarteten Rechnungsordner,
                        //    damit "Verknüpfte Dokumente" die Datei automatisch findet.
                        $pdfSource = repimport_value($json, '_repimport_pdf');
                        if ($pdfSource !== '' && is_file($pdfSource)) {
                            $baseDir = !empty($conf->fournisseur->facture->dir_output)
                                ? rtrim($conf->fournisseur->facture->dir_output, '/')
                                : '';

                            if ($baseDir !== '') {
                                $sourceName = repimport_value($json, 'quelldatei');
                                if ($sourceName === '') $sourceName = basename($pdfSource);

                                $safeName = dol_sanitizeFileName(basename($sourceName));
                                if ($safeName === '') $safeName = 'rechnung.pdf';
                                if (strtolower(pathinfo($safeName, PATHINFO_EXTENSION)) !== 'pdf') {
                                    $safeName .= '.pdf';
                                }

                                // Archiv: YYYY/MM, Jahr und Monat des Rechnungsdatums.
                                $archiveYear = date('Y', (int)$invoiceDate);
                                $archiveMonth = date('m', (int)$invoiceDate);
                                $archiveDir = $baseDir.'/'.$archiveYear.'/'.$archiveMonth;

                                if (dol_mkdir($archiveDir) >= 0) {
                                    $archiveFile = $archiveDir.'/'.$safeName;

                                    // Niemals eine bereits vorhandene Datei überschreiben.
                                    if (is_file($archiveFile)) {
                                        $archiveFile = $archiveDir.'/'.pathinfo($safeName, PATHINFO_FILENAME).'_'.$object->id.'.pdf';
                                    }

                                    if (!@copy($pdfSource, $archiveFile)) {
                                        dol_syslog(
                                            'REPIMPORT: Original-PDF konnte nicht im Archiv abgelegt werden: '
                                            .$pdfSource.' -> '.$archiveFile,
                                            LOG_WARNING
                                        );
                                    } else {
                                        dol_syslog('REPIMPORT: Original-PDF archiviert: '.$archiveFile, LOG_INFO);

                                        // Dolibarr list_of_documents() erwartet bei Lieferantenrechnungen
                                        // den Standardpfad: get_exdir() + Rechnungsreferenz + Dateiname.
                                        $pdfDir = get_exdir($object->id, 2, 0, 0, $object, 'invoice_supplier');
                                        $invoiceRefDir = dol_sanitizeFileName($object->ref).'/';
                                        $compatDir = $baseDir.'/'.ltrim($pdfDir, '/').$invoiceRefDir;

                                        if (dol_mkdir($compatDir) >= 0) {
                                            $compatFile = $compatDir.basename($archiveFile);

                                            // Hardlink: kein zweites Datenexemplar, beide Verzeichnisse
                                            // zeigen auf dieselbe Datei. Fallback auf copy(), falls link()
                                            // auf dem Dateisystem nicht möglich ist.
                                            if (!@link($archiveFile, $compatFile)) {
                                                if (!@copy($archiveFile, $compatFile)) {
                                                    dol_syslog(
                                                        'REPIMPORT: Kompatibilitätsdatei für Dolibarr konnte nicht angelegt werden: '
                                                        .$archiveFile.' -> '.$compatFile,
                                                        LOG_WARNING
                                                    );
                                                } else {
                                                    dol_syslog('REPIMPORT: Dolibarr-Kompatibilitätsdatei kopiert: '.$compatFile, LOG_INFO);
                                                }
                                            } else {
                                                dol_syslog('REPIMPORT: Dolibarr-Kompatibilitäts-Hardlink angelegt: '.$compatFile, LOG_INFO);
                                            }
                                        } else {
                                            dol_syslog('REPIMPORT: Dolibarr-Rechnungsverzeichnis konnte nicht angelegt werden: '.$compatDir, LOG_WARNING);
                                        }
                                    }
                                } else {
                                    dol_syslog('REPIMPORT: Archivverzeichnis konnte nicht angelegt werden: '.$archiveDir, LOG_WARNING);
                                }
                            } else {
                                dol_syslog('REPIMPORT: conf->fournisseur->facture->dir_output ist nicht gesetzt.', LOG_WARNING);
                            }
                        } else {
                            dol_syslog('REPIMPORT: Quelldatei für Original-PDF fehlt: '.$pdfSource, LOG_WARNING);
                        }

                        header('Location: '.DOL_URL_ROOT.'/fourn/facture/card.php?id='.(int)$object->id);
                        exit;
                    }
                }
            }
        }
    }
}

llxHeader('', 'REP Rechnungsimport');
print load_fiche_titre('REP Rechnungsimport');
if ($error) setEventMessages($error, null, 'errors');
if ($success) setEventMessages($success, null, 'mesgs');

print '<h3>Sammelimport</h3>'; $batchDir=$conf->global->REPIMPORT_IMPORT_DIR??''; if($batchDir!==''){print '<div class="ok">Importverzeichnis: <strong>'.dol_escape_htmltag($batchDir).'</strong></div><form method="POST"><input type="hidden" name="token" value="'.newToken().'"/><input type="hidden" name="action" value="batch_start"/><input class="button button-save" type="submit" value="ALLE NEUEN RECHNUNGEN ANALYSIEREN"/></form>';}else{print '<div class="warning">Bitte zuerst in den REP-Import-Einstellungen ein Importverzeichnis festlegen.</div>';} print '<br><a class="button" href="import.php?action=batch_list">WARTESCHLANGE ANZEIGEN</a>';
print ' <form method="post" action="import.php?action=clear_history" style="display:inline;margin-left:8px" onsubmit="return confirm(\"Alle bisherigen REP-Import-Bearbeitungen und Analyseergebnisse löschen? Die PDFs im Importverzeichnis und archivierte Rechnungen bleiben erhalten.\");">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
print '<input type="hidden" name="confirm_clear" value="yes">';
print '<button type="submit" class="button" style="color:#a00">BISHERIGE BEARBEITUNGEN LÖSCHEN</button></form>';
print '<h3>Einzelimport</h3>';print '<form method="POST" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="start">';
print '<table class="border centpercent"><tr><td class="titlefield">Rechnungs-PDF</td><td><input type="file" name="pdf" accept="application/pdf,.pdf" required></td></tr></table>';
print '<div class="center"><input class="button" type="submit" value="VORSCHAU"></div></form>';

if ($json) {
    $lieferant = is_array($json['lieferant'] ?? null) ? $json['lieferant'] : array();
    $rechnung = is_array($json['rechnung'] ?? null) ? $json['rechnung'] : array();
    $positions = is_array($json['positionen'] ?? null) ? $json['positionen'] : array();
    $vatDetected = repimport_value($rechnung, 'mehrwertsteuer_prozent');
    $calcSeconds = isset($json['_repimport_berechnungszeit']) ? (float)$json['_repimport_berechnungszeit'] : null;
    print '<br><div class="ok">PDF wurde analysiert. Die JSON-Ausgabe wurde intern erzeugt und muss nicht von dir importiert werden.</div>';
    if ($calcSeconds !== null) {
        print '<div class="ok" style="margin-top:6px;"><strong>Berechnungszeit:</strong> '.dol_escape_htmltag((string) $calcSeconds).' Sekunden</div>';
    }
    $pdfPreviewUrl = 'import.php?action=pdf&job='.rawurlencode($jobId).'&t='.time();
    $remarks = array();
    if (!empty($json['bemerkungen']) && is_array($json['bemerkungen'])) $remarks = $json['bemerkungen'];
    print '<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(420px,1fr);gap:18px;align-items:start;">';
    print '<div>'; 
    print '<div style="position:sticky;top:10px;z-index:2;margin-bottom:10px;"><a class="button" href="'.dol_escape_htmltag($pdfPreviewUrl).'" target="_blank">PDF IN NEUEM TAB ÖFFNEN</a></div>';
    print '<table class="border centpercent">';
    print '<tr><td>Lieferant</td><td>'.dol_escape_htmltag(repimport_value($lieferant,'name')).'</td></tr>';
    print '<tr><td>Rechnungsnummer</td><td>'.dol_escape_htmltag(repimport_value($rechnung,'rechnungsnummer')).'</td></tr>';
    print '<tr><td>Rechnungsdatum</td><td>'.dol_escape_htmltag(repimport_value($rechnung,'rechnungsdatum')).'</td></tr>';
    print '<tr><td>Bestellnummer</td><td>'.dol_escape_htmltag(repimport_value($rechnung,'bestellnummer')).'</td></tr>';
    print '</table>';
    /*
     * Lieferant für den späteren Erstellungs-Schritt bestimmen.
     * Ein exakter Name wird automatisch übernommen. Wenn es keinen exakten
     * Treffer gibt, zeigt die Vorschau eine Auswahl möglicher Treffer sowie
     * die Möglichkeit, einen neuen Lieferanten ausdrücklich zu bestätigen.
     */
    $repPreview = new RepImport($db);
    $supplierPreview = $repPreview->findSupplierByName(repimport_value($lieferant, 'name'));
    $supplierCandidates = $repPreview->findSupplierCandidates($lieferant, 8);
    $allSuppliers = $repPreview->getAllSuppliers(1000);

    print '<h3>Lieferant</h3>';
    if ($supplierPreview) {
        print '<div class="ok">Lieferant in Dolibarr gefunden: <strong>'.dol_escape_htmltag($supplierPreview->name).'</strong></div>';
    } else {
        print '<div class="warning">Lieferant ist in Dolibarr nicht vorhanden. Bitte vorhandenen Lieferanten auswählen oder neuen Lieferanten ausdrücklich bestätigen.</div>';
    }

    print '<form id="repcreateform" method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="create">';
    print '<input type="hidden" name="jsondata" value="'.dol_escape_htmltag(base64_encode(json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))).'">';
    print '<table class="border centpercent">';

    print '<tr><td class="titlefield">Lieferant</td><td>';
    print '<select class="flat" name="supplier_id" id="rep_supplier_id" style="min-width:420px">';
    print '<option value="0">-- bitte auswählen --</option>';
    $selectedId = $supplierPreview ? (int)$supplierPreview->id : 0;
    $shown = array();
    foreach ($allSuppliers as $cand) {
        $cid = (int)$cand->rowid;
        $shown[$cid] = true;
        $sel = ($cid === $selectedId) ? ' selected' : '';
        $label = (string)$cand->nom;
        if ($cid !== $selectedId) {
            foreach ($supplierCandidates as $pc) {
                if ((int)$pc->rowid === $cid && !empty($pc->match_score)) { $label .= ' – Treffer '.$pc->match_score.'%'; break; }
            }
        }
        print '<option value="'.$cid.'"'.$sel.'>'.dol_escape_htmltag($label).'</option>';
    }
    print '</select>';
    print '<div class="opacitymedium">Alle vorhandenen Lieferanten können jetzt ausgewählt werden. Der erkannte Treffer wird automatisch vorgewählt.</div>';
    print '</td></tr>';

    print '<tr><td>Neuen Lieferanten anlegen</td><td>';
    print '<label><input type="checkbox" name="new_supplier" value="1" id="rep_new_supplier"> Ich bestätige die Neuanlage</label>';
    print '</td></tr>';

    print '<tr><td>Name</td><td><input class="flat minwidth300" type="text" name="supplier_name" value="'.dol_escape_htmltag(repimport_value($lieferant,'name')).'"></td></tr>';
    print '<tr><td>Adresse</td><td><input class="flat minwidth300" type="text" name="supplier_address" value="'.dol_escape_htmltag(repimport_value($lieferant,'adresse')).'"></td></tr>';
    print '<tr><td>PLZ</td><td><input class="flat" type="text" name="supplier_zip" value="'.dol_escape_htmltag(repimport_value($lieferant,'plz')).'"></td></tr>';
    print '<tr><td>Ort</td><td><input class="flat minwidth300" type="text" name="supplier_town" value="'.dol_escape_htmltag(repimport_value($lieferant,'ort')).'"></td></tr>';
    print '<tr><td>Telefon</td><td><input class="flat" type="text" name="supplier_phone" value="'.dol_escape_htmltag(repimport_value($lieferant,'telefon')).'"></td></tr>';
    print '<tr><td>E-Mail</td><td><input class="flat minwidth300" type="text" name="supplier_email" value="'.dol_escape_htmltag(repimport_value($lieferant,'email')).'"></td></tr>';
    print '<tr><td>Webseite</td><td><input class="flat minwidth300" type="text" name="supplier_url" value="'.dol_escape_htmltag(repimport_value($lieferant,'webseite')).'"></td></tr>';
    print '<tr><td>USt-ID</td><td><input class="flat" type="text" name="supplier_tva_intra" value="'.dol_escape_htmltag(repimport_value($lieferant,'ust_id')).'"></td></tr>';
    print '</table>';

    print '<h3>Bemerkungen</h3>';
    if ($remarks) print '<div class="warning" style="margin-bottom:6px;">'.dol_escape_htmltag(implode("\n", $remarks)).'</div>';
    print '<textarea class="flat centpercent" name="bemerkungen" rows="5" placeholder="Weitere Hinweise zur Rechnung / zum Fremdlieferanten">'.dol_escape_htmltag(implode("\n", $remarks)).'</textarea>';

    print '<h3>Mehrwertsteuer</h3>';
    print '<input class="flat" type="text" name="vat_rate" value="'.dol_escape_htmltag($vatDetected).'" placeholder="z. B. 19,00" required>';
    $invoiceTotalsPreview = is_array($json['rechnungsummen'] ?? null) ? $json['rechnungsummen'] : array();
    if (!empty($invoiceTotalsPreview)) {
        print '<h3>Ausdrücklich ausgewiesene Rechnungssummen</h3>';
        print '<table class="liste">';
        if (isset($invoiceTotalsPreview['steuerbemessungsgrundlage'])) {
            print '<tr><td>Steuerbemessungsgrundlage</td><td>'.dol_escape_htmltag($invoiceTotalsPreview['steuerbemessungsgrundlage']).'</td></tr>';
        }
        if (isset($invoiceTotalsPreview['bruttobetrag'])) {
            print '<tr><td>Rechnungsbetrag / Endsumme</td><td>'.dol_escape_htmltag($invoiceTotalsPreview['bruttobetrag']).'</td></tr>';
        }
        print '</table>';
    }

    $extraCostsPreview = is_array($json['zusatzkosten'] ?? null) ? $json['zusatzkosten'] : array();
    if ($extraCostsPreview) {
        print '<h3>Zusatzkosten (keine Artikelpositionen)</h3>';
        print '<table class="liste"><tr><th>Bezeichnung</th><th>Betrag</th></tr>';
        foreach ($extraCostsPreview as $extra) {
            print '<tr><td>'.dol_escape_htmltag($extra['bezeichnung'] ?? '').'</td><td>'.dol_escape_htmltag($extra['betrag'] ?? '').'</td></tr>';
        }
        print '</table>';
        print '<div class="warning">Diese Zusatzkosten werden nicht als Artikelposition übernommen.</div>';
    }

    print '<h3>Positionen</h3><table class="liste"><tr><th>Pos.</th><th>Menge</th><th>Artikel-Nr.</th><th>Bezeichnung</th><th>Einzel</th><th>Gesamt</th></tr>';
    foreach ($positions as $i=>$p) {
        $articles = '';
        if (!empty($p['artikelnummern']) && is_array($p['artikelnummern'])) $articles = implode(', ', $p['artikelnummern']);
        elseif (!empty($p['artikelnummer'])) $articles = (string)$p['artikelnummer'];
        print '<tr><td>'.dol_escape_htmltag($p['positionsnummer'] ?? ($i+1)).'</td><td>'.dol_escape_htmltag($p['menge']??'').'</td><td>'.dol_escape_htmltag($articles).'</td><td>'.dol_escape_htmltag($p['artikelbezeichnung']??'').'</td><td>'.dol_escape_htmltag($p['einzelpreis']??'').'</td><td>'.dol_escape_htmltag($p['gesamtpreis']??'').'</td></tr>';
    }
    print '</table>';
    print '<div class="center"><button class="button button-save" type="submit">Rechnung als Entwurf anlegen</button></div></form>';
    print '</div><div style="position:sticky;top:10px;height:calc(100vh - 115px);min-height:650px"><div style="font-weight:bold;margin-bottom:6px">Original-PDF</div><iframe src="'.dol_escape_htmltag($pdfPreviewUrl).'" style="width:100%;height:calc(100vh - 150px);min-height:650px;border:1px solid #bbb;background:#fff" title="Original-PDF"></iframe></div></div>';
}
llxFooter();
$db->close();
