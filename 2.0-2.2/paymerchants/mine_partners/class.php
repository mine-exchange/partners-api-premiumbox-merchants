<?php
if (!extension_loaded('curl')) {
    throw new \Exception('cURL extension seems not to be installed');
}

if(!class_exists('AP_MinePartnersApi')):
class AP_MinePartnersApi
{
    private $apiUrl = 'https://orders.minegate.io/api';

    private $apiAuthKey;

    private $apiSignatureKey;

    public function __construct($apiUrl, $apiAuthKey, $apiSignatureKey)
    {
        if (!empty($apiUrl)) {
            $this->apiUrl = rtrim($apiUrl, '/');
        }
        $this->apiAuthKey = trim($apiAuthKey);
        $this->apiSignatureKey = trim($apiSignatureKey);
    }

    public function createOrders($orders)
    {
        return $this->request('orders', ['orders' => $orders], 'POST');
    }

    public function confirmOrders($orderIds)
    {
        return $this->request('orders', ['ids' => $orderIds], 'PUT');
    }

    public function getOrders($ordersIds, $page = 1, $perPage = 50)
    {
        return $this->request(
            'orders',
            [
                'ids' => implode(',', $ordersIds),
                'page' => $page,
                'per_page' => $perPage
            ]
        );
    }

    public function getBalance($currency)
    {
        $currency = strtoupper($currency);

        $response = $this->request('balances');

        if (!isset($response->$currency)) {
            throw new Exception("Currency \"{$currency}\" not supported");
        }

        return (float) $response->$currency;
    }

    private function request($endpoint, $parameters = [], $method = 'GET')
    {
        $requestUri = "{$this->apiUrl}/{$endpoint}";

        $curl = curl_init();

        if (empty($parameters)) {
            $requestParameters = '';
        } elseif (in_array($method, ['POST', 'PUT'])) {
            $requestParameters = json_encode($parameters);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $requestParameters);

            if ($method == 'PUT') {
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            }
        } else {
            $requestUri .= "?" . http_build_query($parameters);
            $requestParameters = '';
        }

        $signature = hash_hmac('sha256', $requestParameters, $this->apiSignatureKey);

        curl_setopt_array($curl, [
            CURLOPT_URL => $requestUri ,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Accept: application/json",
                "Authorization: Bearer {$this->apiAuthKey}",
                "X-Signature: {$signature}",
            ]
        ]);

        $content = curl_exec($curl);

        $response = json_decode($content);

        if (!isset($response->success) || !$response->success || !isset($response->data)) {
            throw new Exception(
                "Endpoint: {$endpoint}\r\nRequest parameters:" . print_r($parameters, true) . "\r\nResponse:" . print_r($response, true)
            );
        }

        return $response->data;
    }
}

endif;