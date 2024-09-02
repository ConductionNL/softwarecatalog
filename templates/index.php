<?php

use OCP\Util;

$appId = OCA\SoftwarCatalog\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-main');
Util::addStyle($appId, 'main');
?>
<div id="softwarecatalog"></div>


