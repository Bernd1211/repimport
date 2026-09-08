<?php
function repimport_build_description($positions, $repRef = '')
{
    $lines = array();
    if ($repRef !== '') $lines[] = $repRef;
    $lines[] = 'Pos. | Menge | Artikel-Nr. | Bezeichnung | Einzel | Gesamt';

    foreach ((array) $positions as $p) {
        $articles = !empty($p['artikelnummern']) && is_array($p['artikelnummern'])
            ? implode(', ', $p['artikelnummern']) : '';

        $lines[] = implode(' | ', array(
            $p['positionsnummer'] ?? '',
            $p['menge'] ?? '',
            $articles,
            $p['artikelbezeichnung'] ?? '',
            $p['einzelpreis'] ?? '',
            $p['gesamtpreis'] ?? ''
        ));
    }
    return implode("\n", $lines);
}
