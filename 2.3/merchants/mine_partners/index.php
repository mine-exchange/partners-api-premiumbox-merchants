<?php
/*
title: [en_US:]MINE Partners[:en_US][ru_RU:]MINE Partners[:ru_RU]
description: [en_US:]PremiumExchanger 2.3 supported[:en_US][ru_RU:]Поддерживается PremiumExchanger 2.3[:ru_RU]
version: 1.0
*/

if( !defined( 'ABSPATH')){ exit(); }

if(!class_exists('Ext_Merchant_Premiumbox')){ return; }

if (!trait_exists( 'MinePartnersTrait')) {
    require_once __DIR__ . "/../../includes/mine_partners/MinePartnersTrait.php";
}

if(!class_exists('merchant_mine_partners')):
    class merchant_mine_partners extends Ext_Merchant_Premiumbox
    {
        use MinePartnersTrait;

        protected $wpdb;

        public function __construct($file, $title)
        {
            parent::__construct($file, $title, 1);

            GLOBAL $wpdb;
            $this->wpdb = $wpdb;

            $ids = $this->get_ids('merchants', $this->name);
            foreach ($ids as $id) {
                add_action('premium_merchant_' . $id . '_webhook' . hash_url($id), [$this, 'webhook']);
            }
        }

        public function options($options, $data, $id, $place)
        {
            $options = pn_array_unset($options, ['personal_secret', 'note', 'check_api', 'show_error', 'enableip']);

            $options = $this->addAdditionalOptions($options, $data);

            $urlHash = hash_url($id);

            $text = '
                <div>
                    <h2>Webhook url: <a href="' . get_mlink($id . '_webhook' . $urlHash) . '" target="_blank">' . get_mlink($id . '_webhook' . $urlHash) . '</a></h2>                
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
                    <h4>Cron url: <a href="' . get_mlink($id . '_cron' . $urlHash) . '" target="_blank">' . get_mlink($id . '_cron' . $urlHash) . '</a></h4>                
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

        public function bidaction($content, $merchantId, $amount, $direction)
        {
            GLOBAL $bids_data;

            $order = $bids_data;

            $script = get_mscript($merchantId);
            if (!$script || $script != $this->name) {
                return $content;
            }

            if (!isset($this->internalAccountCurrencies[$order->currency_code_give])) {
                $this->addAdminComment(
                    "Валюта \"{$order->currency_code_give}\" не поддерживается. Доступные: " . implode(',', array_keys($this->internalAccountCurrencies)),
                    $order->id
                );

                return __('Error! Please contact website technical support', 'pn');
            }

            if ($order->trans_in) {
                $paymentLink = trim(get_bids_meta($order->id, 'pay_link'));
                if ($paymentLink) {
                    return $this->redirectToUrl($paymentLink);
                }

                if ($order->to_account) {
                    return $this->addPaymentNumberToContent(
                        $content,
                        $order->to_account,
                        get_pagenote($merchantId, $order, $amount),
                        $amount,
                        strtoupper($order->currency_code_give)
                    );
                }

                $this->addAdminComment('Отсутствует вариант приёма оплаты. Свяжитесь с технической поддержкой платёжного шлюза', $order->id);

                return __('Error! Please contact website technical support', 'pn');
            }

            $currency = $this->wpdb->get_row("SELECT xml_value FROM {$this->wpdb->prefix}currency WHERE id = {$order->currency_id_give} LIMIT 1");
            if (!$currency) {
                $this->addAdminComment('Невозможно получить валюту из направления', $order->id);

                return __('Error! Please contact website technical support', 'pn');
            }

            $merchantSettings = $this->get_file_data($merchantId);
            $api = new MinePartnersApi(
                is_deffin($merchantSettings, 'API_URL'),
                is_deffin($merchantSettings, 'API_AUTH_KEY'),
                is_deffin($merchantSettings, 'API_SIGNATURE_KEY'),
                $merchantId,
                $this->name
            );

            $createdOrder = $this->createOrder($api, $currency->xml_value, $amount, $order);
            if (is_null($createdOrder)) {
                return __('Error! Please contact website technical support', 'pn');
            }

            try {
                $merchantData = get_merch_data($merchantId);
                $allowedDifferencePercentFewer = is_isset($merchantData, 'allowed_difference_percent_fewer');
                if ($allowedDifferencePercentFewer == '') {
                    $allowedDifferencePercentFewer = 0.1;
                }

                $order = $this->recalculateOrderAmountsIfNeeded($order, $direction, $amount, is_sum($createdOrder->inbound->amount, 8), $allowedDifferencePercentFewer, 1);
            } catch (\Exception $e) {
                $this->addAdminComment($e->getMessage(), $order->id);

                return __('Error! Please contact website technical support', 'pn');
            }

            set_bid_status('techpay', $order->id, ['trans_in' => $createdOrder->order_id]);

            if (isset($createdOrder->payment_variants->invoice->url) && !empty($createdOrder->payment_variants->invoice->url)) {
                $paymentLink = $createdOrder->payment_variants->invoice->url;
                update_bids_meta($order->id, 'pay_link', $paymentLink);

                return $this->redirectToUrl($paymentLink);
            }

            if (isset($createdOrder->payment_variants->direct->url) && !empty(isset($createdOrder->payment_variants->direct->url))) {
                $paymentLink = $createdOrder->payment_variants->direct->url;
                update_bids_meta($order->id, 'pay_link', $paymentLink);

                return $this->redirectToUrl($paymentLink);
            }

            if (isset($createdOrder->payment_variants->manual->number) && !empty($createdOrder->payment_variants->manual->number)) {
                $paymentNumber = $createdOrder->payment_variants->manual->number;
                update_bid_tb($order->id, 'to_account', $paymentNumber, $order);

                return $this->addPaymentNumberToContent(
                    $content,
                    $paymentNumber,
                    get_pagenote($merchantId, $order, $amount),
                    $amount,
                    strtoupper($order->currency_code_give)
                );
            }

            $this->addAdminComment('Отсутствует вариант приёма оплаты. Свяжитесь с технической поддержкой платёжного шлюза', $order->id);

            return __('Error! Please contact website technical support', 'pn');
        }

        public function cron($merchantId, $merchantSettings, $merchantData)
        {
            if (!$this->lock()) {
                return;
            }

            $api = new MinePartnersApi(
                is_deffin($merchantSettings, 'API_URL'),
                is_deffin($merchantSettings, 'API_AUTH_KEY'),
                is_deffin($merchantSettings, 'API_SIGNATURE_KEY'),
                $merchantId,
                $this->name
            );

            $orders = $this->wpdb->get_results("SELECT id, trans_in FROM {$this->wpdb->prefix}exchange_bids WHERE status IN ('new', 'techpay', 'coldpay') AND trans_in IS NOT NULL AND trans_in <> '0' AND trans_in <> '' AND m_in = '{$merchantId}'");
            if (empty($orders)) {
                return;
            }

            $ordersIds = array_column((array) $orders, 'id', 'trans_in');
            $result = $this->getAllOrders('merchant', $api, array_keys($ordersIds));
            if (empty($result)) {
                return;
            }

            $ordersFromApi = [];
            foreach ($result as $order) {
                $ordersFromApi[$order->order_id] = $order;
            }

            foreach ($ordersIds as $apiOrderId => $orderId) {
                if (!isset($ordersFromApi[$apiOrderId])) {
                    $this->addAdminComment("Не найден в ответе от API платёжного шлюза", $orderId);
                    continue;
                }

                $this->merchantChangeOrderStatusIfNeeded($orderId, $ordersFromApi[$apiOrderId]);
            }
        }

        public function webhook()
        {
            $merchantId = key_for_url('_webhook');
            $merchantSettings = $this->get_file_data($merchantId);

            $webhookData = file_get_contents('php://input');

            $receivedSignature = is_isset($_SERVER, 'HTTP_X_SIGNATURE');
            $generatedSignature = $this->generateWebhookSignature($webhookData, $merchantSettings['API_SIGNATURE_KEY']);

            if (!hash_equals($receivedSignature, $generatedSignature)) {
                $this->merchantLog("Signature is invalid. Received signature: \"{$receivedSignature}\", generated signature: \"{$generatedSignature}\". Webhook data:\r\n" . print_r($webhookData, true));

                die('Signature is invalid');
            }

            $orderData = @json_decode($webhookData);

            $this->merchantLog($orderData);

            $this->webhookHandler($orderData, $merchantId);
        }

        private function createOrder($api, $currency, $amount, $orderData)
        {
            if (isset($this->benefitDirectionsSwap[$currency])) {
                $currency = $this->benefitDirectionsSwap[$currency];
            }

            $orderId = $orderData->id;
            $premiumOrderId = "pr_in_{$orderId}";

            $orders = [
                [
                    'order_id' => $premiumOrderId,
                    'inbound' => [
                        'currency' => $currency,
                        'account' => $orderData->account_give,
                        'amount' => $amount,
                    ],
                    'outbound' => [
                        'currency' => $this->internalAccountCurrencies[$orderData->currency_code_give],
                        'account' => 'pbo',
                    ],
                    'extra' => [
                        'client' => [
                            'id' => $orderData->user_id,
                            'email' => $orderData->user_email,
                            'ip' => $orderData->user_ip,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                        ],
                    ],
                ]
            ];

            try {
                $response = $api->createOrders($orders);
            } catch (\Exception $e) {
                $this->addAdminComment($e->getMessage(), $orderId);
                return null;
            }

            if (isset($response->created->$premiumOrderId)) {
                return $response->created->$premiumOrderId;
            }

            if (isset($response->invalid->$premiumOrderId)) {
                $errorMessage = $response->invalid->$premiumOrderId;
            } else {
                $errorMessage = "Unexpected response:\r\n" . print_r($response, true);
            }

            $this->addAdminComment($errorMessage, $orderId);
            return null;
        }

        private function addPaymentNumberToContent($content, $payNumber, $notes, $amount, $currency)
        {
            if (!empty($notes)) {
                $content .= '<div class="zone_pagenote">'. apply_filters('comment_text', $notes) .'</div>';
            }

            $content .= '       
            <div class="zone_table">                
                <div class="zone_div">
                    <div class="zone_title"><div class="zone_copy" data-clipboard-text="'. $amount .'"><div class="zone_copy_abs">'. __('copied to clipboard', 'pn') .'</div>'. __('Amount', 'pn') .'</div></div>
                    <div class="zone_text">'. $amount .' '. $currency .'</div>                  
                </div>              
                <div class="zone_div">
                    <div class="zone_title"><div class="zone_copy" data-clipboard-text="'. $payNumber .'"><div class="zone_copy_abs">'. __('copied to clipboard', 'pn') .'</div>'. __('Address', 'pn') .'</div></div>
                    <div class="zone_text">'. $payNumber .'</div>                   
                </div>              
            </div>              
        ';


            $content .=
                '<div style="padding: 20px 0; width: 260px; margin: 0 auto;">
                <div id="qr_adress"></div>
            </div>
            
            <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/lrsjng.jquery-qrcode/0.14.0/jquery-qrcode.min.js"></script>
            <script type="text/javascript">
            jQuery(function($){
                $("#qr_adress").qrcode({
                    size: 260,
                    text: "'. "bitcoin:{$payNumber}?amount={$amount}" .'"
                });
            });
            </script>';

            return $content;
        }

        private function redirectToUrl($url)
        {
            @wp_redirect($url);

            return '
            <div class="zone_table zone_div">
                <h1>Выполняется переход на страницу оплаты. </h1>
                <a href="' .$url. '" referrerpolicy="unsafe-url" id="merchant-redirect-url">Кликните, если переход не произойдёт в течении 10 секунд.</a>
            </div>
            <script type="text/javascript">
                document.getElementById("merchant-redirect-url").click();
            </script>
        ';
        }

    }
endif;
new merchant_mine_partners(__FILE__, 'MINE Partners');