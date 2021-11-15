<?php
/**
 * @author    360dialog – Official WhatsApp Business Solution Provider. <info@360dialog.com>
 * @copyright 2021 360dialog GmbH.
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

class AdminWhatsAppMessagesController extends ModuleAdminController
{
    public $bootstrap = true;
    public $toolbar_title = "WhatsApp Messages";
    const STATUS_SENT = 1;
    const STATUS_PENDING = 2;
    const STATUS_FAILED = 3;
    const TYPE_ORDER = 1;
    const TYPE_CART = 2;

    public $filters = [
        [
            'filter_name' => 'ToChatWhatsAppMessageFilter_id',
            'database_where' => ' AND id LIKE "%@%" ', // @ is for the field value
        ], [
            'filter_name' => 'ToChatWhatsAppMessageFilter_order_id',
            'database_where' => ' AND order_id LIKE "%@%" ', // @ is for the field value
        ], [
            'filter_name' => 'ToChatWhatsAppMessageFilter_message',
            'database_where' => ' AND message LIKE "%@%" ', // @ is for the field value
        ], [
            'filter_name' => 'ToChatWhatsAppMessageFilter_log',
            'database_where' => ' AND log LIKE "%@%" ', // @ is for the field value
        ], [
            'filter_name' => 'ToChatWhatsAppMessageFilter_status',
            'database_where' => ' AND status LIKE "%@%" ', // @ is for the field value
        ], [
            'filter_name' => 'ToChatWhatsAppMessageFilter_type',
            'database_where' => ' AND type LIKE "%@%" ', // @ is for the field value
        ], [
            'filter_name' => 'ToChatWhatsAppMessageFilter_extradata',
            'database_where' => ' AND extradata LIKE "%@%" ', // @ is for the field value
        ],
    ];

    public function initContent()
    {
        $this->postProcess();

        $this->context->smarty->assign([
            "helperList" => $this->getMessagesList(),
        ]);
        $this->setTemplate('../../../../modules/tochatwhatsapp/views/templates/admin/message_list.tpl');
    }

    public function postProcess()
    {
        if (Tools::isSubmit("submitResetToChatWhatsAppMessage")) {
            foreach ($this->filters as $filter) {
                $_POST[$filter["filter_name"]] = '';
            }
        }
        if (Tools::isSubmit("deleteToChatWhatsAppMessage")) {
            $this->deleteWhatsAppMessage(Tools::getValue("id"));
        }
    }

    public function handleFilters()
    {
        $where = '';
        foreach ($this->filters as $filter) {
            $filter_value = Tools::getValue($filter["filter_name"]);
            if ($filter_value != '' && $filter_value != null) {
                $where .= str_replace("@", $filter_value, $filter["database_where"]);
            }
        }
        return $where;
    }

    public function getMessages()
    {
        $sql = "SELECT * FROM " . _DB_PREFIX_ . "tochat_whatsapp_message WHERE 1=1 ";
        $sql .= $this->handleFilters();
        $sql .= "ORDER BY id DESC ";

        $messages = Db::getInstance()->executeS($sql);
        foreach ($messages as &$message) {
            // Format the status
            switch ($message["status"]) {
                case self::STATUS_SENT:
                    $message["status"] = "Sent";
                    break;
                case self::STATUS_PENDING:
                    $message["status"] = "Pending";
                    break;
                case self::STATUS_FAILED:
                    $message["status"] = "Failed";
                    break;
            }

            // Format the type
            switch ($message["type"]) {
                case self::TYPE_ORDER:
                    $message["type"] = $this->l('Order');
                    break;
                case self::TYPE_CART:
                    $message["type"] = $this->l('Abandoned Cart');
                    break;
            }

            // json decode extra data
            $extradata_arr = json_decode($message["extradata"]);
            $message["extradata"] = '';
            foreach ($extradata_arr as $key => $row) {
                $message["extradata"] .= $key . ":" . $row . "\n";
            }
        }

        return $messages;
    }

    public function getMessagesList()
    {
        $messages = $this->getMessages();

        $this->fields_list = array(
            'id' => array(
                'title' => 'ID',
                'width' => 'auto',
                'type' => 'id',
            ),
            'order_id' => array(
                'title' => $this->l('Order Id'),
                'width' => 'auto',
                'type' => 'text',
            ),
            'message' => array(
                'title' => $this->l('Message'),
                'width' => 'auto',
                'type' => 'text',
            ),
            'log' => array(
                'title' => $this->l('Log'),
                'width' => 'auto',
                'type' => 'text',
            ),
            'status' => array(
                'title' => $this->l('Status'),
                'width' => 'auto',
                'type' => 'text',
            ),
            'sent_on' => array(
                'title' => $this->l('On'),
                'width' => 'auto',
                'type' => 'text',
                'search' => false,
            ),
            'type' => array(
                'title' => $this->l('Type'),
                'width' => 'auto',
                'type' => 'text',
            ),
            'extradata' => array(
                'title' => $this->l('Data'),
                'width' => 'auto',
                'type' => 'text',
            ),
        );

        $helper = new HelperList();
        $helper->className = 'NewHook';
        $helper->listTotal = count($messages);

        $helper->simple_header = false;
        $helper->identifier = 'id';
        $helper->actions = [
            'delete',
        ];

        $helper->show_toolbar = true;
        $helper->_pagination = [20, 50, 100];
        $helper->_default_pagination = 50;
        $helper->shopLinkType = $this->shopLinkType;
        $helper->title = $this->l('Messages');
        $helper->table = 'ToChatWhatsAppMessage';
        $helper->token = Tools::getAdminTokenLite('AdminWhatsAppMessages');
        $helper->currentIndex = AdminController::$currentIndex;

        // Handle pagination
        $page = ($page = Tools::getValue('submitFilter' . $helper->table)) ? $page : 1;
        $pagination = ($pagination = Tools::getValue($helper->table . '_pagination')) ? $pagination : 10;
        $messages = $this->paginateContent($messages, $page, $pagination);

        return ($helper->generateList($messages, $this->fields_list));
    }

    public function paginateContent($content, $page = 1, $pagination = 20)
    {
        if (count($content) > $pagination) {
            $content = array_slice($content, $pagination * ($page - 1), $pagination);
        }
        return $content;
    }

    private function deleteWhatsAppMessage($id)
    {
        if ($id == '' || $id == null) {
            return;
        }
        Db::getInstance()->execute("DELETE FROM " . _DB_PREFIX_ . "tochat_whatsapp_message WHERE id= " . $id);
    }
}
