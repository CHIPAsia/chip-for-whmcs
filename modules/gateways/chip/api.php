<?php

if (!defined('CHIP_MODULE_VERSION')) {
  define('CHIP_MODULE_VERSION', '1.7.0');
}

class ChipAPI
{
  private static $_instance;

  private $brand_id;
  private $private_key;
  private $client;

  public static function get_instance($secret_key, $brand_id)
  {
    if (self::$_instance == null) {
      self::$_instance = new self($secret_key, $brand_id);
    }

    return self::$_instance;
  }

  public function __construct($private_key, $brand_id)
  {
    $this->private_key = $private_key;
    $this->brand_id = $brand_id;
    $this->client = new \GuzzleHttp\Client([
      'base_uri' => 'https://gate.chip-in.asia/api/v1/',
      'headers' => [
        'Content-type' => 'application/json',
        'Authorization' => 'Bearer ' . $this->private_key,
      ],
      'timeout' => 30,
    ]);
  }

  public function create_payment($params)
  {
    return $this->call('POST', 'purchases/', $params);
  }

  public function charge_payment($payment_id, $params)
  {
    return $this->call('POST', "purchases/{$payment_id}/charge/", $params);
  }

  public function payment_methods($currency)
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

  public function payment_recurring_methods($currency)
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

  public function get_payment($payment_id)
  {
    return $this->call('GET', "purchases/{$payment_id}/");
  }

  public function create_client($params)
  {
    return $this->call('POST', "clients/", $params);
  }

  public function get_client_by_email($email)
  {
    return $this->call('GET', "clients/", ['q' => $email]);
  }

  public function patch_client($client_id, $params)
  {
    return $this->call('PATCH', "clients/{$client_id}/", $params);
  }

  public function delete_token($purchase_id)
  {
    return $this->call('POST', "purchases/$purchase_id/delete_recurring_token/");
  }

  public function refund_payment($payment_id, $params)
  {
    return $this->call('POST', "purchases/{$payment_id}/refund/", $params);
  }

  public function public_key()
  {
    return $this->call('GET', "public_key/");
  }

  public function account_balance()
  {
    return $this->call('GET', 'account/json/balance/', ['brand_id' => $this->brand_id]);
  }

  private function call($method, $route, $params = [])
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
      $result = json_decode($response->getBody()->getContents(), true);

      if (!$result || !empty($result['errors'])) {
        return null;
      }

      return $result;
    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
      logActivity('CHIP API Error: ' . $e->getMessage());
      return null;
    }
  }
}
