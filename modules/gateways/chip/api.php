<?php

define("ROOT_URL", "https://gate.chip-in.asia");

class ChipAPI
{
  private static $_instance;
  
  public static function get_instance($secret_key, $brand_id) {

    if ( self::$_instance == null ) {
      self::$_instance = new ChipAPI($secret_key, $brand_id);
    }

    return self::$_instance;
  }
  
  public function __construct($private_key, $brand_id)
  {
    $this->private_key = $private_key;
    $this->brand_id    = $brand_id;
  }

  public function create_payment($params)
  {
    return $this->call('POST', '/purchases/', $params);
  }

  public function payment_methods($currency, $language)
  {
    return $this->call(
      'GET',
      "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
    );
  }

  public function get_payment($payment_id)
  {
    $result = $this->call('GET', "/purchases/{$payment_id}/");
    return $result;
  }

  public function was_payment_successful($payment_id)
  {
    $result = $this->get_payment($payment_id);
    return $result && $result['status'] == 'paid';
  }

  public function refund_payment($payment_id, $params)
  {
    $result = $this->call('POST', "/purchases/{$payment_id}/refund/", $params);

    return $result;
  }

  public function public_key()
  {
    $result = $this->call('GET', "/public_key/");
    
    return $result;
  }

  private function call($method, $route, $params = [])
  {
    $private_key = $this->private_key;
    if (!empty($params)) {
      $params = json_encode($params);
    }

    $response = $this->request(
      $method,
      sprintf("%s/api/v1%s", ROOT_URL, $route),
      $params,
      [
        'Content-type: application/json',
        'Authorization: ' . "Bearer " . $private_key,
      ]
    );

    $result = json_decode($response, true);
    if (!$result) {
      return null;
    }

    if (!empty($result['errors'])) {
      return null;
    }

    return $result;
  }

  private function request($method, $url, $params = [], $headers = [])
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    
    if ($method == 'POST') {
      curl_setopt($ch, CURLOPT_POST, 1);
    }

    if ($method == 'PUT') {
      curl_setopt($ch, CURLOPT_PUT, 1);
    }

    if ($method == 'PUT' or $method == 'POST') {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    
    curl_close($ch);
    
    return $response;
  }
}
