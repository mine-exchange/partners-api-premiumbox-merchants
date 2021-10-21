<?php
if (!extension_loaded('curl')) {
    throw new \Exception('cURL extension seems not to be installed');
}

if(!class_exists('MinePartnersApi')):
class MinePartnersApi
{
    private $apiUrl = 'https://orders.minegate.io/api';

    private $apiAuthKey;

    private $apiSignatureKey;

    private $merchantId;

    private $merchantName;

    public function __construct($apiUrl, $apiAuthKey, $apiSignatureKey, $merchantId, $merchantName)
    {
        if (!empty($apiUrl)) {
            $this->apiUrl = rtrim($apiUrl, '/');
        }
        $this->apiAuthKey = trim($apiAuthKey);
        $this->apiSignatureKey = trim($apiSignatureKey);

        $this->merchantId = $merchantId;
        $this->merchantName = $merchantName;
    }

    public function createOrders($orders)
    {
        return $this->request('orders', ['orders' => $orders], 'POST');
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

    private function request($endpoint, $parameters, $method = 'GET')
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

        $headers = [
            "Content-Type: application/json",
            "Accept: application/json",
            "Authorization: Bearer {$this->apiAuthKey}",
            "X-Signature: {$signature}",
        ];

        curl_setopt_array($curl, [
            CURLOPT_URL => $requestUri ,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $errors  = curl_errno($curl);
        $content = curl_exec($curl);

        curl_close($curl);

        $response = json_decode($content);

        do_action('save_merchant_error', $this->merchantName, $this->merchantId, $requestUri, $headers, $parameters, $response, $errors);

        if (!isset($response->success) || !$response->success || !isset($response->data)) {
            throw new Exception("Endpoint: [{$method}] {$requestUri}\r\nRequest parameters:" . json_encode($parameters, JSON_PRETTY_PRINT) . "\r\nResponse:" . json_encode($response, JSON_PRETTY_PRINT));
        }

        return $response->data;
    }
}

endif;