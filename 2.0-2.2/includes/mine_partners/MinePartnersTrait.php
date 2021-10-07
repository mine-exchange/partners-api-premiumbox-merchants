<?php

trait MinePartnersTrait
{
    protected $internalAccountCurrencies = [
        'RUB' => 'VNRUB',
        'BTC' => 'VNBTC',
        'UAH' => 'VNUAH',
        'USD' => 'VNUSD',
    ];

    protected $benefitDirectionsSwap = [
        'QWRUB' => 'QWRUBVIP',
    ];

    protected $merchantStatusesMessages = [
        'error' => 'Ошибка в заявке. Свяжитесь с технической поддержкой. Повторную отправку делать запрещено.',
        'canceled' => 'Возможно, прошло более 30 минут с момента её создания, либо вы отменили заявку через ЛК, возможны иные причины.',
        'moderation' => 'Заявка проверяется оператором сервиса. Свяжитесь с службой поддержки чтобы уточнить детали.'
    ];

    protected $paymerchantStatusesMessages = [
        'error' => 'Ошибка выплаты, пожалуйста свяжитесь с службой поддержки. Данный статус НЕ является финальным. Средства возможно ушли получателю! Для уточнения деталей следует обратиться в поддержку нашего сервиса!',
        'canceled' => 'Деньги по заявке не отправлены и возвращены на Ваш внутренний счет. Возможные причины: ошибка в реквизитах получателя, ограничения банка эмитента и др. После получения этого статуса можно повторять отправку.',
        'moderation' => 'Заявка проверяется оператором сервиса. Свяжитесь с службой поддержки чтобы уточнить детали.'
    ];

    protected $isOldPremiumExchanger = false;

    protected $wpdb;

    public function initMinePartnersSettings()
    {
        $this->setupFakeDataInSettingsUI();

        GLOBAL $wpdb;
        $this->wpdb = $wpdb;

        if (function_exists('is_my_money')) {
            $this->isOldPremiumExchanger = true;
        }
    }

    public function get_map()
    {
        return [
            'API_URL' => [
                'title' => '[en_US:]API url[:en_US][ru_RU:]API url[:ru_RU]',
                'view' => 'input',
            ],
            'API_AUTH_KEY' => [
                'title' => '[en_US:]Auth key[:en_US][ru_RU:]Ключ авторизации[:ru_RU]',
                'view' => 'input',
            ],
            'API_SIGNATURE_KEY' => [
                'title' => '[en_US:]Signature key[:en_US][ru_RU:]Ключ подписи[:ru_RU]',
                'view' => 'input',
            ],
        ];
    }

    public function settings_list()
    {
        return [
            [
                'API_URL',
                'API_AUTH_KEY',
                'API_SIGNATURE_KEY',
            ]
        ];
    }


    public function log($type, $text, $orderId = '')
    {
        if ($type == 'merchant') {
            $this->merchantLog($text);
        } else {
            $this->paymerchantLog($text, $orderId);
        }
    }

    protected function addAdditionalOptions($options, $data)
    {
        $options['mine_partner_line1'] = [
            'view' => 'line',
        ];

        $allowedDifferencePercentFewer = is_isset($data, 'allowed_difference_percent_fewer');
        if ($allowedDifferencePercentFewer == '') {
            $allowedDifferencePercentFewer = 0.1;
        }

        $options['allowed_difference_percent_fewer'] = [
            'view' => 'input',
            'title' => 'Допустимый % расхождения для пересчёта',
            'name' => 'allowed_difference_percent_fewer',
            'default' => $allowedDifferencePercentFewer,
            'work' => 'input',
        ];
        $options['allowed_difference_percent_fewer_help'] = [
            'view' => 'help',
            'title' => __('More info', 'pn'),
            'default' => 'Максимальная разница в процентах между исходной заявкой и пересчётом в REST на этапе <b>создания</b> заявки. <a href="https://github.com/mine-exchange/partners-api/blob/main/README.md#%EF%B8%8F-%D1%84%D0%B8%D0%BD%D0%B0%D0%BD%D1%81%D0%BE%D0%B2%D1%8B%D0%B9-%D0%BA%D0%BE%D0%BD%D1%82%D1%80%D0%BE%D0%BB%D1%8C" target="_blank">Подробнее</a>'
        ];

        $options['mine_partner_line2'] = [
            'view' => 'line',
        ];

        return $options;
    }

