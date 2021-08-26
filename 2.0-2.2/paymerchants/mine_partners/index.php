<?php
/*
title: [en_US:]MINE Partners[:en_US][ru_RU:]MINE Partners[:ru_RU]
description: [en_US:]PremiumExchanger 2.0 - 2.2 supported[:en_US][ru_RU:]Поддерживается PremiumExchanger 2.0 - 2.2[:ru_RU]
version: 2.0
*/

if( !defined( 'ABSPATH')){ exit(); }

if(!class_exists('AutoPayut_Premiumbox')){ return; }

if (!trait_exists( 'MinePartnersTrait')) {
    require_once __DIR__ . "/../../includes/mine_partners/MinePartnersTrait.php";
}

if(!class_exists('paymerchant_mine_partners')):
class paymerchant_mine_partners extends AutoPayut_Premiumbox
{
    use MinePartnersTrait;

    private $merchantIsActive;

    public function __construct($file, $title)
    {
        parent::__construct($file, $title, 0);

        $this->initMinePartnersSettings();

        $merchants = get_option('extlist_merchants');
        if (isset($merchants[$this->name]) && isset($merchants[$this->name]['status']) && $merchants[$this->name]['status']) {
            $this->merchantIsActive = true;
        } else {
            $this->merchantIsActive = false;
        }

        $ids = $this->get_ids('paymerchants', $this->name);
        foreach ($ids as $id) {
            if ($this->merchantIsActive) {
                $urlHash = hash_url($id);
                add_action("premium_merchant_{$id}_webhook{$urlHash}", [$this, 'webhook']);
            } else {
                $urlHash = hash_url($id, 'ap');
                add_action("premium_merchant_ap_{$id}_webhook{$urlHash}", [$this, 'webhook']);
            }

            add_action("premium_merchant_ap_{$id}_cron{$urlHash}", [$this, 'paymerchant_cron']);
        }
    }

    public function options($options, $data, $id, $place)
    {
        $options = pn_array_unset($options, 'error_status');
        $options = pn_array_unset($options, 'checkpay');
        $options = pn_array_unset($options, 'note');
        $options = pn_array_unset($options, 'enableip');

        if ($this->merchantIsActive) {
            $options = pn_array_unset($options, 'resulturl');
            $options[] = [
                'view' => 'textfield',
                'title' => 'Хэш для Status/Result URL',
                'default' => '<div>Хэш для "Status/Result URL" используется из мерчанта</div>',
            ];

            $urlHash = hash_url($id);
            $webhookUrl = get_mlink("{$id}_webhook{$urlHash}");
        } else {
            $urlHash = hash_url($id, 'ap');
            $webhookUrl = get_mlink("ap_{$id}_webhook{$urlHash}");
        }

        $options = $this->addAdditionalOptions($options, $data);

        $text = '
            <div>
                <h2>Webhook url: <a href="' . $webhookUrl . '" target="_blank">' . $webhookUrl . '</a></h2>                
                Данный URL необходимо добавить в личном кабинете MINE в поле "URL обратного вызова". <br />
                <br />
                <b>Обратите внимание: premiumbox накладывает некоторые ограничения, поэтому:</b>
                <ul style="padding:revert !important; list-style: initial !important;">
                    <li>Если активирован мерчант, URL и для мерчанта и для пеймерчанта всегда используется из мерчанта.</li>
                    <li>⚠️ Если мерчант деактивирован, настройки URL берётся из пеймерчанта (поэтому если вы деактивируете мерчант, не забудьте поменять адрес в личном кабинете MINE)</li>
                </ul>    
            </div>
            <hr />
            <div>
                <h4>Cron url: <a href="' . get_mlink('ap_' . $id . '_cron' . $urlHash) . '" target="_blank">' . get_mlink('ap_' . $id . '_cron' . $urlHash) . '</a></h4>                
                👎 Мы не рекомендуем использовать проверку по CRON в силу множества нагрузочных особенностей. Однако она тоже доступна на самый крайний случай.
                <h4>⚠️ По умолчанию хеш, который дописывается в адрес CRON скрипта всегда берётся из настроек мерчанта. Если вы деактивируете мерчант, не забудьте поменять в вашем CRONTAB ссылку, взяв её из настроек пеймерчанта.</h4>
            </div>
        ';
        $options['webhook_and_cron_urls'] = [
            'view' => 'textfield',
            'title' => '',
            'default' => $text,
        ];

        return $options;
    }

