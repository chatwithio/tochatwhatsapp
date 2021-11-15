<?php
/**
 * @author    360dialog – Official WhatsApp Business Solution Provider. <info@360dialog.com>
 * @copyright 2021 360dialog GmbH.
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

ini_set('memory_limit', '256M');
$_SERVER['REQUEST_METHOD'] = "POST";

include_once dirname(__FILE__) . '/../../config/config.inc.php';
include_once dirname(__FILE__) . '/../../init.php';
include dirname(__FILE__) . '/tochatwhatsapp.php';

if (Tools::substr(Tools::encrypt('tochatwhatsapp/cron'), 0, 10) != Tools::getValue('token')
    || !Module::isInstalled('tochatwhatsapp')) {
    die('Bad token');
}

$tochat_whatsapp = new Tochatwhatsapp();
$tochat_whatsapp->abandonedCart();
$tochat_whatsapp->automateMessage();
echo "DONE";
