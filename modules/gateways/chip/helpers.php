<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class ChipHelpers
{
    /**
     * Single source of truth for admin-tickbox alias groups.
     *
     * Each group renders as one tickbox in the admin UI, but expands to
     * one or more raw CHIP `payment_method` values at emit time. The
     * `expand` key controls resolution:
     *   - 'static':    emit each member that the merchant's brand supports
     *   - 'preferred': see expand_whitelist_aliases() for resolution rules
     *
     * @return array<string, array{label: string, members: list<string>, expand: string}>
     */
    private static function whitelist_alias_groups(): array
    {
        return [
            'card' => [
                'label' => 'Card',
                'members' => ['visa', 'mastercard', 'maestro'],
                'expand' => 'static',
            ],
            'duitnow_qr' => [
                'label' => 'DuitNow QR',
                'members' => ['dnqr', 'duitnow_qr'],
                'expand' => 'preferred',
            ],
            'shopee_pay' => [
                'label' => 'Shopee Pay',
                'members' => ['razer_shopeepay', 'shopee_pay'],
                'expand' => 'preferred',
            ],
        ];
    }

    /**
     * Expand a list of ticked config keys into the raw CHIP payment_method
     * values to send in `payment_method_whitelist`. Unknown keys are
     * passed through unchanged. Result is deduplicated, preserving the
     * order in which values first appear.
     *
     * @param list<string> $ticked Config-key tails (e.g. ['card', 'fpx_b2b1'])
     * @param list<string> $merchantAvailable The merchant's available payment methods
     *                                        (from /payment_methods/), used to
     *                                        resolve 'preferred' groups
     * @return list<string>
     */
    public static function expand_whitelist_aliases(array $ticked, array $merchantAvailable): array
    {
        $available = array_flip($merchantAvailable);
        $groups = self::whitelist_alias_groups();
        $out = [];

        foreach ($ticked as $key) {
            if (!isset($groups[$key])) {
                $out[] = $key;

                continue;
            }

            $group = $groups[$key];

            if ($group['expand'] === 'static') {
                foreach ($group['members'] as $member) {
                    if (isset($available[$member])) {
                        $out[] = $member;
                    }
                }

                continue;
            }

            // 'preferred' resolution
            if ($key === 'duitnow_qr') {
                if (isset($available['dnqr']) && isset($available['duitnow_qr'])) {
                    $out[] = 'dnqr';
                } elseif (isset($available['dnqr'])) {
                    $out[] = 'dnqr';
                } elseif (isset($available['duitnow_qr'])) {
                    $out[] = 'duitnow_qr';
                }

                continue;
            }

            if ($key === 'shopee_pay') {
                if (isset($available['shopee_pay'])) {
                    $out[] = 'shopee_pay';
                } elseif (isset($available['razer_shopeepay'])) {
                    $out[] = 'razer_shopeepay';
                }

                continue;
            }

            // Future 'preferred' groups fall through to a static-membership
            // emit, which preserves the original 'preferred' semantic: emit
            // any member the merchant's brand supports.
            foreach ($group['members'] as $member) {
                if (isset($available[$member])) {
                    $out[] = $member;
                }
            }
        }

        return array_values(array_unique($out));
    }

    public static function get_config_params($gateway_name, $friendly_name, $params = [])
    {
        $list_time_zones = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
        $formatted_time_zones = [];
        foreach ($list_time_zones as $mtz) {
            $formatted_time_zones[$mtz] = str_replace("_", " ", $mtz);
        }

        $show_whitelist_option = false;
        $show_force_token_option = false;
        $available_payment_method = [];

        if (!empty($params['secretKey']) && !empty($params['brandId'])) {
            try {
                $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);

                $base_currency = Capsule::table('tblcurrencies')->where('default', '1')->first();
                $currency_code = $base_currency ? $base_currency->code : 'MYR';

                $convertto = Capsule::table('tblpaymentgateways')
                    ->where('gateway', $gateway_name)
                    ->where('setting', 'convertto')
                    ->first();

                if ($convertto && $convertto->value) {
                    $convertto_currency = Capsule::table('tblcurrencies')->where('id', $convertto->value)->first();
                    if ($convertto_currency) {
                        $currency_code = $convertto_currency->code;
                    }
                }

                // For specific gateways, we might want to filter or default whitelists
                $result = $chip->payment_methods($currency_code);

                if (is_array($result) && array_key_exists('available_payment_methods', $result) && !empty($result['available_payment_methods'])) {
                    $categories = [
                        'Cards' => ['visa', 'mastercard', 'maestro', 'mpgs_apple_pay', 'mpgs_google_pay'],
                        'FPX' => ['fpx', 'fpx_b2b1'],
                        'E-Wallets & QR' => ['razer_atome', 'razer_grabpay', 'razer_maybankqr', 'razer_shopeepay', 'razer_tng', 'duitnow_qr', 'dnqr'],
                        'Crypto' => ['crypto_coin'],
                    ];

                    $alias_groups = self::whitelist_alias_groups();
                    $alias_member_to_group = [];
                    foreach ($alias_groups as $group_key => $group) {
                        foreach ($group['members'] as $member) {
                            $alias_member_to_group[$member] = $group_key;
                        }
                    }

                    $methods_by_category = [];
                    foreach ($result['available_payment_methods'] as $apm) {
                        if ($apm == 'razer') {
                            continue;
                        }

                        $found_cat = 'Others';
                        foreach ($categories as $cat => $members) {
                            if (in_array($apm, $members)) {
                                $found_cat = $cat;

                                break;
                            }
                        }
                        $methods_by_category[$found_cat][] = $apm;
                    }

                    foreach ($methods_by_category as $category => $apms) {
                        $is_first_in_cat = true;
                        foreach ($apms as $apm) {
                            // Skip apms that are members of an alias group;
                            // the group is rendered once below.
                            if (isset($alias_member_to_group[$apm])) {
                                continue;
                            }

                            $default = 'no';

                            // Logic for specific gateway defaults
                            if ($gateway_name == 'chip_fpx' && $apm == 'fpx') {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_fpxb2b1' && $apm == 'fpx_b2b1') {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_ewallets' && in_array($apm, ['razer_atome', 'razer_grabpay', 'razer_maybankqr', 'razer_tng'])) {
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

                            $friendly_name_label = 'Whitelist ' . $friendly_apm;
                            if ($is_first_in_cat) {
                                $friendly_name_label = '<b>[' . strtoupper($category) . ']</b><br/>' . $friendly_name_label;
                                $is_first_in_cat = false;
                            }

                            $available_payment_method['payment_method_whitelist__' . $apm] = [
                                'FriendlyName' => $friendly_name_label,
                                'Type' => 'yesno',
                                'Default' => $default,
                                'Description' => $description,
                            ];
                        }
                    }

                    // Render one tickbox per alias group that has at least
                    // one member present in the merchant's available list.
                    // Render groups in the same order the categories appear
                    // by walking the original available list in order.
                    $rendered_groups = [];
                    foreach ($result['available_payment_methods'] as $apm) {
                        if (!isset($alias_member_to_group[$apm])) {
                            continue;
                        }
                        $group_key = $alias_member_to_group[$apm];
                        if (isset($rendered_groups[$group_key])) {
                            continue;
                        }
                        $rendered_groups[$group_key] = true;

                        $group = $alias_groups[$group_key];
                        $default = 'no';

                        if ($gateway_name == 'chip_cards' && $group_key === 'card') {
                            $default = 'yes';
                        } elseif ($gateway_name == 'chip_dnqr' && $group_key === 'duitnow_qr') {
                            $default = 'yes';
                        } elseif ($gateway_name == 'chip_ewallets' && $group_key === 'shopee_pay') {
                            $default = 'yes';
                        }

                        $available_payment_method['payment_method_whitelist__' . $group_key] = [
                            'FriendlyName' => 'Whitelist ' . $group['label'],
                            'Type' => 'yesno',
                            'Default' => $default,
                            'Description' => 'Tick to enable ' . $group['label']
                                . ($default === 'yes' ? ' (Default)' : ''),
                        ];
                    }
                    $show_whitelist_option = true;
                }

                $recurring_result = $chip->payment_recurring_methods($currency_code);
                if (is_array($recurring_result) && array_key_exists('available_payment_methods', $recurring_result) && !empty($recurring_result['available_payment_methods'])) {
                    $show_force_token_option = true;
                }
            } catch (Exception $e) {
                \logActivity('CHIP Config Error: ' . $e->getMessage());
            }
        }

        $config_params = [
            'FriendlyName' => [
                'Type' => 'System',
                'Value' => $friendly_name,
            ],
            'brandId' => [
                'FriendlyName' => 'Brand ID',
                'Type' => 'text',
                'Size' => '25',
                'Default' => '',
                'Description' => 'Enter your Brand ID here',
            ],
            'secretKey' => [
                'FriendlyName' => 'Secret Key',
                'Type' => 'text',
                'Size' => '25',
                'Default' => '',
                'Description' => 'Enter secret key here',
            ],
            'paymentInformation' => [
                'FriendlyName' => 'Payment Information',
                'Type' => 'textarea',
                'Rows' => '5',
                'Description' => 'This information will be displayed on the payment page.',
            ],
            'dueStrict' => [
                'FriendlyName' => 'Due Strict',
                'Type' => 'yesno',
                'Description' => 'Tick to enforce due strict payment timeframe',
                'Default' => 'on',
            ],
            'dueStrictTiming' => [
                'FriendlyName' => 'Due Strict Timing',
                'Type' => 'text',
                'Size' => '3',
                'Default' => '60',
                'Description' => 'Enter due strict timing. Default 60 for 1 hour.',
            ],
            'purchaseTimeZone' => [
                'FriendlyName' => 'Time zone',
                'Type' => 'dropdown',
                'Description' => 'Time zone setting for receipt page.',
                'Default' => 'Asia/Kuala_Lumpur',
                'Options' => $formatted_time_zones,
            ],
            'systemUrlHttps' => [
                'FriendlyName' => 'System URL Mode',
                'Type' => 'dropdown',
                'Description' => 'Choose https if you are facing issue with payment status update due to http to https redirection',
                'Options' => [
                    'default' => 'System Default',
                    'https' => 'Force HTTPS',
                ],
            ],
            'disableRecurring' => [
                'FriendlyName' => 'Disable Recurring Payments',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Tick to prevent saving cards and reject charges using previously saved tokens.',
            ],
        ];

        if ($show_force_token_option) {
            $config_params['forceTokenization'] = [
                'FriendlyName' => 'Force Tokenization',
                'Type' => 'yesno',
                'Description' => 'Tick to force tokenization for card payment.',
            ];
        }

        if ($show_whitelist_option) {
            $config_params['paymentWhitelist'] = [
                'FriendlyName' => 'Payment Method Whitelisting',
                'Type' => 'yesno',
                'Description' => 'Tick to enforce payment method whitelisting.',
            ];
            $config_params += $available_payment_method;
        }

        return $config_params;
    }

    /**
     * Read the merchant's available payment methods from CHIP, used by the
     * emit step to resolve alias groups (Card / DuitNow QR / Shopee Pay)
     * against what the brand actually supports. Returns an empty list on
     * any failure so the expander degrades gracefully.
     *
     * @param string $secretKey
     * @param string $brandId
     * @param string $currency
     * @return list<string>
     */
    public static function fetch_merchant_available_methods(string $secretKey, string $brandId, string $currency): array
    {
        try {
            $chip = \ChipAPI::get_instance($secretKey, $brandId);
            $result = $chip->payment_methods($currency);
        } catch (Exception $e) {
            \logActivity('CHIP: failed to fetch merchant payment methods: ' . $e->getMessage());

            return [];
        }

        if (!is_array($result) || !isset($result['available_payment_methods']) || !is_array($result['available_payment_methods'])) {
            return [];
        }

        return array_values($result['available_payment_methods']);
    }

    public static function get_whitelisted_methods($params)
    {
        if ($params['paymentWhitelist'] != 'on') {
            return [];
        }

        $keys = array_keys($params);
        $result = preg_grep('/payment_method_whitelist__.*/', $keys);

        $ticked = [];
        foreach ($result as $key) {
            if ($params[$key] == 'on') {
                $key_array = explode('__', $key);
                $ticked[] = end($key_array);
            }
        }

        $merchantAvailable = self::fetch_merchant_available_methods(
            (string) ($params['secretKey'] ?? ''),
            (string) ($params['brandId'] ?? ''),
            (string) ($params['currency'] ?? 'MYR')
        );

        return self::expand_whitelist_aliases($ticked, $merchantAvailable);
    }
}
