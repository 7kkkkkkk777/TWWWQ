<?php

namespace CouponImporter\Providers;

if (!defined('ABSPATH')) {
    exit;
}

interface ProviderInterface {

    public function get_name();

    public function get_settings_fields();

    public function validate_settings($settings);

    public function test_connection($settings);

    public function get_coupons($settings, $limit = null);

    public function get_advertisers($settings);

    public function get_categories($settings);
}
