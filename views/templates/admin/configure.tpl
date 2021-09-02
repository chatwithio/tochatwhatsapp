{*
* 2007-2017 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2021 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}
<div class="panel">
	<h3><i class="icon icon-credit-card"></i> TOCHAT.BE</h3>
	<form class="form-horizontal" enctype="multipart/form-data" action="" method="POST">
	  <div class="form-group">
		<label for="input1" class="col-sm-2 control-label">Tochat.Be ID: </label>
		<div class="col-sm-8">
		  <input type="text" class="form-control" name="telefon" id="input1" value="{$whatasppno|escape:'html':'UTF-8'}">
			<br />
		</div>
		<div class="alert alert-warning col-sm-10 pull-right">
			<b>El id de Widget proporcionado por Tochat.Be</b>
		</div>
	  </div>
	 
	  <div class="form-group">
		<div class="col-sm-offset-2 col-sm-8">
		  <button type="submit" name="telekle" class="btn btn-default ">{l s='Save or Update' mod='whatsapp'}</button>
		</div>
	  </div>
	</form>
</div>
