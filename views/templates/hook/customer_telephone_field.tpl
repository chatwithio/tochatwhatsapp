{**
 * @author    360dialog – Official WhatsApp Business Solution Provider. <info@360dialog.com>
 * @copyright 2021 360dialog GmbH.
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}

<div class="form-group row" id="order_message_telephone">
  <label for="customer_telephone" class="form-control-label label-on-top col-12">
    <span class="text-danger">*</span>
    Telephone number
  </label>
  <div class="col-12">
    <div class="input-group js-text-with-length-counter">
      <input id="customer_telephone" name="customer-telephone" required="required" class="form-control" value="{$customer_telephone|escape:'htmlall':'UTF-8'}">
    </div>
  </div>
</div>
<button type="button" id="sendToWhatsapp" class="btn btn-primary" onclick="whatsappSubmit(event)">WhatsApp</button>
<script>
(function() {
  var tel = document.querySelector('#order_message_telephone');
  var btn = document.querySelector('#sendToWhatsapp');
  var form = document.querySelectorAll('form[name="order_message"]')[0];
  form.insertBefore(tel, form.children[form.children.length - 2]);
  form.children[form.children.length - 1].append(btn);
})();
function whatsappSubmit(event){
        event.preventDefault();
        let telephone = document.getElementById('customer_telephone').value;
        let message = document.getElementById('order_message_message').value;
        var form = document.querySelectorAll('form[name="order_message"]')[0];
        if(form.checkValidity()){
            apiUrl = "https://api.whatsapp.com/send?phone=$phone&text=$text";
            window.open(apiUrl.replace('$phone',telephone).replace('$text',message), '_blank').focus();
        }
    }
</script>