    public function get_reserve_lists($paymerchantId, $paymerchantSettings)
    {
        $currencies = [];
        foreach ($this->internalAccountCurrencies as $currencyCode => $currencyName) {
            $currencyName = strtolower($currencyName);
            $currencies["{$paymerchantId}_{$currencyName}"] = $currencyCode;
        }
        return $currencies;
    }

    public function update_reserve($code, $paymerchantId, $paymerchantSettings)
    {
        $currency = trim(
            is_isset(
                $this->get_reserve_lists($paymerchantId, $paymerchantSettings),
                $code
            )
        );
        if (!$currency) {
            return 0;
        }

        $api = new AP_MinePartnersApi(
            is_deffin($paymerchantSettings, 'API_URL'),
            is_deffin($paymerchantSettings, 'API_AUTH_KEY'),
            is_deffin($paymerchantSettings, 'API_SIGNATURE_KEY')
        );

        try{
            $balance = $api->getBalance($currency);
        } catch (Exception $e) {
            $this->paymerchantLog($e->getMessage());
            return 0;
        }

        return (!empty($balance)) ? $balance : 0;
    }

    public function do_auto_payouts($error, $payError, $paymerchantId, $order, $place, $directionData, $paymerchantData, $unmetas, $modulePlace, $direction, $isTest, $paymerchantSettings)
    {
        $isTest = 0;
        if (!empty($error)) {
            $this->reset_ap_status($error, $payError, $order, $place, $isTest);
            return;
        }

        $this->set_ap_status($order, $isTest);

        if (!isset($this->internalAccountCurrencies[$order->currency_code_get])) {
            $commentText = "Валюта \"{$order->currency_code_get}\" не поддерживается. Доступные: " . implode(',', array_keys($this->internalAccountCurrencies));
            $this->addAdminComment($commentText, $order->id);
            $this->reset_ap_status([$commentText], 1, $order, $place, $isTest);
            return;
        }

        $amount = is_sum(is_paymerch_sum($order, $paymerchantData), 8);
        if (!$amount) {
            $commentText = 'Невозможно получить сумму заявки';
            $this->addAdminComment($commentText, $order->id);
            $this->reset_ap_status([$commentText], 1, $order, $place, $isTest);
            return;
        }

        if (!$order->trans_out) {
            $currency = $this->wpdb->get_row("SELECT xml_value FROM {$this->wpdb->prefix}currency WHERE id = {$order->currency_id_get} LIMIT 1");
            if (!$currency) {
                $commentText = 'Невозможно получить валюту из направления';
                $this->addAdminComment($commentText, $order->id);
                $this->reset_ap_status([$commentText], 1, $order, $place, $isTest);
                return;
            }

            $api = new AP_MinePartnersApi(
                is_deffin($paymerchantSettings, 'API_URL'),
                is_deffin($paymerchantSettings, 'API_AUTH_KEY'),
                is_deffin($paymerchantSettings, 'API_SIGNATURE_KEY')
            );

            try {
                $createdOrder = $this->createOrder($api, $currency->xml_value, $amount, $order);
            } catch (Exception $e) {
                $this->addAdminComment($e->getMessage(), $order->id);
                $this->reset_ap_status([$e->getMessage()], 1, $order, $place, $isTest);
                return;
            }

            $order = update_bid_tb_array($order->id, ['trans_out' => $createdOrder->order_id], $order);

            $actualAmount = is_sum($createdOrder->outbound->amount, 8);

            $allowedDifferencePercentageFewer = is_isset($paymerchantData, 'allowed_difference_percent_fewer');
            if ($allowedDifferencePercentageFewer == '') {
                $allowedDifferencePercentageFewer = 0.1;
            }

            if ($amount != $actualAmount && $this->isNotAllowedAmountDifference($amount, $actualAmount, $allowedDifferencePercentageFewer)) {
                $errorMessage = "Difference between initial and amount got from API is bigger than {$allowedDifferencePercentageFewer}%";
                $this->addAdminComment($errorMessage, $order->id);
                set_bid_status('verify', $order->id);

                if ($place == 'admin'){
                    pn_display_mess("{$errorMessage}. The order has been transferred to the status \"verify\".");
                }
                return;
            }

            try {
                $confirmedOrder = $this->confirmOrder($api, $order);
            } catch (Exception $e) {
                $this->addAdminComment($e->getMessage(), $order->id);
                $this->reset_ap_status([$e->getMessage()], 1, $order, $place, $isTest);
                return;
            }

            set_bid_status('coldsuccess', $order->id);
        }

        if ($place == 'admin'){
            pn_display_mess(
                __('Automatic payout is done', 'pn'),
                __('Automatic payout is done', 'pn'),
                'true'
            );
        }
    }

