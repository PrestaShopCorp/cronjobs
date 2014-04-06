<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');

$token = Tools::getValue('token');

if ($token != Configuration::get('CRONJOBS_EXECUTION_TOKEN'))
	exit(0);

echo 'Access granted';

?>
