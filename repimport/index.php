<?php
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Main include failed");
header("Location: ".dol_buildpath('/custom/repimport/import.php', 1));
exit;