    protected function getAllOrders($type, $api, $ordersIds, $page = 1, $perPage = 50, $orders = [])
    {
        try {
            $response = $api->getOrders($ordersIds, $page, $perPage);
        } catch (Exception $e) {
            $this->log($type, "Can't get orders (" . implode(', ', $ordersIds) . "):\r\n{$e->getMessage()}");
            return [];
        }

        if (!isset($response->total) || !isset($response->orders)) {
            $this->log($type, "Can't get orders (" . implode(', ', $ordersIds) . "): Unexpected response:\r\n" . print_r($response, true));
            return [];
        }

        $orders = array_merge($orders, (array) $response->orders);

        if ($response->total > count($orders)) {
            $orders = $this->getAllOrders($type, $api, $ordersIds, $page, $perPage, $orders);
        }

        return $orders;
    }

    protected function generateWebhookSignature($webhookData, $signatureKey)
    {
        return hash_hmac('sha256', $webhookData, $signatureKey);
    }

    protected function webhookHandler($orderData, $merchantId)
    {
        if (!isset($orderData->order_id) || !isset($orderData->status)) {
            die('Invalid webhook data');
        }

        $requestOrderId = pn_strip_input($orderData->order_id);

        if (strpos($requestOrderId, 'pr_in_') === 0) {
            $this->merchantWebhookHandler($requestOrderId, $orderData, $merchantId);
        } else {
            $this->paymerchantWebhookHandler($requestOrderId, $orderData, $merchantId);
        }
        die('OK');
    }

    protected function merchantWebhookHandler($requestOrderId, $orderData, $merchantId)
    {
        $order = $this->wpdb->get_row("SELECT id, status FROM {$this->wpdb->prefix}exchange_bids WHERE trans_in = '{$requestOrderId}' AND m_in = '{$merchantId}'");
        if (!$order) {
            $this->merchantLog("Order with \"trans_in\"=\"{$requestOrderId}\" not found. Webhook data:\r\n" . print_r($orderData, true));
            die('Order not found');
        }

        if (!in_array($order->status, ['new', 'techpay', 'coldpay'])) {
            $this->merchantLog("Order \"{$order->id}\" (\"trans_in\"=\"{$requestOrderId}\") has status \"{$order->status}\", but received webhook. Webhook data:\r\n" . print_r($orderData, true));
            die('Order status is invalid');
        }

        $this->merchantChangeOrderStatusIfNeeded($order->id, $orderData);
    }

    protected function paymerchantWebhookHandler($requestOrderId, $orderData, $paymerchantId)
    {
        $order = $this->wpdb->get_row("SELECT id, status, sum2c, sum2dc, sum2r, sum2 FROM {$this->wpdb->prefix}exchange_bids WHERE trans_out = '{$requestOrderId}' AND m_out = '{$paymerchantId}'");
        if (!$order) {
            $this->paymerchantLog("Order with \"trans_out\"=\"{$requestOrderId}\" not found. Webhook data:\r\n" . print_r($orderData, true));
            die('Order not found');
        }

        if (!in_array($order->status, ['coldsuccess', 'payouterror', 'verify'])) {
            $this->paymerchantLog("Order \"{$order->id}\" (\"trans_out\"=\"{$requestOrderId}\") has status \"{$order->status}\", but received webhook. Webhook data:\r\n" . print_r($orderData, true));
            die('Order status is invalid');
        }

        $this->paymerchantChangeOrderStatusIfNeeded($order, $orderData, $paymerchantId);
    }

