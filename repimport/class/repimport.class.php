<?php
class RepImport
{
    public $db;
    public $error = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getNextRep($date = null)
    {
        global $conf, $user;
        if (!$date) $date = dol_now();
        $year = (int) dol_print_date($date, '%Y');
        $month = (int) dol_print_date($date, '%m');

        $sql = "SELECT MAX(rowid) AS maxrowid FROM ".MAIN_DB_PREFIX."repimport_rep";
        $sql .= " WHERE entity = ".((int) $conf->entity);
        $resql = $this->db->query($sql);
        if (!$resql) { $this->error = $this->db->lasterror(); return -1; }
        $obj = $this->db->fetch_object($resql);
        $sequence = ((int) ($obj->maxrowid ?? 0)) + 1;
        $ref = sprintf('REP-%04d%02d-%04d', $year, $month, $sequence);

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."repimport_rep";
        $sql .= " (entity, ref, created_at, fk_user) VALUES (";
        $sql .= ((int) $conf->entity).",";
        $sql .= "'".$this->db->escape($ref)."',";
        $sql .= "'".$this->db->idate(dol_now())."',";
        $sql .= ((int) $user->id).")";
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return -1; }
        return $ref;
    }

    public function releaseRep($ref)
    {
        global $conf;
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."repimport_rep";
        $sql .= " WHERE entity = ".((int) $conf->entity);
        $sql .= " AND ref = '".$this->db->escape($ref)."'";
        return $this->db->query($sql) ? 1 : 0;
    }

    public function linkRepToInvoice($ref, $invoiceId)
    {
        global $conf;
        $sql = "UPDATE ".MAIN_DB_PREFIX."repimport_rep SET invoice_id = ".((int) $invoiceId);
        $sql .= " WHERE entity = ".((int) $conf->entity);
        $sql .= " AND ref = '".$this->db->escape($ref)."'";
        return $this->db->query($sql) ? 1 : 0;
    }

    public function findSupplierByName($name)
    {
        // Fournisseur::fetch() treats the second argument as a reference,
        // not as the company name. Therefore use a direct exact name lookup.
        $name = trim((string)$name);
        if ($name === '') return null;

        global $conf;
        $entity = function_exists('getEntity') ? getEntity('societe') : ((int)$conf->entity);
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
        $sql .= " WHERE fournisseur = 1 AND entity IN (".$entity.")";
        $sql .= " AND LOWER(TRIM(nom)) = LOWER('".$this->db->escape($name)."')";
        $sql .= " ORDER BY rowid ASC LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) return null;

        $supplier = new Fournisseur($this->db);
        $result = $supplier->fetch((int)$obj->rowid);
        return ($result > 0 && !empty($supplier->fournisseur)) ? $supplier : null;
    }

    public function findSupplier($name)
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
        $name = trim((string)$name);
        if ($name === '') { $this->error = 'Kein Lieferantenname vorhanden.'; return null; }

        $supplier = new Fournisseur($this->db);
        $result = $supplier->fetch(0, $name, '', '', '', '', '', '', '', '', '', 0, 1);
        if ($result > 0) return $supplier;
        if ($result == -2) { $this->error = 'Mehrere Lieferanten mit diesem Namen gefunden.'; return null; }
        if ($result == 0) { $this->error = 'Lieferant nicht in Dolibarr gefunden.'; return null; }
        $this->error = $supplier->error ?: $this->db->lasterror();
        return null;
    }

    /**
     * Find plausible supplier matches when the invoice name is not an exact Dolibarr name.
     * No supplier is selected automatically here; the user must confirm the match.
     */
    public function findSupplierCandidates($data, $limit = 10)
    {
        global $conf;
        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['telefon'] ?? ($data['phone'] ?? '')));
        $town = trim((string)($data['ort'] ?? ($data['town'] ?? '')));
        $zip = trim((string)($data['plz'] ?? ($data['zip'] ?? '')));

        if ($name === '' && $email === '' && $phone === '' && $town === '' && $zip === '') return array();

        $conditions = array();
        $terms = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_values(array_filter($terms, function($v) { return mb_strlen($v, 'UTF-8') >= 3; }));
        foreach ($terms as $term) {
            $e = $this->db->escape($term);
            $conditions[] = "s.nom LIKE '%".$e."%'";
            $conditions[] = "s.name_alias LIKE '%".$e."%'";
        }
        if ($email !== '') $conditions[] = "s.email LIKE '%".$this->db->escape($email)."%'";
        if ($phone !== '') {
            $digits = preg_replace('/\D+/', '', $phone);
            if ($digits !== '') $conditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(s.phone,' ',''),'-',''),'/',''),'+','') LIKE '%".$this->db->escape($digits)."%'";
        }
        if ($town !== '') $conditions[] = "s.town LIKE '%".$this->db->escape($town)."%'";
        if ($zip !== '') $conditions[] = "s.zip LIKE '%".$this->db->escape($zip)."%'";
        if (!$conditions) return array();

        $entity = function_exists('getEntity') ? getEntity('societe') : ((int)$conf->entity);
        $sql = "SELECT s.rowid, s.nom, s.name_alias, s.email, s.phone, s.address, s.zip, s.town, s.code_fournisseur, s.tva_intra";
        $sql .= " FROM ".MAIN_DB_PREFIX."societe s";
        $sql .= " WHERE s.fournisseur = 1 AND s.entity IN (".$entity.")";
        $sql .= " AND (".implode(' OR ', $conditions).")";
        $sql .= " ORDER BY s.nom ASC";
        $sql .= " LIMIT ".((int)$limit * 3);

        $resql = $this->db->query($sql);
        if (!$resql) { $this->error = $this->db->lasterror(); return array(); }

        $rows = array();
        while ($o = $this->db->fetch_object($resql)) {
            $score = 0;
            $reasons = array();
            $normName = $this->normalizeSupplierText($name);
            $candName = $this->normalizeSupplierText($o->nom);
            $candAlias = $this->normalizeSupplierText($o->name_alias);

            if ($normName !== '' && $candName === $normName) { $score += 100; $reasons[] = 'Name identisch'; }
            elseif ($normName !== '' && ($candName !== '' && (strpos($candName, $normName) !== false || strpos($normName, $candName) !== false))) { $score += 65; $reasons[] = 'Name ähnlich'; }
            elseif ($normName !== '' && $candAlias !== '' && (strpos($candAlias, $normName) !== false || strpos($normName, $candAlias) !== false)) { $score += 60; $reasons[] = 'Alias ähnlich'; }

            foreach ($terms as $term) {
                $t = $this->normalizeSupplierText($term);
                if ($t !== '' && (strpos($candName, $t) !== false || strpos($candAlias, $t) !== false)) { $score += 12; }
            }
            if ($email !== '' && strcasecmp($email, (string)$o->email) === 0) { $score += 80; $reasons[] = 'E-Mail identisch'; }
            if ($phone !== '' && $this->digits((string)$o->phone) !== '' && $this->digits($phone) === $this->digits((string)$o->phone)) { $score += 70; $reasons[] = 'Telefon identisch'; }
            if ($zip !== '' && $zip === (string)$o->zip) { $score += 20; $reasons[] = 'PLZ identisch'; }
            if ($town !== '' && $this->normalizeSupplierText($town) !== '' && strpos($this->normalizeSupplierText((string)$o->town), $this->normalizeSupplierText($town)) !== false) { $score += 20; $reasons[] = 'Ort passend'; }

            $o->match_score = $score;
            $o->match_reason = implode(', ', $reasons);
            $rows[] = $o;
        }

        usort($rows, function($a, $b) { return $b->match_score <=> $a->match_score; });
        return array_slice($rows, 0, (int)$limit);
    }

    private function normalizeSupplierText($value)
    {
        $s = mb_strtolower(trim((string)$value), 'UTF-8');
        $s = str_replace(array('ä','ö','ü','ß'), array('ae','oe','ue','ss'), $s);
        $s = preg_replace('/[^a-z0-9]+/u', '', $s);
        return $s;
    }

    private function digits($value)
    {
        return preg_replace('/\D+/', '', (string)$value);
    }

    public function getAllSuppliers($limit = 500)
    {
        global $conf;
        $entity = function_exists('getEntity') ? getEntity('societe') : ((int)$conf->entity);
        $sql = "SELECT rowid, nom, name_alias FROM ".MAIN_DB_PREFIX."societe";
        $sql .= " WHERE fournisseur = 1 AND entity IN (".$entity.")";
        $sql .= " ORDER BY nom ASC LIMIT ".((int)$limit);
        $resql = $this->db->query($sql);
        if (!$resql) { $this->error = $this->db->lasterror(); return array(); }
        $rows = array();
        while ($o = $this->db->fetch_object($resql)) $rows[] = $o;
        return $rows;
    }

    public function getSupplierById($id)
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
        $id = (int)$id;
        if ($id <= 0) return null;
        $supplier = new Fournisseur($this->db);
        $result = $supplier->fetch($id);
        return ($result > 0 && !empty($supplier->fournisseur)) ? $supplier : null;
    }

    public function parseDate($value)
    {
        $value = trim((string) $value);
        if ($value === '') return 0;

        // Normale numerische Datumsformate.
        foreach (array('d.m.Y', 'Y-m-d', 'd-m-Y', 'd/m/Y') as $format) {
            $dt = DateTime::createFromFormat('!'.$format, $value);
            if ($dt && $dt->format($format) === $value) return $dt->getTimestamp();
        }

        // Gemaschte deutsche Schreibweise, wie sie Amazon-PDFs liefern können:
        // "17 März 2026", "17. März 2026", "17 Maerz 2026".
        $months = array(
            'januar'=>1, 'jan'=>1, 'februar'=>2, 'feb'=>2, 'märz'=>3, 'maerz'=>3, 'mrz'=>3, 'mär'=>3,
            'april'=>4, 'apr'=>4, 'mai'=>5, 'juni'=>6, 'jun'=>6, 'juli'=>7, 'jul'=>7,
            'august'=>8, 'aug'=>8, 'september'=>9, 'sep'=>9, 'sept'=>9,
            'oktober'=>10, 'okt'=>10, 'november'=>11, 'nov'=>11, 'dezember'=>12, 'dez'=>12
        );
        $norm = mb_strtolower($value, 'UTF-8');
        $norm = preg_replace('/\s+/u', ' ', $norm);
        if (preg_match('/^([0-9]{1,2})\.?\s+([[:alpha:]äöüÄÖÜ]+)\s+([0-9]{4})$/u', $norm, $m)) {
            $month = $months[$m[2]] ?? 0;
            if ($month) {
                $dt = DateTime::createFromFormat('!Y-n-j', $m[3].'-'.$month.'-'.$m[1]);
                if ($dt && $dt->format('Y') === $m[3] && (int)$dt->format('n') === $month && (int)$dt->format('j') === (int)$m[1]) {
                    return $dt->getTimestamp();
                }
            }
        }
        return 0;
    }

    public function numberValue($value)
    {
        if (is_int($value) || is_float($value)) return (float) $value;
        $s = trim((string) $value);
        if ($s === '') return null;
        $s = str_replace(array('€', 'EUR', ' '), '', $s);
        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
            else { $s = str_replace(',', '', $s); }
        } elseif (strpos($s, ',') !== false) { $s = str_replace(',', '.', $s); }
        return is_numeric($s) ? (float) $s : null;
    }

    public function sumPositionTotals($positions)
    {
        $sum = 0.0;
        foreach ((array) $positions as $p) {
            $v = $this->numberValue($p['gesamtpreis'] ?? null);
            if ($v !== null) $sum += $v;
        }
        return round($sum, 2);
    }

    public function buildInvoiceDescription($repRef, $positions)
    {
        $lines = array();
        $lines[] = '<strong>'.dol_escape_htmltag($repRef).'</strong>';
        $lines[] = '';
        $lines[] = 'Pos. | Menge | Artikel-Nr. | Bezeichnung | Einzel | Gesamt';
        foreach ((array) $positions as $p) {
            $articles = !empty($p['artikelnummern']) && is_array($p['artikelnummern']) ? implode(', ', $p['artikelnummern']) : '';
            $lines[] = implode(' | ', array($p['positionsnummer'] ?? '', $p['menge'] ?? '', $articles, $p['artikelbezeichnung'] ?? '', $p['einzelpreis'] ?? '', $p['gesamtpreis'] ?? ''));
        }
        return nl2br(dol_escape_htmltag(implode("\n", $lines)));
    }
}