    public function cron($paymerchantId, $paymerchantSettings, $paymerchantData)
    {
        if (!$this->lock('paymerchant')) {
            return;
        }

        $api = new AP_MinePartnersApi(
            is_deffin($paymerchantSettings, 'API_URL'),
            is_deffin($paymerchantSettings, 'API_AUTH_KEY'),
            is_deffin($paymerchantSettings, 'API_SIGNATURE_KEY')
        );

        $ordersResult = $this->wpdb->get_results("SELECT id, status, trans_out, sum2c, sum2dc, sum2r, sum2 FROM {$this->wpdb->prefix}exchange_bids WHERE status IN ('coldsuccess', 'payouterror', 'verify') AND trans_out IS NOT NULL AND trans_out <> '0' AND trans_out <> '' AND m_out = '{$paymerchantId}'");
        if (empty($ordersResult)) {
            return;
        }

        $orders = [];
        foreach ($ordersResult as $order) {
            $orders[$order->trans_out] = $order;
        }

        $result = $this->getAllOrders('paymerchant', $api, array_keys($orders));
        if (empty($result)) {
            return;
        }

        $ordersFromApi = [];
        foreach ($result as $order) {
            $ordersFromApi[$order->order_id] = $order;
        }

        foreach ($orders as $apiOrderId => $order) {
            $orderId = $order->id;

            if (!isset($ordersFromApi[$apiOrderId])) {
                $this->addAdminComment("Not found in response from api", $orderId);
                $this->setPayoutError($orderId);
                $this->paymerchantLog("Error: Order with \"trans_out\"=\"{$apiOrderId}\" not found in response from api", $orderId);
                continue;
            }

            $this->paymerchantChangeOrderStatusIfNeeded($order, $ordersFromApi[$apiOrderId], $paymerchantId);
        }
    }

    public function webhook()
    {
        $paymerchantId = key_for_url('_webhook', 'ap_');
        $paymerchantSettings = $this->get_file_data($paymerchantId);

        $webhookData = file_get_contents('php://input');

        $receivedSignature = is_isset($_SERVER, 'HTTP_X_SIGNATURE');
        $generatedSignature = $this->generateWebhookSignature($webhookData, $paymerchantSettings['API_SIGNATURE_KEY']);
        if (!hash_equals($receivedSignature, $generatedSignature)) {
            $this->paymerchantLog("Signature is invalid. Received signature: \"{$receivedSignature}\", generated signature: \"{$generatedSignature}\". Webhook data:\r\n" . print_r($webhookData, true));
            die('Signature is invalid');
        }

        $orderData = @json_decode($webhookData);

        $this->paymerchantLog($orderData);

        $this->webhookHandler($orderData, $paymerchantId);
        return;
    }

    private function createOrder($api, $currency, $amount, $orderData)
    {
        $orderId = $orderData->id;
        $premiumOrderId = "pr_out_{$orderId}";

        $orders = [
            [
                'order_id' => $premiumOrderId,
                'inbound' => [
                    'currency' => $this->internalAccountCurrencies[$orderData->currency_code_get],
                    'account' => 'pbo',
                ],
                'outbound' => [
                    'currency' =>$currency,
                    'account' => $orderData->account_get,
                    'amount' => $amount,
                ],
                'auto_confirm' => false
            ]
        ];

        $response = $api->createOrders($orders);

        if (isset($response->created->$premiumOrderId)) {
            return $response->created->$premiumOrderId;
        }

        if (isset($response->invalid->$premiumOrderId)) {
            $errorMessage = $response->invalid->$premiumOrderId;
        } else {
            $errorMessage = "Unexpected response:\r\n" . print_r($response, true);
        }

        throw new \Exception($errorMessage);
    }

    private function confirmOrder($api, $orderData)
    {
        $premiumOrderId = "pr_out_{$orderData->id}";

        $response = $api->confirmOrders([$premiumOrderId]);

        if (isset($response->confirmed->$premiumOrderId)) {
            return $response->confirmed->$premiumOrderId;
        }

        if (isset($response->invalid->$premiumOrderId)) {
            $errorMessage = $response->invalid->$premiumOrderId;
        } else {
            $errorMessage = "Unexpected response:\r\n" . print_r($response, true);
        }

        throw new \Exception($errorMessage);
    }
}
endif;
new paymerchant_mine_partners(__FILE__, 'MINE Partners');