    protected function merchantChangeOrderStatusIfNeeded($orderId, $orderData)
    {
        $orderDetails = get_data_merchant_for_id($orderId, []);
        $orderAmount = is_sum($orderDetails['sum'], 8);
        $actualOrderAmount = is_sum($orderData->inbound->amount, 8);

        if ($orderData->status == 'moderation') {
            $this->addAdminComment($this->getMerchantStatusDescription('moderation'), $orderId);
            $this->merchantLog("Order ({$orderId}): {$this->getMerchantStatusDescription('moderation')}");
            return;
        }

        $this->removeAdminCommentIfExists($this->getMerchantStatusDescription('moderation'), $orderId);

        if ($orderData->status == 'incoming_unconfirmed') {
            set_bid_status('coldpay', $orderId);

            $this->merchantLog("Order ({$orderId}) is waiting confirmation from the BTC network");
            return;
        }

        if ($orderData->status == 'error') {
            set_bid_status('error', $orderId);

            $this->addAdminComment($this->getMerchantStatusDescription($orderData->status), $orderId);
            $this->merchantLog("Error: Order ({$orderId}) {$this->getMerchantStatusDescription($orderData->status)}");
            return;
        }

        if ($orderData->status == 'canceled') {
            set_bid_status('delete', $orderId);

            $this->addAdminComment($this->getMerchantStatusDescription($orderData->status), $orderId);
            $this->merchantLog("Deleted: Order ({$orderId}) {$this->getMerchantStatusDescription($orderData->status)}");
            return;
        }

        if ($orderData->status == 'success') {
            if ($orderAmount == $actualOrderAmount) {
                set_bid_status('realpay', $orderId);
                $this->merchantLog("Order ({$orderId}) has been completed successfully");
            } else {
                set_bid_status('verify', $orderId, ['sum' => $actualOrderAmount]);
                $this->addAdminComment("Несоответствие суммы: сумма в заявке ({$orderAmount} {$orderData->inbound->currency}), полученная сумма ({$actualOrderAmount} {$orderData->inbound->currency})", $orderId);
                $this->merchantLog("Order ({$orderId}) was sent to verification, as the amount is incorrect: amount in order ({$orderAmount} {$orderData->inbound->currency}), received amount ({$actualOrderAmount} {$orderData->inbound->currency})");
            }

            return;
        }

        $this->merchantLog("Order ({$orderId}) has not yet been completed. Status: {$orderData->status}");
    }

    protected function paymerchantChangeOrderStatusIfNeeded($order, $orderData, $paymerchantId)
    {
        $paymerchantData = @get_paymerch_data($paymerchantId);

        $orderAmount = is_sum(is_paymerch_sum($order, $paymerchantData), 8);
        $actualOrderAmount = is_sum($orderData->outbound->amount, 8);

        $orderId = $order->id;
        $orderStatus = $order->status;

        $this->saveReceiptsIfExists($orderId, $orderData);

        if ($orderData->status == 'moderation') {
            $this->addAdminComment($this->getPaymerchantStatusDescription('moderation'), $orderId);
            $this->paymerchantLog("Order №\"{$orderId}\" is now checking by payment gateway and will be processed later", $orderId);
            return;
        }

        $this->removeAdminCommentIfExists($this->getPaymerchantStatusDescription('moderation'), $orderId);

        if ($orderData->status == 'success') {
            $allowedDifferencePercentageFewer = is_isset($paymerchantData, 'allowed_difference_percent_fewer');
            if ($allowedDifferencePercentageFewer == '') {
                $allowedDifferencePercentageFewer = 0.1;
            }

            if ($orderAmount != $actualOrderAmount && $this->isNotAllowedAmountDifference($orderAmount, $actualOrderAmount, $allowedDifferencePercentageFewer)) {
                set_bid_status('verify', $orderId);
                $this->addAdminComment("Несоответствие суммы: сумма в заявке ({$orderAmount} {$orderData->outbound->currency}), выплаченная сумма ({$actualOrderAmount} {$orderData->outbound->currency})", $orderId);
                $this->paymerchantLog("Order ({$orderId}) was sent to verification, as the amount is incorrect: amount in order ({$orderAmount} {$orderData->outbound->currency}), paid amount ({$actualOrderAmount} {$orderData->outbound->currency})");
            } else {
                set_bid_status('success', $orderId);
                $this->paymerchantLog("Order №\"{$orderId}\" has been completed successfully", $orderId);
            }

            return;
        }

        if ($orderStatus == 'payouterror') {
            return;
        }

        if (in_array($orderData->status, ['error', 'canceled'])) {
            $this->addAdminComment($this->getPaymerchantStatusDescription($orderData->status), $orderId);
            $this->setPayoutError($orderId);
            $this->paymerchantLog("Order №\"{$orderId}\" processing has been finished with an error. Status: {$orderData->status}", $orderId);
            return;
        }

        $this->paymerchantLog("Order №\"{$orderId}\" has not yet been completed. Status: {$orderData->status}", $orderId);
    }

    protected function saveReceiptsIfExists($orderId, $orderData)
    {
        if (!isset($orderData->details->payout_receipts) || empty($orderData->details->payout_receipts)) {
            return;
        }

        $receiptsDetails = '';
        foreach ($orderData->details->payout_receipts as $transactionId => $receiptData) {
            if (isset($receiptData->jpeg) && !empty($receiptData->jpeg)) {
                $receiptsDetails .= "{$receiptData->jpeg}";
            } elseif (isset($receiptData->pdf) && !empty($receiptData->pdf)) {
                $receiptsDetails .= "{$receiptData->pdf}";
            } else {
                continue;
            }

            $receiptsDetails .= " ({$transactionId}";

            if (isset($receiptData->amount) && !empty($receiptData->amount)) {
                $receiptsDetails .= ", {$receiptData->amount}";
            }

            $receiptsDetails .= "); ";
        }

        if (!empty($receiptsDetails)) {
            $this->addAdminComment("Чеки: {$receiptsDetails}", $orderId);
        }
    }

