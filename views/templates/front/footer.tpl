{assign var='controllerName' value=$smarty.get.controller}
<div class="whatsappDiv">
<script defer data-key="{$whatasppno|escape:'html':'UTF-8'}" src="https://widget.tochat.be/bundle.js" data-price="{$product->price}" data-product="{$page.meta.title}" data-url="{$urls.current_url}"></script>
</div>