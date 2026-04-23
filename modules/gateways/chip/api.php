<?php

declare(strict_types=1);

if (!defined('CHIP_MODULE_VERSION')) {
    define('CHIP_MODULE_VERSION', '1.7.1');
}

require_once __DIR__ . '/exceptions.php';

class ChipAPI
{
    private static $_instance;

    private $brand_id;
    private $private_key;
    private $client;

    public static function get_instance(string $secret_key, string $brand_id): self
    {
        if (self::$_instance == null) {
            self::$_instance = new self($secret_key, $brand_id);
        }

        return self::$_instance;
    }

    public function __construct(string $private_key, string $brand_id)
    {
        $this->private_key = $private_key;
        $this->brand_id = $brand_id;
        $this->client = new \GuzzleHttp\Client([
            'base_uri' => 'https://gate.chip-in.asia/api/v1/',
            'headers' => [
                'Content-type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->private_key,
            ],
            'timeout' => 10,
        ]);
    }

    public function create_payment(array $params): ?array
    {
        return $this->call('POST', 'purchases/', $params);
    }

    public function charge_payment(string $payment_id, array $params): ?array
    {
        return $this->call('POST', "purchases/{$payment_id}/charge/", $params);
    }

    public function payment_methods(string $currency): ?array
    {
        return $this->call(
            'GET',
            "payment_methods/",
            [
                'brand_id' => $this->brand_id,
                'currency' => $currency,
                'amount' => 1000
            ]
        );
    }

    public function payment_recurring_methods(string $currency): ?array
    {
        return $this->call(
            'GET',
            "payment_methods/",
            [
                'brand_id' => $this->brand_id,
                'currency' => $currency,
                'amount' => 200,
                'recurring' => 'true'
            ]
        );
    }

    public function get_payment(string $payment_id): ?array
    {
        return $this->call('GET', "purchases/{$payment_id}/");
    }

    public function create_client(array $params): ?array
    {
        return $this->call('POST', "clients/", $params);
    }

    public function get_client_by_email(string $email): ?array
    {
        return $this->call('GET', "clients/", ['q' => $email]);
    }

    public function patch_client(string $client_id, array $params): ?array
    {
        return $this->call('PATCH', "clients/{$client_id}/", $params);
    }

    public function delete_token(string $purchase_id): ?array
    {
        return $this->call('POST', "purchases/$purchase_id/delete_recurring_token/");
    }

    public function refund_payment(string $payment_id, array $params): ?array
    {
        return $this->call('POST', "purchases/{$payment_id}/refund/", $params);
    }

    public function public_key(): ?string
    {
        return $this->call('GET', "public_key/");
    }

    public function account_balance(): ?array
    {
        return $this->call('GET', 'account/json/balance/', ['brand_id' => $this->brand_id]);
    }

    private function call(string $method, string $route, array $params = [])
    {
        try {
            $options = [];
            if (!empty($params)) {
                if ($method === 'GET') {
                    $options['query'] = $params;
                } else {
                    $options['json'] = $params;
                }
            }

            $response = $this->client->request($method, $route, $options);
            $body = $response->getBody()->getContents();

            if ($route === 'public_key/') {
                return $body;
            }

            $result = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ChipAPIException('Invalid JSON response from CHIP API');
            }

            if (!empty($result['errors'])) {
                throw new ChipAPIException('CHIP API Error: ' . json_encode($result['errors']), $response->getStatusCode(), $result);
            }

            return $result;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $result = json_decode($body, true);

            if ($statusCode === 401) {
                throw new ChipAPIException('Invalid API Key or Unauthorized access', 401, $result, $e);
            } elseif ($statusCode === 404) {
                throw new ChipAPIException('Resource not found', 404, $result, $e);
            }

            throw new ChipAPIException('CHIP API Client Error: ' . $e->getMessage(), $statusCode, $result, $e);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            \logActivity('CHIP API Network Error: ' . $e->getMessage());
            throw new ChipAPIException('CHIP API Network Error: ' . $e->getMessage(), 0, null, $e);
        }
    }
}
