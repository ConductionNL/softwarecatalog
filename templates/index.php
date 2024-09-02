<?php

use OCP\Util;

$appId = OCA\SoftwareCatalog\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-main');
Util::addStyle($appId, 'main');
?>
<div id="softwarecatalog"></div>


