<?php

use WHMCS\Database\Capsule;

class ChipHelpers
{
  public static function get_config_params($gateway_name, $friendly_name, $params = [])
  {
    $list_time_zones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
    $formatted_time_zones = array();
    foreach ($list_time_zones as $mtz) {
      $formatted_time_zones[$mtz] = str_replace("_", " ", $mtz);
    }

    $show_whitelist_option = false;
    $show_force_token_option = false;
    $available_payment_method = array();

    if (!empty($params['secretKey']) && !empty($params['brandId'])) {
      $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
      
      // For specific gateways, we might want to filter or default whitelists
      $result = $chip->payment_methods('MYR');

      if (is_array($result) && array_key_exists('available_payment_methods', $result) && !empty($result['available_payment_methods'])) {
        foreach ($result['available_payment_methods'] as $apm) {
          if ($apm == 'razer') {
            continue;
          }

          $default = 'no';
          
          // Logic for specific gateway defaults
          if ($gateway_name == 'chip_cards' && in_array($apm, ['maestro', 'mastercard', 'visa'])) {
            $default = 'yes';
          } elseif ($gateway_name == 'chip_fpx' && $apm == 'fpx') {
            $default = 'yes';
          } elseif ($gateway_name == 'chip_fpxb2b1' && $apm == 'fpx_b2b1') {
            $default = 'yes';
          } elseif ($gateway_name == 'chip_dnqr' && $apm == 'duitnow_qr') {
            $default = 'yes';
          } elseif ($gateway_name == 'chip_ewallets' && in_array($apm, ['razer_atome', 'razer_grabpay', 'razer_maybankqr', 'razer_shopeepay', 'razer_tng'])) {
            $default = 'yes';
          } elseif ($gateway_name == 'chip_crypto_coin' && $apm == 'crypto_coin') {
            $default = 'yes';
          }

          $friendly_apm = str_replace('_', ' ', $apm);
          $friendly_apm = ucwords($friendly_apm);
          $friendly_apm = str_replace(['Fpx', 'B2b1', 'Qr'], ['FPX', 'B2B1', 'QR'], $friendly_apm);
          $friendly_apm = str_replace(['Razer ', 'Mpgs '], '', $friendly_apm);

          $description = 'Tick to enable ' . $friendly_apm;
          if ($default == 'yes') {
            $description .= ' (Default)';
          }

          $available_payment_method['payment_method_whitelist__' . $apm] = array(
            'FriendlyName' => 'Whitelist ' . $friendly_apm,
            'Type' => 'yesno',
            'Default' => $default,
            'Description' => $description,
          );
        }
        $show_whitelist_option = true;
      }

      $recurring_result = $chip->payment_recurring_methods('MYR');
      if (is_array($recurring_result) && array_key_exists('available_payment_methods', $recurring_result) && !empty($recurring_result['available_payment_methods'])) {
        $show_force_token_option = true;
      }
    }

    $config_params = array(
      'FriendlyName' => array(
        'Type' => 'System',
        'Value' => $friendly_name,
      ),
      'brandId' => array(
        'FriendlyName' => 'Brand ID',
        'Type' => 'text',
        'Size' => '25',
        'Default' => '',
        'Description' => 'Enter your Brand ID here',
      ),
      'secretKey' => array(
        'FriendlyName' => 'Secret Key',
        'Type' => 'text',
        'Size' => '25',
        'Default' => '',
        'Description' => 'Enter secret key here',
      ),
      'paymentInformation' => array(
        'FriendlyName' => 'Payment Information',
        'Type' => 'textarea',
        'Rows' => '5',
        'Description' => 'This information will be displayed on the payment page.'
      ),
      'dueStrict' => array(
        'FriendlyName' => 'Due Strict',
        'Type' => 'yesno',
        'Description' => 'Tick to enforce due strict payment timeframe',
        'Default' => 'on',
      ),
      'dueStrictTiming' => array(
        'FriendlyName' => 'Due Strict Timing',
        'Type' => 'text',
        'Size' => '3',
        'Default' => '60',
        'Description' => 'Enter due strict timing. Default 60 for 1 hour.',
      ),
      'purchaseSendReceipt' => array(
        'FriendlyName' => 'Purchase Send Receipt',
        'Type' => 'yesno',
        'Description' => 'Tick to ask CHIP to send receipt upon successful payment.',
        'Default' => 'on',
      ),
      'purchaseTimeZone' => array(
        'FriendlyName' => 'Time zone',
        'Type' => 'dropdown',
        'Description' => 'Time zone setting for receipt page.',
        'Default' => 'Asia/Kuala_Lumpur',
        'Options' => $formatted_time_zones
      ),
      'updateClientInfo' => array(
        'FriendlyName' => 'Update client information',
        'Type' => 'yesno',
        'Description' => 'Tick to update client information on purchase creation.',
        'Default' => 'on',
      ),
      'systemUrlHttps' => array(
        'FriendlyName' => 'System URL Mode',
        'Type' => 'dropdown',
        'Description' => 'Choose https if you are facing issue with payment status update due to http to https redirection',
        'Options' => array(
          'default' => 'System Default',
          'https' => 'Force HTTPS',
        )
      ),
    );

    if ($show_force_token_option) {
      $config_params['forceTokenization'] = array(
        'FriendlyName' => 'Force Tokenization',
        'Type' => 'yesno',
        'Description' => 'Tick to force tokenization for card payment.',
      );
    }

    if ($show_whitelist_option) {
      $config_params['paymentWhitelist'] = array(
        'FriendlyName' => 'Payment Method Whitelisting',
        'Type' => 'yesno',
        'Description' => 'Tick to enforce payment method whitelisting.',
      );
      $config_params += $available_payment_method;
    }

    return $config_params;
  }

  public static function get_whitelisted_methods($params)
  {
    if ($params['paymentWhitelist'] != 'on') {
      return [];
    }

    $keys = array_keys($params);
    $result = preg_grep('/payment_method_whitelist__.*/', $keys);

    $configured_payment_methods = array();
    foreach ($result as $key) {
      if ($params[$key] == 'on') {
        $key_array = explode('__', $key);
        $configured_payment_methods[] = end($key_array);
      }
    }

    return $configured_payment_methods;
  }
}
