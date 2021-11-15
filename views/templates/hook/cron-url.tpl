{**
 * @author    360dialog – Official WhatsApp Business Solution Provider. <info@360dialog.com>
 * @copyright 2021 360dialog GmbH.
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}

<div class="panel" id="fieldset_1_1">
  <div class="panel-heading">
    <i class="icon-cogs"></i>Documentation
  </div>
  <div class="form-wrapper">
        <p>Tutorials, Videos, Demos.. <a href="https://tochat.be/magento-order-notifications-whatsapp/" target="_blank">tochat.be</a></p>

        <p>Order Automation Template Placeholders</p>
        <ul>
            <li>{{'{{1}}'}} => ORDERID</li>
            <li>{{'{{2}}'}} => CustomerName</li>
            <li>{{'{{3}}'}} => ProductNames</li>
            <li>{{'{{4}}'}} => Total</li>
        </ul>

        <p>Abandoned cart Template Placeholders</p>
        <ul>
            <li>{{'{{1}}'}} => CustomerName</li>
            <li>{{'{{2}}'}} => ProductNames</li>
            <li>{{'{{3}}'}} => Total</li>
            <li>{{'{{4}}'}} => StoreName</li>
        </ul>
        
        <p>Please set the following url in run per mintunes on cpanel. it is required for automation of order emssage and abandoned cart messages.</p>
        <span>* * * * * curl -s "{$cron_url}" > /dev/null</span>
  </div>
</div>