<?php
/**
 * @author    360dialog – Official WhatsApp Business Solution Provider. <info@360dialog.com>
 * @copyright 2021 360dialog GmbH.
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (!defined('_CAN_LOAD_FILES_')) {
    exit;
}

use PrestaShop\Module\TochatWhatsapp\Api;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

class Tochatwhatsapp extends Module
{
    const ORDER_STATE_NEW = 0;
    const ORDER_STATE_PROCESSING = 3;
    const ORDER_STATE_CANCELED = 6;
    const ORDER_STATE_COMPLETE = 5;

    const MESSAGE_STATUS_SENT = 1;
    const MESSAGE_STATUS_PENDING = 2;
    const MESSAGE_STATUS_FAILED = 3;

    const MESSAGE_TYPE_CART = 2;

    private $config = [
        'TOCHATWHATSAPP_GENERAL_STATUS',
        'TOCHATWHATSAPP_AUTOMATION_STATUS',
        'TOCHATWHATSAPP_AUTOMATION_APIKEY',
        'TOCHATWHATSAPP_AUTOMATION_ENDPOINT',
        'TOCHATWHATSAPP_AUTOMATION_COUNTRY_CODE',
        'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_NEW',
        'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_PROCESSING',
        'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_CANCELED',
        'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_COMPLETE',
        'TOCHATWHATSAPP_ABANDONED_STATUS',
        'TOCHATWHATSAPP_ABANDONED_INTERVAL',
        'TOCHATWHATSAPP_ABANDONED_TEMPLATE',
        'TOCHATWHATSAPP_WIDGET_STATUS',
        'TOCHATWHATSAPP_WIDGET_SNIPPET',
    ];

    protected $html = '';

    protected $tochatTemps = null;

    protected $postErrors = array();

    protected $endpoint = 'https://waba.360dialog.io/v1/';

    public function __construct()
    {
        $this->name = 'tochatwhatsapp';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Tochat';
        $this->need_instance = 0;
        $this->need_instance = 0;

        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => _PS_VERSION_);

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Tochat Whatsapp');
        $this->description = $this->l('Whatsapp Order Notification');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->module_key = 'a68d4be9a9baf7cef997a6f013bb9886';
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $this->registerHook('displayBeforeBodyClosingTag');
        $this->registerHook('actionOrderStatusPostUpdate');
        $this->registerHook('actionObjectOrderAddAfter');
        $this->registerHook('displayAdminOrderSideBottom');
        // custom hook to display the customer phone field
        //$this->registerHook('displayAdminOrderMessagesForm');
        $this->registerHook('additionalCustomerFormFields');
        $this->registerHook('actionObjectCustomerUpdateAfter');
        $this->registerHook('actionObjectCustomerAddAfter');

        //Set Default Config Values
        Configuration::updateValue('TOCHATWHATSAPP_AUTOMATION_ENDPOINT', $this->endpoint);

        // Alter customer table
        try {
            Db::getInstance()
            ->execute("ALTER TABLE `" . _DB_PREFIX_ . "customer` ADD `telephone` VARCHAR(32) NULL DEFAULT NULL;");
        } catch (\Exception $e) {
        }

        // Install Backoffice tab
        $this->installBackofficeTab();

        return Db::getInstance()->execute('
        CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'tochat_whatsapp_message` (
              `id` int NOT NULL AUTO_INCREMENT COMMENT \'ID\',
              `order_id` int unsigned DEFAULT NULL COMMENT \'Sales Order Id\',
              `message` mediumtext COMMENT \'Message\',
              `log` mediumtext COMMENT \'Log Message\',
              `type` smallint NOT NULL DEFAULT \'1\' COMMENT \'Type\',
              `extradata` mediumtext CHARACTER SET utf8 COLLATE utf8_general_ci COMMENT \'Extra Data\',
              `status` smallint NOT NULL DEFAULT \'1\' COMMENT \'Status\',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'Created At\',
              `sent_on` timestamp NULL DEFAULT NULL COMMENT \'Sent On\',
              PRIMARY KEY (`id`),
              KEY `TOCHAT_WHATSAPP_MESSAGE_ORDER_ID_SALES_ORDER_ENTITY_ID` (`order_id`),
              FULLTEXT KEY `TOCHAT_WHATSAPP_MESSAGE_MESSAGE_EXTRADATA_LOG` (`message`,`extradata`,`log`),
              CONSTRAINT `TOCHAT_WHATSAPP_MESSAGE_ORDER_ID_PS_ORDERS_ID_ORDER` 
              FOREIGN KEY (`order_id`) REFERENCES `' . _DB_PREFIX_ . 'orders` (`id_order`) ON DELETE SET NULL
        ) ENGINE=' . _MYSQL_ENGINE_ . ' default CHARSET=utf8');
    }

    public function uninstall()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'tochat_whatsapp_message');
        //Delete Configuration
        foreach ($this->config as $config) {
            Configuration::deleteByName($config);
        }
        return parent::uninstall();
    }

    public function hookDisplayBeforeBodyClosingTag(array $params)
    {
        $status = (bool) Configuration::get('TOCHATWHATSAPP_WIDGET_STATUS', 0);
        $snippet = Configuration::get('TOCHATWHATSAPP_WIDGET_SNIPPET', null);
        if ($status && !empty($snippet)) {
            return sprintf('<script defer src="%s"></script>', $snippet);
        }
    }
    //PROCESS NEW ORDER TEMPLATE
    public function hookActionObjectOrderAddAfter(array $params)
    {
        $automationStatus = Configuration::get('TOCHATWHATSAPP_AUTOMATION_STATUS', null);
        if (!$automationStatus) {
            return true;
        }

        $order = $params['object'];
        $address = new Address((int) $order->id_address_invoice);
        Db::getInstance()->insert('tochat_whatsapp_message', array(
            'status' => self::MESSAGE_STATUS_PENDING,
            'order_id' => (int) $order->id,
            'extradata' => json_encode([
                'reference' => $order->reference,
                'mobile' => $address->phone ?? $address->phone_mobile,
                'state' => 'new',
            ]),
        ));
        return true;
    }
    /*Log the Order status Change*/
    public function hookActionOrderStatusPostUpdate(array $params)
    {
        $automationStatus = Configuration::get('TOCHATWHATSAPP_AUTOMATION_STATUS', null);
        if (!$automationStatus) {
            return true;
        }

        if (in_array($params['newOrderStatus']->id, [
            self::ORDER_STATE_PROCESSING,
            self::ORDER_STATE_CANCELED,
            self::ORDER_STATE_COMPLETE,
        ])) {
            $order = new Order((int) $params['id_order']);
            $address = new Address((int) $order->id_address_invoice);
            Db::getInstance()->insert('tochat_whatsapp_message', array(
                'order_id' => (int) $order->id,
                'status' => self::MESSAGE_STATUS_PENDING,
                'extradata' => json_encode([
                    'reference' => $order->reference,
                    'mobile' => $address->phone ?? $address->phone_mobile,
                ]),
            ));
        }
        return true;
    }

    private function postValidation()
    {
        if (Tools::isSubmit('tochatwhatsapp_automation') && Tools::getValue('TOCHATWHATSAPP_AUTOMATION_STATUS') == 1) {
            $apikey = Tools::getValue('TOCHATWHATSAPP_AUTOMATION_APIKEY');
            $endpoint = Tools::getValue('TOCHATWHATSAPP_AUTOMATION_ENDPOINT');
            $code = Tools::getValue('TOCHATWHATSAPP_AUTOMATION_COUNTRY_CODE');

            if (!$apikey) {
                $this->postErrors[] = $this->trans('Apikey is Required.', array(), 'Modules.TochatWhatsapp.Admin');
            }
            if (!$endpoint) {
                $this->postErrors[] = $this->trans('Endpoint is Required.', array(), 'Modules.TochatWhatsapp.Admin');
            }
            if (!$code) {
                $this->postErrors[] = $this->trans('Country Code is Required.', array(), 'Modules.TochatWhatsapp.Admin');
            }
            
            if ($apikey && $endpoint) {
                $obj = $this->getApiObject($apikey, $endpoint);
                if (gettype($obj) == 'string') {
                    $this->postErrors[] = $this->trans($obj, array(), 'Modules.TochatWhatsapp.Admin');
                }
            }
        }

        if (Tools::isSubmit('tochatwhatsapp_abandoned')
            && Tools::getValue('TOCHATWHATSAPP_ABANDONED_STATUS') == 1) {
            if (!Tools::getValue('TOCHATWHATSAPP_ABANDONED_INTERVAL')) {
                $this->postErrors[] = $this->trans(
                    'Interval is Required.',
                    array(),
                    'Modules.TochatWhatsapp.Admin'
                );
            }
            if (!Tools::getValue('TOCHATWHATSAPP_ABANDONED_TEMPLATE')) {
                $this->postErrors[] = $this->trans(
                    'Abandoned Cart template is Required.',
                    array(),
                    'Modules.TochatWhatsapp.Admin'
                );
            }
        }

        if (Tools::isSubmit('tochatwhatsapp_widget')
            && Tools::getValue('TOCHATWHATSAPP_WIDGET_STATUS') == 1) {
            if (!Tools::getValue('TOCHATWHATSAPP_WIDGET_SNIPPET')) {
                $this->postErrors[] = $this->trans(
                    'Snippet is Required.',
                    array(),
                    'Modules.TochatWhatsapp.Admin'
                );
            }
        }
    }

    protected function postProcess()
    {
        if (Tools::isSubmit('tochatwhatsapp_general')) {
            Configuration::updateValue(
                'TOCHATWHATSAPP_GENERAL_STATUS',
                Tools::getValue('TOCHATWHATSAPP_GENERAL_STATUS')
            );
        }

        if (Tools::isSubmit('tochatwhatsapp_automation')) {
            Configuration::updateValue(
                'TOCHATWHATSAPP_AUTOMATION_STATUS',
                Tools::getValue('TOCHATWHATSAPP_AUTOMATION_STATUS')
            );
            Configuration::updateValue(
                'TOCHATWHATSAPP_AUTOMATION_APIKEY',
                Tools::getValue('TOCHATWHATSAPP_AUTOMATION_APIKEY')
            );
            Configuration::updateValue(
                'TOCHATWHATSAPP_AUTOMATION_ENDPOINT',
                Tools::getValue('TOCHATWHATSAPP_AUTOMATION_ENDPOINT')
            );
            Configuration::updateValue(
                'TOCHATWHATSAPP_AUTOMATION_COUNTRY_CODE',
                Tools::getValue('TOCHATWHATSAPP_AUTOMATION_COUNTRY_CODE')
            );
            
            Configuration::updateValue(
                'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_NEW',
                Tools::getValue('TOCHATWHATSAPP_AUTOMATION_TEMPLATE_NEW')
            );
            Configuration::updateValue(
                'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_PROCESSING',
                Tools::getValue('TOCHATWHATSAPP_AUTOMATION_TEMPLATE_PROCESSING')
            );
            Configuration::updateValue(
                'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_CANCELED',
                Tools::getValue('TOCHATWHATSAPP_AUTOMATION_TEMPLATE_CANCELED')
            );
            Configuration::updateValue(
                'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_COMPLETE',
                Tools::getValue('TOCHATWHATSAPP_AUTOMATION_TEMPLATE_COMPLETE')
            );
        }

        if (Tools::isSubmit('tochatwhatsapp_abandoned')) {
            Configuration::updateValue(
                'TOCHATWHATSAPP_ABANDONED_STATUS',
                Tools::getValue('TOCHATWHATSAPP_ABANDONED_STATUS')
            );
            Configuration::updateValue(
                'TOCHATWHATSAPP_ABANDONED_INTERVAL',
                Tools::getValue('TOCHATWHATSAPP_ABANDONED_INTERVAL')
            );
            Configuration::updateValue(
                'TOCHATWHATSAPP_ABANDONED_TEMPLATE',
                Tools::getValue('TOCHATWHATSAPP_ABANDONED_TEMPLATE')
            );
        }

        if (Tools::isSubmit('tochatwhatsapp_widget')) {
            Configuration::updateValue(
                'TOCHATWHATSAPP_WIDGET_STATUS',
                Tools::getValue('TOCHATWHATSAPP_WIDGET_STATUS')
            );
            Configuration::updateValue(
                'TOCHATWHATSAPP_WIDGET_SNIPPET',
                Tools::getValue('TOCHATWHATSAPP_WIDGET_SNIPPET'),
                true
            );
        }

        $this->html .= $this->displayConfirmation($this->trans('Settings updated', array(), 'Admin.Global'));
    }

    public function getContent()
    {
        $this->postValidation();
        if (!count($this->postErrors)) {
            $this->postProcess();
        } else {
            foreach ($this->postErrors as $err) {
                $this->html .= $this->displayError($err);
            }
        }

        $this->html .= '<br />';

        $this->getTochatTemplates();

        return $this->html . $this->renderForm() . $this->displayCronUrl();
    }

    public function getApiObject($apikey = null, $endpoint = null)
    {
        if (!$apikey) {
            $apikey = Configuration::get('TOCHATWHATSAPP_AUTOMATION_APIKEY');
        }

        if (!$endpoint) {
            $endpoint = Configuration::get('TOCHATWHATSAPP_AUTOMATION_ENDPOINT');
        }

        $api = new Api($apikey, $endpoint);
        $response = $api->getTemplates();
        if (gettype($response) == 'string') {
            return $response;
        }
        return $api;
    }

    public function getTochatTemplates()
    {
        if ($this->tochatTemps == null) {
            $this->tochatTemps = [];
            $api = $this->getApiObject();
            if (gettype($api) != 'string') {
                $response = $api->getTemplates();
                foreach ($response->waba_templates as $template) {
                    if ($template->status == 'approved') {
                        $this->tochatTemps[] = $template;
                    }
                }
            }
        }
        return $this->tochatTemps;
    }

    public function getTochatTemplatesOptions()
    {
        $return = [];
        if (count($this->tochatTemps)) {
            foreach ($this->tochatTemps as $template) {
                $return[] = [
                    'id_option' => $template->name . '.' . $template->language,
                    'name' => $template->name . '(' . $template->language . ')',
                ];
            }
        }
        return $return;
    }

    public function renderForm()
    {
        $general = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('General', array(), 'Modules.TochatWhatsapp.Admin'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Enable/Disable', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_GENERAL_STATUS',
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                            ),
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                    'name' => 'tochatwhatsapp_general',
                ),
            ),
        );
        $automation = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans(
                        'Order Notification with Whatsapp API',
                        array(),
                        'Modules.TochatWhatsapp.Admin'
                    ),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Enable/Disable', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_AUTOMATION_STATUS',
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                            ),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Tochat ApiKey', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_AUTOMATION_APIKEY',
                        'required' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Endpoint', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_AUTOMATION_ENDPOINT',
                        'required' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Default Country Code(ex. +49)', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_AUTOMATION_COUNTRY_CODE',
                        'required' => true
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->trans('Template New', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_NEW',
                        'required' => true,
                        'options' => array(
                            'query' => $this->getTochatTemplatesOptions(),
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->trans('Template Processing', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_PROCESSING',
                        'required' => true,
                        'options' => array(
                            'query' => $this->getTochatTemplatesOptions(),
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->trans('Template Canceled', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_CANCELED',
                        'required' => true,
                        'options' => array(
                            'query' => $this->getTochatTemplatesOptions(),
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->trans('Template Completed', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_AUTOMATION_TEMPLATE_COMPLETE',
                        'required' => true,
                        'options' => array(
                            'query' => $this->getTochatTemplatesOptions(),
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                    'name' => 'tochatwhatsapp_automation',
                ),
            ),
        );

        $interval = [
            ['id_option' => '0.25', 'name' => $this->trans("%m Minutes", ['%m' => 15])],
            ['id_option' => '0.5', 'name' => $this->trans("%m Minutes", ['%m' => 30])],
            ['id_option' => '1', 'name' => $this->trans("%m hour", ['%m' => 1])],
            ['id_option' => '3', 'name' => $this->trans("%m hours", ['%m' => 3])],
            ['id_option' => '5', 'name' => $this->trans("%m hours", ['%m' => 5])],
            ['id_option' => '7', 'name' => $this->trans("%m hours", ['%m' => 7])],
            ['id_option' => '10', 'name' => $this->trans("%m hours", ['%m' => 10])],
            ['id_option' => '24', 'name' => $this->trans("%m Day", ['%m' => 1])],
            ['id_option' => '48', 'name' => $this->trans("%m Days", ['%m' => 2])],
            ['id_option' => '72', 'name' => $this->trans("%m Days", ['%m' => 3])],
            ['id_option' => '120', 'name' => $this->trans("%m Days", ['%m' => 5])],
            ['id_option' => '168', 'name' => $this->trans("%m Days", ['%m' => 7])],
        ];

        $abandoned = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Recover Abandoned Cart', array(), 'Modules.TochatWhatsapp.Admin'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Enable/Disable', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_ABANDONED_STATUS',
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                            ),
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->trans('Interval', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_ABANDONED_INTERVAL',
                        'required' => true,
                        'options' => array(
                            'query' => $interval,
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->trans('Template', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_ABANDONED_TEMPLATE',
                        'required' => true,
                        'options' => array(
                            'query' => $this->getTochatTemplatesOptions(),
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                    'name' => 'tochatwhatsapp_abandoned',
                ),
            ),
        );

        $widget = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('WhatsApp Widget', array(), 'Modules.TochatWhatsapp.Admin'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Enable/Disable', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_WIDGET_STATUS',
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                            ),
                        ),
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->trans('Snippet Url', array(), 'Modules.TochatWhatsapp.Admin'),
                        'name' => 'TOCHATWHATSAPP_WIDGET_SNIPPET',
                        'required' => true,
                        'desc' => $this->trans('Please enter a valid url(eg.https://widget.tochat.be/bundle.js?key=xyz)', array(), 'Modules.TochatWhatsapp.Admin'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                    'name' => 'tochatwhatsapp_widget',
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->table = $this->table;

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;

        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&' . http_build_query(['configure' => $this->name]);
        $helper->submit_action = 'submit' . $this->name;

        // Default language
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');

        // Load current value into the form
        foreach ($this->config as $config) {
            $helper->fields_value[$config] = Tools::getValue($config, Configuration::get($config));
        }

        return $helper->generateForm([$general, $automation, $abandoned, $widget]);
    }

    public function hookDisplayAdminOrderSideBottom($params)
    {
        $status = Configuration::get('TOCHATWHATSAPP_GENERAL_STATUS', null);
        if (!$status) {
            return;
        }

        $id_order = $params["id_order"];
        $order = new Order($id_order);

        $address = new Address((int) $order->id_address_invoice);

        $this->context->smarty->assign([
            "customer_telephone" => $this->getCustomerTelehone($order->id_customer)
                ?? $address->phone
                ?? $address->phone_mobile,
        ]);
        return $this->display(__FILE__, "views/templates/hook/customer_telephone_field.tpl");
    }

    public function hookAdditionalCustomerFormFields($params)
    {
        $id_customer = Context::getContext()->customer->id;
        $telephone = $this->getCustomerTelehone($id_customer);

        $extra_fields = array();
        $extra_fields['telephone'] = (new FormField)
            ->setName('telephone')
            ->setType('text')
            ->setRequired(true)
            ->setValue($telephone)
            ->setLabel($this->trans('Telephone', array(), 'Modules.TochatWhatsapp.Admin'));

        return $extra_fields;
    }

    public function hookValidateCustomerFormFields($params)
    {
        $module_fields = $params['fields'];
        if (!Validate::isPhoneNumber($module_fields[0]->getValue())) {
            $module_fields[0]->addError(
                $this->l('Invalid format')
            );
        }
        return array(
            $module_fields,
        );
    }

    public function hookActionObjectCustomerUpdateAfter($params)
    {
        $id_customer = (int) $params['object']->id;
        $this->updateCustomerPhone($id_customer);
    }

    public function hookActionObjectCustomerAddAfter($params)
    {
        $id_customer = (int) $params['object']->id;
        $this->updateCustomerPhone($id_customer);
    }

    private function updateCustomerPhone($id_customer)
    {
        $telephone = Tools::getValue('telephone');
        $query = 'UPDATE `' . _DB_PREFIX_ . 'customer` c '
        . ' SET  c.`telephone` = "' . pSQL($telephone) . '"'
        . ' WHERE c.id_customer = ' . (int) $id_customer;
        Db::getInstance()->execute($query);
    }

    private function getCustomerTelehone($id_customer)
    {
        return Db::getInstance()->getValue("
            SELECT telephone
            FROM " . _DB_PREFIX_ . "customer
            WHERE id_customer = $id_customer
        ");
    }

    public function installBackofficeTab()
    {
        $tab = new Tab();
        foreach (Language::getLanguages() as $lang) {
            $tab->name[$lang["id_lang"]] = $this->l('ToChat Log');
        }
        $tab->class_name = 'AdminWhatsAppMessages';
        $tab->position = 10;
        $tab->id_parent = Db::getInstance()
                            ->getValue("SELECT id_tab 
                                FROM " . _DB_PREFIX_ . "tab 
                                WHERE class_name = 'AdminAdvancedParameters';
                            ");
        $tab->module = $this->name;
        $tab->add();
    }

    public function displayCronUrl()
    {
        $domain = Tools::usingSecureMode() ? Tools::getShopDomainSsl(true) : Tools::getShopDomain(true);
        $token = Tools::substr(Tools::encrypt('tochatwhatsapp/cron'), 0, 10);

        $link = new LinkCore;

        $this->smarty->assign([
            'cron_url' => $domain . __PS_BASE_URI__ . 'modules/tochatwhatsapp/cron.php?&token=' . $token,
            'log_url' => $link->getAdminLink('AdminWhatsAppMessages'),
        ]);
        return $this->display(__FILE__, 'views/templates/hook/cron-url.tpl');
    }

    public function automateMessage()
    {
        //CHECK IF AUTOMATION IS SET
        $automationStatus = Configuration::get('TOCHATWHATSAPP_AUTOMATION_STATUS', null);
        if (!$automationStatus) {
            return;
        }

        $sql = "SELECT * FROM " . _DB_PREFIX_ . "tochat_whatsapp_message
                        WHERE 1=1
                        AND `order_id` IS NOT NULL
                        AND `status` = 2";

        $apikey = Configuration::get('TOCHATWHATSAPP_AUTOMATION_APIKEY');
        $endpoint = Configuration::get('TOCHATWHATSAPP_AUTOMATION_ENDPOINT');
        $api = new Api($apikey, $endpoint);

        $messesges = Db::getInstance()->executeS($sql);
        if (!count($messesges)) {
            return;
        }

        $currencySym = $this->context->currency->sign;

        //Fetch Templates FROM API
        $templates = [];
        $templatesOrg = $this->getTochatTemplates();
        if (count($templatesOrg)) {
            foreach ($templatesOrg as $template) {
                $templates[$template->language][$template->name] = $template;
            }
        }

        //Automate Messages
        foreach ($messesges as $message) {
            $order = new Order((int) $message['order_id']);
            $address = new Address((int) $order->id_address_invoice);
            $template = null;
            switch ($order->current_state) {
                case self::ORDER_STATE_PROCESSING:
                    $template = Configuration::get('TOCHATWHATSAPP_AUTOMATION_TEMPLATE_PROCESSING', null);
                    break;
                case self::ORDER_STATE_CANCELED:
                    $template = Configuration::get('TOCHATWHATSAPP_AUTOMATION_TEMPLATE_CANCELED', null);
                    break;
                case self::ORDER_STATE_COMPLETE:
                    $template = Configuration::get('TOCHATWHATSAPP_AUTOMATION_TEMPLATE_COMPLETE', null);
                    break;
            }
            //CHECK IF ORDER IS NEW
            if (!$template) {
                $extradata = json_decode($message['extradata']);
                if (isset($extradata->state) && $extradata->state == 'new') {
                    $template = Configuration::get('TOCHATWHATSAPP_AUTOMATION_TEMPLATE_NEW', null);
                }
            }

            //Prefix country code
            $prefix = Configuration::get('TOCHATWHATSAPP_AUTOMATION_COUNTRY_CODE', null);
            $tel = $address->phone ?? $address->phone_mobile;
            if ($prefix && strpos($tel, $prefix) === false) {
                $tel = $prefix . $tel;
            }
            $tel = trim($tel, '+');

            $resData = [];

            // //Validate Contact
            if (empty($template)) {
                $resData = [
                    'status' => self::MESSAGE_STATUS_FAILED,
                    'log' => "Template Not found",
                    'sent_on' => date('Y-m-d H:i:s'),
                ];
            } elseif ($api->checkContact($tel)) {
                $tId = null;
                $lang = null;

                [$tId, $lang] = explode('.', $template);
                //Search the template body content for placeholder and fill them with values
                $tempObj = $templates[$lang][$tId];
                $body = current(array_filter($tempObj->components, function ($e) {
                    return $e->type == 'BODY';
                }));
                $name = null;
                foreach ($order->getProducts() as $item) {
                    $name[] = $item['product_name'];
                }
                $itemName = implode(',', $name);
                preg_match_all("/{{+\d+}}/", $body->text, $placeholders);
                $placeholders = $placeholders[0];
                $values = [
                    1 => $order->reference,
                    2 => $address->firstname . ' ' . $address->lastname,
                    3 => Tools::strlen($itemName) > 150 ? Tools::substr($itemName, 0, 150) . '...' : $itemName,
                    4 => $currencySym . number_format($order->total_paid_tax_incl, 2),
                ];
                if (count($placeholders)) {
                    foreach (range(1, 4) as $i) {
                        if (array_search("{{" . $i . "}}", $placeholders) === false) {
                            unset($values[$i]);
                        }
                    }
                }
                $messageStr = str_replace(array_map(function ($e) {
                    return '{{' . $e . '}}';
                }, array_keys($values)), $values, $body->text);
                //END

                $response = $api->sendWhatsApp(
                    $tel,
                    $values,
                    $tId, //template
                    $lang,
                    $tempObj->namespace//namespace
                );
                if (isset($response->meta->success)
                    && $response->meta->success == false) {
                    $resData = [
                        'status' => self::MESSAGE_STATUS_FAILED,
                        'message' => $messageStr,
                        'log' => $response->meta->developer_message,
                        'sent_on' => date('Y-m-d H:i:s'),
                    ];
                } else {
                    $resData = [
                        'status' => self::MESSAGE_STATUS_SENT,
                        'message' => $messageStr,
                        'sent_on' => date('Y-m-d H:i:s'),
                        'log' => null,
                    ];
                }
            } else {
                $resData = [
                    'status' => self::MESSAGE_STATUS_FAILED,
                    'log' => "Invalid Phone Number",
                    'sent_on' => date('Y-m-d H:i:s'),
                ];
            }
            //Update Message Status
            Db::getInstance()->update("tochat_whatsapp_message", $resData, "id = {$message['id']}");
        }
    }

    public function abandonedCart()
    {

        //CHECK IF AUTOMATION IS SE
        $automationStatus = Configuration::get('TOCHATWHATSAPP_ABANDONED_STATUS', null);
        if (!$automationStatus) {
            return;
        }

        $interval = (int) ((float) Configuration::get('TOCHATWHATSAPP_ABANDONED_INTERVAL', 10) * 60);

        $today = getdate();
        $now = date('Y-m-d H:i:s', $today[0]);

        $sql = "SELECT * FROM `" . _DB_PREFIX_ . "cart` 
            WHERE (`id_address_invoice` != '0' OR `id_customer` != 0) 
            AND `id_cart` NOT IN (SELECT `id_cart` 
                FROM `" . _DB_PREFIX_ . "orders`) 
            AND TIMESTAMPDIFF(MINUTE, `date_upd`, '$now') = $interval";

        $carts = Db::getInstance()->ExecuteS($sql);

        if (!$carts) {
            return false;
        }

        //Fetch Templates FROM API
        $templates = [];
        $templatesOrg = $this->getTochatTemplates();
        if (count($templatesOrg)) {
            foreach ($templatesOrg as $template) {
                $templates[$template->language][$template->name] = $template;
            }
        }

        $currencySym = $this->context->currency->sign;

        foreach ($carts as $cart) {
            $customerData = [];
            if ($cart['id_customer']) {
                $customer = new Customer((int) $cart['id_customer']);
                $customerData['email'] = $customer->email;
                $customerData['name'] = $customer->firstname . ' ' . $customer->lastname;
                $customerData['telephone'] = $this->getCustomerTelehone($customer->id);
            } elseif ($cart['id_address_invoice']) {
                $address = new Address((int) $cart['id_address_invoice']);
                $customerData['email'] = $address->firstname . ' ' . $address->lastname;
                $customerData['name'] = $address->firstname . ' ' . $address->lastname;
                $customerData['telephone'] = $address->phone ?? $address->phone_mobile;
            }

            if (count(array_filter($customerData)) != 3) {
                continue;
            }

            $cartObj = new Cart((int) $cart['id_cart']);


            //Prefix country code
            $prefix = Configuration::get('TOCHATWHATSAPP_AUTOMATION_COUNTRY_CODE', null);
            $tel = $customerData['telephone'];
            if ($prefix && strpos($customerData['telephone'], $prefix) === false) {
                $tel = $prefix . $tel;
            }
            $tel = trim($tel, '+');


            $template = Configuration::get('TOCHATWHATSAPP_ABANDONED_TEMPLATE', null);

            $api = $this->getApiObject();

            if (!empty($tel)
                && !empty($template)
                && $api->checkContact($tel)) {
                $tId = null;
                $lang = null;

                [$tId, $lang] = explode('.', $template);

                //Search the template body content for placeholder and fill them with values
                $tempObj = $templates[$lang][$tId];
                $body = current(array_filter($tempObj->components, function ($e) {
                    return $e->type == 'BODY';
                }));
                $name = null;
                foreach ($cartObj->getProducts() as $item) {
                    $name[] = $item['name'];
                }
                $itemName = implode(',', $name);
                preg_match_all("/{{+\d+}}/", $body->text, $placeholders);
                $placeholders = $placeholders[0];
                $values = [
                    1 => $customerData['name'],
                    2 => Tools::strlen($itemName) > 150 ? Tools::substr($itemName, 0, 150) . '...' : $itemName,
                    3 => $currencySym . number_format($cartObj->getOrderTotal(true, Cart::BOTH)),
                    4 => Configuration::get('PS_SHOP_NAME'),
                ];
                if (count($placeholders)) {
                    foreach (range(1, 4) as $i) {
                        if (array_search("{{" . $i . "}}", $placeholders) === false) {
                            unset($values[$i]);
                        }
                    }
                }
                $messageStr = str_replace(array_map(function ($e) {
                    return '{{' . $e . '}}';
                }, array_keys($values)), $values, $body->text);
                //END

                //Send Message via API
                $response = $api->sendWhatsApp(
                    $tel,
                    $values,
                    $tId, //template
                    $lang,
                    $tempObj->namespace//namespace
                );

                $status = self::MESSAGE_STATUS_SENT;
                $log = null;

                if ((
                    isset($response->meta->success)
                    &&
                    $response->meta->success == false
                )
                    || isset($response->errors)
                ) {
                    $status = seld::MESSAGE_STATUS_FAILED;
                    $log = implode("|", array_map(function ($ele) {
                        return $ele->details;
                    }, $response->errors));
                }

                Db::getInstance()->insert(
                    "tochat_whatsapp_message",
                    [
                        "message" => $messageStr,
                        "type" => self::MESSAGE_TYPE_CART,
                        "status" => $status,
                        "extradata" => json_encode($customerData),
                        "sent_on" => date('Y-m-d H:i:s'),
                        "log" => $log,
                    ]
                );
            }
        }
    }
}
