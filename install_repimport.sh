#!/bin/bash
set -euo pipefail
# REP Rechnungsimport 0.6.3 - Komplettinstaller
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOLI_ROOT="${1:-/usr/share/dolibarr}"
DOLI_HTDOCS="${DOLI_ROOT%/}/htdocs"
MODULE_SRC="${SCRIPT_DIR}/repimport"
MODULE_DST="${DOLI_HTDOCS}/custom/repimport"
PARSER_DST="${MODULE_DST}/rechnungs_ki_split_v20_7.py"
if [[ $EUID -ne 0 ]]; then echo "Bitte mit sudo/root ausführen."; exit 1; fi
if [[ ! -f "${DOLI_HTDOCS}/main.inc.php" ]]; then echo "Dolibarr wurde unter ${DOLI_HTDOCS} nicht gefunden."; echo "Falls Dolibarr anders installiert ist: sudo $0 /pfad/zu/dolibarr"; exit 1; fi
echo "== REP Rechnungsimport 0.6.3 =="
echo "Dolibarr: ${DOLI_ROOT}"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y python3 python3-requests poppler-utils
install -d -m 0755 "${DOLI_HTDOCS}/custom"
rm -rf "${MODULE_DST}"
cp -a "${MODULE_SRC}" "${MODULE_DST}"
chown -R root:root "${MODULE_DST}"
find "${MODULE_DST}" -type d -exec chmod 0755 {} \;
find "${MODULE_DST}" -type f -exec chmod 0644 {} \;
chmod 0755 "${PARSER_DST}"
DOCROOT=""
if command -v php >/dev/null 2>&1; then
  DOCROOT="$(sudo -u www-data php -r 'require "'"'${DOLI_HTDOCS}'"'/main.inc.php"; echo isset($conf->fournisseur->facture->dir_output) ? $conf->fournisseur->facture->dir_output : "";' 2>/dev/null || true)"
fi
if [[ -n "${DOCROOT}" ]]; then mkdir -p "${DOCROOT}/repimport"; chown -R www-data:www-data "${DOCROOT}/repimport"; chmod 0755 "${DOCROOT}/repimport"; echo "REP-Speicher: ${DOCROOT}/repimport"; else echo "Hinweis: Dokumentpfad konnte nicht automatisch ermittelt werden."; fi
echo
echo "Installation abgeschlossen."
echo "Parser: ${PARSER_DST}"
echo "In Dolibarr REP Import -> Einstellungen prüfen:"
echo "  - Parserpfad"
echo "  - LM Studio URL"
echo "  - LM Studio Modell"
echo "  - LM Studio Timeout"
echo "  - Sammellieferanten"
echo
echo "Das Modul muss ggf. in Dolibarr einmal deaktiviert/aktiviert werden, falls der neue Versionsstand nicht sofort angezeigt wird."
