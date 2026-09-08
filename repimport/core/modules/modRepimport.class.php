<?php
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modRepimport extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs;
        $this->db = $db;
        $this->numero = 105001;
        $this->rights_class = 'repimport';
        $this->family = 'financial';
        $this->module_position = 500;
        $this->name = 'Repimport';
        $this->description = 'REP Rechnungsimport – PDF direkt analysieren und als Lieferantenrechnung importieren';
        $this->version = '0.6.2';
        $this->const_name = 'MAIN_MODULE_REPIMPORT';
        $this->picto = 'generic';
        $this->config_page_url = array('setup.php@repimport');
        $this->depends = array('modFournisseur');

        $this->rights = array();
        $this->rights[0][0] = 105001;
        $this->rights[0][1] = 'Rechnungsimport anzeigen';
        $this->rights[0][4] = 'read';
        $this->rights[0][5] = 'read';
        $this->rights[1][0] = 105002;
        $this->rights[1][1] = 'Rechnungsimport erstellen';
        $this->rights[1][4] = 'write';
        $this->rights[1][5] = 'write';

        $this->menu = array();
        $this->menu[] = array(
            'fk_menu' => '',
            'type' => 'top',
            'titre' => 'REP Import',
            'mainmenu' => 'repimport',
            'leftmenu' => '',
            'url' => '/repimport/import.php',
            'langs' => 'repimport@repimport',
            'position' => 100,
            'enabled' => '$conf->repimport->enabled',
            'perms' => '$user->rights->repimport->read',
            'target' => '',
            'user' => 2
        );
    }

    public function init($options = '')
    {
        $sql = array();
        $sql[] = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."repimport_rep (
            rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
            entity INTEGER NOT NULL DEFAULT 1,
            ref VARCHAR(32) NOT NULL,
            invoice_id INTEGER NULL,
            created_at DATETIME NOT NULL,
            fk_user INTEGER NULL,
            UNIQUE KEY uk_repimport_ref (entity, ref)
        ) ENGINE=InnoDB";
        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        return $this->_remove(
            array("DROP TABLE IF EXISTS ".MAIN_DB_PREFIX."repimport_rep"),
            $options
        );
    }
}