    protected function recalculateOrderAmountsIfNeeded($order, $direction, $amount, $actualAmount, $allowedDifferencePercentage, $type)
    {
        if ($amount == $actualAmount) {
            return $order;
        }

        if ($this->isNotAllowedAmountDifference($amount, $actualAmount, $allowedDifferencePercentage)) {
            throw new \Exception("Difference between initial and amount got from API is bigger than {$allowedDifferencePercentage}%. This is strange and can't process.");
        }

        $inboundCurrency = $this->wpdb->get_row("SELECT * FROM {$this->wpdb->prefix}currency WHERE id='{$order->currency_id_give}'");
        $outboundCurrency = $this->wpdb->get_row("SELECT * FROM {$this->wpdb->prefix}currency WHERE id='{$order->currency_id_get}'");

        $recalculatedParameters = [
            'vd1' => $inboundCurrency,
            'vd2' => $outboundCurrency,
            'direction' => $direction,
            'user_id' => $order->user_id,
            'post_sum' => is_sum($actualAmount, 8),
            'dej' => $type,
        ];
        $recalculatedParameters = apply_filters('get_calc_data_params', $recalculatedParameters, 'calculator');

        $recalculatedData = get_calc_data($recalculatedParameters);

        $parameters = [
            "sum{$type}" => $recalculatedData["sum{$type}"],
            "sum{$type}c" => $recalculatedData["sum{$type}c"],
            "sum{$type}r" => $recalculatedData["sum{$type}r"],
            "sum{$type}dc" => $recalculatedData["sum{$type}dc"],
        ];
        if ($type == 2) {
            $parameters["sum2t"] = $recalculatedData["sum2t"];
        }

        return update_bid_tb_array($order->id, $parameters, $order);
    }

    protected function isNotAllowedAmountDifference($amount, $actualAmount, $allowedDifferencePercentage)
    {
        $allowedDifferencePercentage = (float) $allowedDifferencePercentage;
        if ($allowedDifferencePercentage <= 0) {
            $allowedDifference = 0;
        } else {
            $allowedDifference = $amount / 100 * $allowedDifferencePercentage;
        }

        return ($allowedDifference < abs($amount - $actualAmount));
    }

    protected function getMerchantStatusDescription($status)
    {
        return isset($this->merchantStatusesMessages[$status]) ? $this->merchantStatusesMessages[$status] : '';
    }

    protected function getPaymerchantStatusDescription($status)
    {
        return isset($this->paymerchantStatusesMessages[$status]) ? $this->paymerchantStatusesMessages[$status] : '';
    }

    protected function setPayoutError($orderId)
    {
        send_paymerchant_error($orderId, __('Your payment is declined', 'pn'));
        update_bids_meta($orderId, 'ap_status', 0);
        update_bids_meta($orderId, 'ap_status_date', current_time('timestamp'));
        set_bid_status('payouterror', $orderId);
    }

    protected function merchantLog($text)
    {
        do_action('save_merchant_error', $this->name, $text);
    }

    protected function paymerchantLog($text, $orderId = '')
    {
        do_action('save_paymerchant_error', $this->name, $text, $orderId);
    }

    protected function lock($type = 'merchant')
    {
        $lock = fopen(ABSPATH . "wp-content/uploads/.{$this->name}" . ucfirst($type) . "Cron.lock", "w+");
        if (!$lock) {
            $this->log($type, "CRON can't set lock");
            return false;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $this->log($type, "CRON has started, but the previous process has not finished yet. It will restart later.");
            return false;
        }

        return true;
    }

    protected function setupFakeDataInSettingsUI()
    {
        if (!is_array($this->m_data)) {
            $this->m_data = [];
        }

        if (!isset($this->m_data['API_URL']) || !$this->m_data['API_URL']) {
            $this->m_data['API_URL'] = ' ';
        }

        if (!isset($this->m_data['ALLOW_ORDERS_UPDATE_IF_DIFFERENCE_PERCENT_FEWER']) || !$this->m_data['ALLOW_ORDERS_UPDATE_IF_DIFFERENCE_PERCENT_FEWER']) {
            $this->m_data['ALLOW_ORDERS_UPDATE_IF_DIFFERENCE_PERCENT_FEWER'] = 0.1;
        }
    }

    protected function addAdminComment($text, $orderId, $comments = null)
    {
        if ($this->isOldPremiumExchanger) {
            $this->addSubstringToAdminComment($text, $orderId, $comments);
        } else {
            $this->addToAdminCommentsList($text, $orderId, $comments);
        }
    }

    protected function removeAdminCommentIfExists($commentText, $orderId, $comments = null)
    {
        if ($this->isOldPremiumExchanger) {
            $this->removeSubstringFromAdminCommentIfExists($commentText, $orderId, $comments);
        } else {
            $this->removeFromAdminCommentsListIfExists($commentText, $orderId, $comments);
        }
    }

    /* For premiumExchanger versions 2.1 or higher */
    private function getAdminCommentsList($orderId)
    {
        $comments = $this->wpdb->get_results(
            "SELECT id, text_comment FROM {$this->wpdb->prefix}comment_system WHERE item_id='{$orderId}' AND itemtype='admin_bid'",
            ARRAY_A
        );
        return array_column($comments, 'text_comment', 'id');
    }

    /* For premiumExchanger versions 2.1 or higher */
    private function addToAdminCommentsList($text, $orderId, $commentsList = [])
    {
        if ($this->findInAdminCommentsList($text, $orderId, $commentsList) !== false) {
            return;
        }

        $this->wpdb->insert(
            "{$this->wpdb->prefix}comment_system",
            [
                'comment_date' => current_time('mysql'),
                'user_id' => 0,
                'user_login' => 'MINE Partners',
                'text_comment' => $text,
                'itemtype' => 'admin_bid',
                'item_id' => $orderId,
            ]
        );
    }

    /* For premiumExchanger versions 2.1 or higher */
    private function findInAdminCommentsList($needleCommentText, $orderId, $commentsList = [])
    {
        if (empty($commentsList)) {
            $commentsList = $this->getAdminCommentsList($orderId);
        }

        return array_search($needleCommentText, $commentsList);
    }

    /* For premiumExchanger versions 2.1 or higher */
    private function removeFromAdminCommentsList($commentId)
    {
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}comment_system WHERE itemtype = 'admin_bid' AND id = '{$commentId}'");
    }

    /* For premiumExchanger versions 2.1 or higher */
    private function removeFromAdminCommentsListIfExists($needleCommentText, $orderId, $commentsList = [])
    {
        if (empty($commentsList)) {
            $commentsList = $this->getAdminCommentsList($orderId);
        }

        $adminCommentId = $this->findInAdminCommentsList($needleCommentText, $orderId, $commentsList);
        if ($adminCommentId !== false) {
            $this->removeFromAdminCommentsList($adminCommentId);
        }
    }

    /* For premiumExchanger version 2.0 */
    private function getAdminComment($orderId)
    {
        return trim(get_bids_meta($orderId, 'comment_admin'));
    }

    /* For premiumExchanger version 2.0 */
    private function addSubstringToAdminComment($substring, $orderId, $adminComment = '')
    {
        if ($this->substringExistsInAdminComment($substring, $orderId, $adminComment) !== false) {
            return;
        }

        update_bids_meta($orderId, 'comment_admin', "{$adminComment}\r\n{$substring}");
    }

    /* For premiumExchanger version 2.0 */
    private function substringExistsInAdminComment($substringToCheck, $orderId, $adminComment = '')
    {
        if (!$adminComment) {
            $adminComment = $this->getAdminComment($orderId);
        }

        return strpos($adminComment, $substringToCheck);
    }

    /* For premiumExchanger version 2.0 */
    private function removeSubstringFromAdminComment($substringToRemove, $orderId, $adminComment = '')
    {
        if (!$adminComment) {
            $adminComment = $this->getAdminComment($orderId);
        }

        update_bids_meta(
            $orderId,
            'comment_admin',
            str_replace($substringToRemove, '', $adminComment)
        );
    }

    /* For premiumExchanger version 2.0 */
    private function removeSubstringFromAdminCommentIfExists($substringToRemove, $orderId, $adminComment = '')
    {
        if (!$adminComment) {
            $adminComment = $this->getAdminComment($orderId);
        }

        if ($this->substringExistsInAdminComment($substringToRemove, $orderId, $adminComment) !== false) {
            $this->removeSubstringFromAdminComment($orderId, $substringToRemove, $adminComment);
        }
    }
}
