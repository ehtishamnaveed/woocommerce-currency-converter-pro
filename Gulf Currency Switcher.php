<?php
/**
 * Plugin Name: Gulf Currency Switcher (Auto Daily Rates)
 * Description: Automatically converts WooCommerce product prices to Gulf or custom currencies, updates rates daily via free API, and provides easy admin settings + usage guide.
 * Version: 2.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * =========================================================
 *  COUNTRY + CURRENCY DATA (REST COUNTRIES, SINGLE SOURCE)
 * =========================================================
 *
 * We fetch from restcountries.com ONCE (or when admin refreshes),
 * process it into a flat list:
 *
 * [
 *   [
 *     'common_name'   => 'Bhutan',
 *     'country_code'  => 'BT',
 *     'currency_code' => 'BTN',
 *     'flag_png'      => 'https://flagcdn.com/w320/bt.png',
 *     'flag_svg'      => 'https://flagcdn.com/bt.svg',
 *   ],
 *   [
 *     'common_name'   => 'Bhutan',
 *     'country_code'  => 'BT',
 *     'currency_code' => 'INR',
 *     'flag_png'      => 'https://flagcdn.com/w320/bt.png',
 *     'flag_svg'      => 'https://flagcdn.com/bt.svg',
 *   ],
 *   ...
 * ]
 *
 * This processed array is stored in option: gcs_countries_flat
 * and used in BOTH admin and frontend.
 */

/**
 * Fetch from REST Countries and store processed flat list.
 * Returns true on success, false on failure.
 */
function gcs_refresh_countries_cache() {
    $url = 'https://restcountries.com/v3.1/all?fields=name,flags,currencies,cca2';

    $response = wp_remote_get( $url, ['timeout' => 30] );
    if ( is_wp_error( $response ) ) {
        return false;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
        return false;
    }

    $flat = [];

    foreach ( $data as $country ) {
        $common_name  = $country['name']['common'] ?? '';
        $country_code = isset( $country['cca2'] ) ? strtoupper( $country['cca2'] ) : '';

        if ( ! $country_code ) {
            continue;
        }

        $flag_png = $country['flags']['png'] ?? '';
        $flag_svg = $country['flags']['svg'] ?? '';

        $currencies = $country['currencies'] ?? [];

        // One entry per currency (handles multi-currency countries like Bhutan)
        if ( ! empty( $currencies ) && is_array( $currencies ) ) {
            foreach ( $currencies as $cur_code => $cur_info ) {
                $cur_code = strtoupper( $cur_code );
                if ( ! $cur_code ) {
                    continue;
                }

                $flat[] = [
                    'common_name'   => $common_name,
                    'country_code'  => $country_code,
                    'currency_code' => $cur_code,
                    'flag_png'      => $flag_png,
                    'flag_svg'      => $flag_svg,
                ];
            }
        }
    }

    // Sort by country name then currency code
    usort( $flat, function( $a, $b ) {
        $name_cmp = strcmp( $a['common_name'], $b['common_name'] );
        if ( $name_cmp !== 0 ) return $name_cmp;
        return strcmp( $a['currency_code'], $b['currency_code'] );
    } );

    // Store processed list ONCE
    update_option( 'gcs_countries_flat', $flat );
    update_option( 'gcs_countries_last_update', time() );

    return true;
}

/**
 * Get processed country/currency list from option.
 * If empty, it will try to refresh from REST Countries.
 */
function gcs_get_countries_list() {
    $countries = get_option( 'gcs_countries_flat', [] );

    if ( empty( $countries ) ) {
        gcs_refresh_countries_cache();
        $countries = get_option( 'gcs_countries_flat', [] );
    }

    return is_array( $countries ) ? $countries : [];
}

// Backwards-compatible alias, if you still call gcs_fetch_countries() elsewhere.
function gcs_fetch_countries() {
    return gcs_get_countries_list();
}

/**
 * Convert a 2-letter country code to emoji flag.
 */
function gcs_country_code_to_emoji( $country_code ) {
    $country_code = strtoupper( $country_code );
    if ( strlen( $country_code ) !== 2 ) return '🏴';

    $flag = '';
    for ( $i = 0; $i < 2; $i++ ) {
        $flag .= mb_chr( ord( $country_code[ $i ] ) + 127397, 'UTF-8' );
    }
    return $flag;
}

/**
 * =========================================================
 *  DEFAULT SETTINGS & GENERAL CURRENCY SWITCHER LOGIC
 * =========================================================
 */

function gcs_default_settings() {
    $woo_currency = get_option( 'woocommerce_currency', 'USD' );
    return [
        'base_currency'      => $woo_currency,
        'update_hours'       => 24,
        'selected_countries' => [],
    ];
}

function gcs_get_settings() {
    $settings = get_option( 'gcs_settings', [] );
    $defaults = gcs_default_settings();
    $settings = wp_parse_args( $settings, $defaults );

    // Sync with WooCommerce default currency
    $woo_currency = get_option( 'woocommerce_currency', 'USD' );
    if ( $settings['base_currency'] !== $woo_currency ) {
        $settings['base_currency'] = $woo_currency;
        update_option( 'gcs_settings', $settings );
    }

    return $settings;
}

function gcs_api_url() {
    $settings       = gcs_get_settings();
    $base_currency  = $settings['base_currency'];
    return 'https://open.er-api.com/v6/latest/' . $base_currency;
}

/**
 * Fetch and cache exchange rates.
 */
function gcs_fetch_and_cache_rates() {
    $url      = gcs_api_url();
    $response = wp_remote_get( $url, ['timeout' => 30] );
    
    if ( is_wp_error( $response ) ) {
        return false;
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( isset( $data['result'] ) && $data['result'] === 'success' && isset( $data['rates'] ) ) {
        $cache_data = [
            'timestamp' => current_time( 'timestamp' ),
            'rates'     => $data['rates'],
        ];
        
        $settings = gcs_get_settings();
        set_transient( 'gcs_currency_rates', $cache_data, HOUR_IN_SECONDS * $settings['update_hours'] );
        return true;
    }
    
    return false;
}
add_action( 'gcs_refresh_daily_rates', 'gcs_fetch_and_cache_rates' );

/**
 * Activation / deactivation hooks
 */
function gcs_activate_schedule() {
    if ( ! wp_next_scheduled( 'gcs_refresh_daily_rates' ) ) {
        wp_schedule_event( time(), 'daily', 'gcs_refresh_daily_rates' );
    }

    // Fetch countries ONCE
    gcs_refresh_countries_cache();

    // Fetch rates immediately
    gcs_fetch_and_cache_rates();
}
register_activation_hook( __FILE__, 'gcs_activate_schedule' );

function gcs_deactivate_schedule() {
    wp_clear_scheduled_hook( 'gcs_refresh_daily_rates' );
}
register_deactivation_hook( __FILE__, 'gcs_deactivate_schedule' );

/**
 * Get cached currency rates, refreshing if needed.
 */
function gcs_get_currency_rates() {
    $cached = get_transient( 'gcs_currency_rates' );
    
    if ( ! $cached || empty( $cached['rates'] ) || ! is_array( $cached['rates'] ) ) {
        gcs_fetch_and_cache_rates();
        $cached = get_transient( 'gcs_currency_rates' );
    }
    
    return isset( $cached['rates'] ) ? $cached['rates'] : [];
}

/**
 * Get user's selected currency (from cookie), fallback to base currency.
 */
function gcs_get_user_currency() {
    $settings         = gcs_get_settings();
    $default_currency = $settings['base_currency'];
    
    if ( isset( $_COOKIE['gcs_selected_currency'] ) ) {
        $cookie_currency = strtoupper( sanitize_text_field( $_COOKIE['gcs_selected_currency'] ) );
        
        $rates = gcs_get_currency_rates();
        if ( isset( $rates[ $cookie_currency ] ) && $cookie_currency !== 'DEFAULT' ) {
            return $cookie_currency;
        }
    }
    
    return $default_currency;
}

/**
 * Actual price conversion.
 */
function gcs_convert_price( $price, $product = null ) {
    if ( ! is_numeric( $price ) || $price === '' ) {
        return $price;
    }
    
    $settings        = gcs_get_settings();
    $default_currency = $settings['base_currency'];
    $user_currency    = gcs_get_user_currency();
    
    if ( $user_currency === $default_currency ) {
        return $price;
    }
    
    $rates = gcs_get_currency_rates();
    if ( empty( $rates ) || ! isset( $rates[ $user_currency ] ) ) {
        return $price;
    }
    
    $rate      = (float) $rates[ $user_currency ];
    $converted = $price * $rate;
    
    return round( $converted, 2 );
}

/**
 * Apply WooCommerce price filters.
 */
function gcs_apply_price_filters() {
    add_filter( 'woocommerce_product_get_price', 'gcs_convert_price', 99, 2 );
    add_filter( 'woocommerce_product_get_regular_price', 'gcs_convert_price', 99, 2 );
    add_filter( 'woocommerce_product_get_sale_price', 'gcs_convert_price', 99, 2 );
    add_filter( 'woocommerce_product_variation_get_price', 'gcs_convert_price', 99, 2 );
    add_filter( 'woocommerce_product_variation_get_regular_price', 'gcs_convert_price', 99, 2 );
    add_filter( 'woocommerce_product_variation_get_sale_price', 'gcs_convert_price', 99, 2 );
    
    add_filter( 'woocommerce_variation_prices_price', 'gcs_convert_price', 99, 2 );
    add_filter( 'woocommerce_variation_prices_regular_price', 'gcs_convert_price', 99, 2 );
    add_filter( 'woocommerce_variation_prices_sale_price', 'gcs_convert_price', 99, 2 );
}
add_action( 'init', 'gcs_apply_price_filters' );

/**
 * Change WooCommerce currency symbol according to selected currency.
 */
function gcs_change_currency_symbol( $symbol, $currency ) {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return $symbol;
    }
    
    $settings      = gcs_get_settings();
    $user_currency = gcs_get_user_currency();
    
    if ( $user_currency !== $settings['base_currency'] ) {
        $symbols = get_woocommerce_currency_symbols();
        return isset( $symbols[ $user_currency ] ) ? $symbols[ $user_currency ] : $user_currency . ' ';
    }
    
    return $symbol;
}
add_filter( 'woocommerce_currency_symbol', 'gcs_change_currency_symbol', 99, 2 );

/**
 * Change WooCommerce currency code.
 */
function gcs_change_currency_code( $currency_code ) {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return $currency_code;
    }
    
    return gcs_get_user_currency();
}
add_filter( 'woocommerce_currency', 'gcs_change_currency_code', 99 );

/**
 * ==================================
 *  FRONTEND SHORTCODE SELECTOR
 * ==================================
 */
function gcs_currency_selector_shortcode() {
    $settings          = gcs_get_settings();
    $selected_countries = isset( $settings['selected_countries'] ) ? $settings['selected_countries'] : [];
    $woo_default       = get_option( 'woocommerce_currency', 'USD' );
    
    $rates            = gcs_get_currency_rates();
    $current_currency = gcs_get_user_currency();
    $is_default       = ( $current_currency === $settings['base_currency'] );

    ob_start();
    ?>
    <div class="gcs-currency-switcher">
        <select id="gcs-currency" class="gcs-currency-select">
            <option value="default" <?php echo $is_default ? 'selected' : ''; ?>>
                <?php echo esc_html( $woo_default ); ?>
            </option>
            <?php foreach ( $selected_countries as $country ) :
                $currency     = isset( $country['currency_code'] ) ? strtoupper( $country['currency_code'] ) : '';
                $country_code = isset( $country['country_code'] ) ? strtoupper( $country['country_code'] ) : '';
                $flag_png     = isset( $country['flag_png'] ) ? esc_url( $country['flag_png'] ) : '';
                
                if ( ! $currency || ! isset( $rates[ $currency ] ) ) {
                    continue;
                }

                $selected   = ( $current_currency === $currency ) ? 'selected' : '';
                $flag_emoji = $country_code ? gcs_country_code_to_emoji( $country_code ) : '🏴';
            ?>
                <option value="<?php echo esc_attr( $currency ); ?>" <?php echo $selected; ?> data-flag="<?php echo $flag_png; ?>" data-country-code="<?php echo esc_attr( $country_code ); ?>">
                    <?php 
                    // Display with emoji for now, we'll enhance with JS
                    echo esc_html( $flag_emoji . ' ' . $country_code . ' — ' . $currency ); 
                    ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <style>
    .gcs-currency-select {
        padding: 8px 12px;
        font-size: 14px;
        min-width: 180px;
        border: 1px solid #ddd;
        border-radius: 6px;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
        appearance: none;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-size: 16px;
        padding-right: 35px;
    }
    
    /* Style for flag images in dropdown */
    .gcs-currency-select option {
        padding: 8px 12px;
    }
    
    .gcs-flag-img {
        width: 20px;
        height: 14px;
        border-radius: 2px;
        object-fit: cover;
        margin-right: 8px;
        vertical-align: middle;
        display: inline-block;
    }
    
    .gcs-currency-select:hover {
        border-color: #999;
    }
    
    .gcs-currency-select:focus {
        outline: none;
        border-color: #007cba;
        box-shadow: 0 0 0 2px rgba(0,124,186,0.2);
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var select = document.getElementById('gcs-currency');
        if (!select) return;
        
        // Replace emoji flags with PNG flags (optional enhancement)
        for (var i = 0; i < select.options.length; i++) {
            var option = select.options[i];
            var flagUrl = option.getAttribute('data-flag');
            var countryCode = option.getAttribute('data-country-code');
            
            if (flagUrl && countryCode) {
                // Create flag image
                var flagImg = document.createElement('img');
                flagImg.src = flagUrl;
                flagImg.className = 'gcs-flag-img';
                flagImg.alt = countryCode + ' flag';
                
                // Get original text (remove emoji if present)
                var originalText = option.textContent;
                var textWithoutEmoji = originalText.replace(/[\u{1F600}-\u{1F6FF}]/gu, '').trim();
                
                // Create new content
                var newContent = document.createElement('span');
                newContent.appendChild(flagImg);
                newContent.appendChild(document.createTextNode(' ' + textWithoutEmoji));
                
                // Update option content
                option.innerHTML = '';
                option.appendChild(newContent);
            }
        }

        select.addEventListener('change', function() {
            var val = this.value;

            if (val === 'default') {
                document.cookie = 'gcs_selected_currency=; path=/; max-age=0; SameSite=Lax';
            } else {
                var d = new Date();
                d.setTime(d.getTime() + (30*24*60*60*1000));
                document.cookie = 'gcs_selected_currency=' + val
                    + '; path=/; expires=' + d.toUTCString()
                    + '; SameSite=Lax';
            }

            window.location.reload();
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode( 'gcs_currency_selector', 'gcs_currency_selector_shortcode' );

/**
 * ==================================
 *  ADMIN MENU & STYLES
 * ==================================
 */
function gcs_admin_menu() {
    add_menu_page(
        'Gulf Currency Switcher',
        'Currency Switcher',
        'manage_options',
        'gcs-settings',
        'gcs_settings_page',
        'dashicons-money-alt',
        58
    );
}
add_action( 'admin_menu', 'gcs_admin_menu' );

function gcs_admin_enqueue_scripts( $hook ) {
    if ( $hook !== 'toplevel_page_gcs-settings' ) {
        return;
    }

    wp_enqueue_script( 'jquery' );
    wp_enqueue_style( 'gcs-select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css' );
    wp_enqueue_script( 'gcs-select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', [ 'jquery' ], null, true );

    wp_add_inline_style( 'admin-bar', '
        .gcs-admin-container { max-width: 800px; }
        .gcs-status-box { padding: 12px 16px; margin: 20px 0; border-radius: 8px; border-left: 4px solid; }
        .gcs-status-success { background: #f0f9f0; border-left-color: #46b450; }
        .gcs-status-error { background: #fdf0f0; border-left-color: #dc3232; }
        .gcs-form-section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .gcs-form-section h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
        .gcs-country-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: #f5f5f5;
            border-radius: 20px;
            margin: 4px;
            font-size: 13px;
            border: 1px solid #e0e0e0;
        }
        .gcs-country-chip .remove {
            color: #999;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            margin-left: 4px;
        }
        .gcs-country-chip .remove:hover { color: #dc3232; }
        .select2-container { width: 100% !important; max-width: 400px; }
        .select2-selection { height: 36px !important; border-radius: 4px !important; }
        .select2-selection__rendered { line-height: 36px !important; }
        .select2-selection__arrow { height: 34px !important; }
        .gcs-flag {
            width: 20px;
            height: 14px;
            border-radius: 2px;
            object-fit: cover;
            margin-right: 6px;
            vertical-align: middle;
        }
    ' );
}
add_action( 'admin_enqueue_scripts', 'gcs_admin_enqueue_scripts' );

/**
 * ==================================
 *  AJAX: REFRESH COUNTRIES / RATES
 * ==================================
 */
function gcs_refresh_countries_ajax() {
    check_ajax_referer( 'gcs_refresh_countries', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $ok = gcs_refresh_countries_cache();

    if ( $ok ) {
        $list = gcs_get_countries_list();
        wp_send_json_success( [
            'message' => 'Countries refreshed successfully! Found ' . count( $list ) . ' country / currency entries.',
            'count'   => count( $list ),
        ] );
    }

    wp_send_json_error( 'Failed to fetch countries. Please try again.' );
}
add_action( 'wp_ajax_gcs_refresh_countries', 'gcs_refresh_countries_ajax' );

function gcs_refresh_rates_ajax() {
    check_ajax_referer( 'gcs_refresh_rates', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    
    $fetched = gcs_fetch_and_cache_rates();
    $cached  = get_transient( 'gcs_currency_rates' );
    
    if ( $fetched && ! empty( $cached['rates'] ) ) {
        $timestamp   = isset( $cached['timestamp'] ) ? date_i18n( 'Y-m-d H:i:s', $cached['timestamp'] ) : 'Now';
        $rates_count = count( $cached['rates'] );
        wp_send_json_success( [
            'message'   => "Exchange rates refreshed! {$rates_count} currencies updated at {$timestamp}",
            'timestamp' => $timestamp,
        ] );
    } else {
        wp_send_json_error( 'Failed to refresh exchange rates. API may be temporarily unavailable.' );
    }
}
add_action( 'wp_ajax_gcs_refresh_rates', 'gcs_refresh_rates_ajax' );

/**
 * ==================================
 *  ADMIN SETTINGS PAGE
 * ==================================
 */
function gcs_settings_page() {
    if ( isset( $_POST['gcs_save'] ) && check_admin_referer( 'gcs_save_settings' ) ) {
        $selected_countries_data = isset( $_POST['selected_countries'] ) ? $_POST['selected_countries'] : '[]';
        $selected_countries      = json_decode( stripslashes( $selected_countries_data ), true );

        if ( ! is_array( $selected_countries ) ) {
            $selected_countries = [];
        }
        
        $settings = [
            'base_currency'      => sanitize_text_field( $_POST['base_currency'] ?? '' ),
            'update_hours'       => max( 1, min( 48, intval( $_POST['update_hours'] ?? 24 ) ) ),
            'selected_countries' => $selected_countries,
        ];
        update_option( 'gcs_settings', $settings );
        
        echo '<div class="updated"><p><strong>Settings saved successfully!</strong></p></div>';
    }

    $settings      = gcs_get_settings();
    $cached_rates  = get_transient( 'gcs_currency_rates' );
    $last_update   = isset( $cached_rates['timestamp'] ) ? date_i18n( 'Y-m-d H:i:s', $cached_rates['timestamp'] ) : 'Never';
    $rates_count   = isset( $cached_rates['rates'] ) ? count( $cached_rates['rates'] ) : 0;
    $woo_currency  = get_option( 'woocommerce_currency', 'USD' );

    $all_countries      = gcs_get_countries_list();
    $selected_countries = isset( $settings['selected_countries'] ) ? $settings['selected_countries'] : [];
    ?>
    <div class="wrap gcs-admin-container">
        <h1>🌍 Gulf Currency Switcher</h1>

        <?php if ( $rates_count > 0 ) : ?>
            <div class="gcs-status-box gcs-status-success">
                <strong>✅ Exchange Rates Active</strong> 
                <p style="margin:5px 0 0;font-size:13px;color:#666;">
                    Last updated: <?php echo esc_html( $last_update ); ?> | 
                    Available currencies: <?php echo intval( $rates_count ); ?>
                </p>
            </div>
        <?php else : ?>
            <div class="gcs-status-box gcs-status-error">
                <strong>❌ No Exchange Rates Available</strong>
                <p style="margin:5px 0 0;font-size:13px;color:#666;">
                    Please refresh rates below. API may be temporarily unavailable.
                </p>
            </div>
        <?php endif; ?>

        <form method="post" id="gcs-settings-form">
            <?php wp_nonce_field( 'gcs_save_settings' ); ?>

            <div class="gcs-form-section">
                <h2>⚙️ Basic Settings</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Base Currency</label></th>
                        <td>
                            <input type="text" name="base_currency" value="<?php echo esc_attr( $woo_currency ); ?>" readonly class="regular-text" style="background:#f5f5f5;border:1px solid #ddd;" />
                            <p class="description">Your WooCommerce store's default currency</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Update Interval</label></th>
                        <td>
                            <input type="number" name="update_hours" value="<?php echo esc_attr( $settings['update_hours'] ); ?>" min="1" max="48" class="small-text" style="width:70px;" /> hours
                            <p class="description">How often to refresh exchange rates</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="gcs-form-section">
                <h2>🌐 Select Currencies</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Add Countries</label></th>
                        <td>
                            <select id="gcs-countries-select" class="gcs-country-select">
                                <option value="">Search for countries...</option>
                                <?php foreach ( $all_countries as $country ) :
                                    if ( empty( $country['currency_code'] ) ) {
                                        continue;
                                    }

                                    $country_code = $country['country_code'];
                                    $currency     = $country['currency_code'];
                                    $flag_png     = $country['flag_png'];
                                    $flag_emoji   = $country_code ? gcs_country_code_to_emoji( $country_code ) : '🏴';

                                    $display_name = $flag_emoji . ' ' . $country_code . ' — ' . $currency;

                                    $option_value = json_encode( [
                                        'name'          => $country['common_name'],
                                        'country_code'  => $country_code,
                                        'currency_code' => $currency,
                                        'flag_png'      => $flag_png,
                                    ] );
                                ?>
                                    <option value="<?php echo esc_attr( $option_value ); ?>">
                                        <?php echo esc_html( $display_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div style="margin-top:10px;">
                                <button type="button" id="gcs-refresh-countries" class="button">
                                    🔄 Refresh Countries List
                                </button>
                                <span id="gcs-refresh-countries-status" style="margin-left:10px;font-size:12px;color:#666;"></span>
                            </div>

                            <div style="margin-top:20px;">
                                <h3 style="margin-bottom:10px;">Selected Countries:</h3>
                                <div id="gcs-selected-chips"></div>
                                <div id="gcs-no-selection" style="color:#999;font-style:italic;margin-top:10px;">
                                    No countries selected. Search and select countries above.
                                </div>
                            </div>

                            <input type="hidden" name="selected_countries" id="gcs-selected-countries-data" value="<?php echo esc_attr( json_encode( $selected_countries ) ); ?>" />
                        </td>
                    </tr>
                </table>
            </div>

            <div class="gcs-form-section">
                <h2>📊 Exchange Rates</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Current Rates</label></th>
                        <td>
                            <div style="max-height:300px;overflow-y:auto;padding:10px;background:#f9f9f9;border-radius:4px;">
                                <?php
                                $rates = gcs_get_currency_rates();
                                if ( ! empty( $rates ) && ! empty( $selected_countries ) ) {
                                    echo '<table style="width:100%;border-collapse:collapse;">';
                                    echo '<tr style="background:#f0f0f0;"><th style="padding:8px;text-align:left;border-bottom:1px solid #ddd;">Currency</th><th style="padding:8px;text-align:left;border-bottom:1px solid #ddd;">Rate</th></tr>';
                                    foreach ( $selected_countries as $country ) {
                                        $currency = strtoupper( $country['currency_code'] ?? '' );
                                        if ( $currency && isset( $rates[ $currency ] ) ) {
                                            echo '<tr>';
                                            echo '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( $currency ) . '</td>';
                                            echo '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( number_format( $rates[ $currency ], 4 ) ) . '</td>';
                                            echo '</tr>';
                                        }
                                    }
                                    echo '</table>';
                                } elseif ( empty( $selected_countries ) ) {
                                    echo '<p style="color:#666;text-align:center;padding:20px;">Select countries above to see their exchange rates</p>';
                                } else {
                                    echo '<p style="color:#666;text-align:center;padding:20px;">No exchange rates available. Please refresh.</p>';
                                }
                                ?>
                            </div>

                            <div style="margin-top:15px;">
                                <button type="button" id="gcs-refresh-rates" class="button button-secondary">
                                    🔄 Refresh Exchange Rates Now
                                </button>
                                <span id="gcs-refresh-rates-status" style="margin-left:10px;font-size:12px;"></span>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <input type="submit" name="gcs_save" class="button-primary button-large" value="💾 Save Settings" />
            </p>
        </form>

        <div class="gcs-form-section">
            <h2>📘 How to Use</h2>
            <div style="background:#f8f9fa;padding:15px;border-radius:6px;border-left:4px solid #007cba;">
                <p><strong>Shortcode:</strong> Add this shortcode anywhere in your site:</p>
                <code style="display:block;background:white;padding:10px;margin:10px 0;border:1px solid #ddd;border-radius:4px;font-size:14px;">
                    [gcs_currency_selector]
                </code>
                
                <p><strong>Preview:</strong> The selector will display flags with country and currency codes (e.g., "🇦🇪 AE — AED").</p>
                
                <p><strong>Note:</strong> Only currencies with available exchange rates will be selectable by visitors.</p>

                <br>
                <h3>🧠 Notes</h3>
                <ul>
                    <li>Exchange rates update automatically once every 24 hours (you can change this).</li>
                    <li>Free API source: open.er-api.com</li>
                    <li>Countries data from: REST Countries API</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var selectedCountries = <?php echo json_encode( $selected_countries ); ?> || [];

        function countryCodeToEmoji(countryCode) {
            countryCode = (countryCode || '').toUpperCase();
            if (countryCode.length !== 2) return '🏴';
            return String.fromCodePoint(
                countryCode.charCodeAt(0) + 127397,
                countryCode.charCodeAt(1) + 127397
            );
        }

        function formatCountry(state) {
            if (!state.id) return state.text;
            var val = $(state.element).val();
            var data;
            try { data = JSON.parse(val); } catch (e) { return state.text; }

            var flagHtml = '';
            if (data.flag_png) {
                flagHtml = '<img src="' + data.flag_png + '" class="gcs-flag" />';
            } else if (data.country_code) {
                flagHtml = countryCodeToEmoji(data.country_code) + ' ';
            } else {
                flagHtml = '🏴 ';
            }

            var label = (data.country_code || '') + ' — ' + (data.currency_code || '');
            return $('<span>' + flagHtml + ' ' + label + '</span>');
        }

        $('#gcs-countries-select').select2({
            placeholder: 'Search for countries...',
            allowClear: true,
            width: '100%',
            dropdownParent: $('#gcs-countries-select').parent(),
            dropdownCssClass: 'gcs-select2-dropdown',
            templateResult: formatCountry,
            templateSelection: formatCountry
        });

        function renderSelectedChips() {
            var html = '';
            var hasSelection = false;

            selectedCountries.forEach(function(country, idx) {
                hasSelection = true;
                var flagHtml = '';
                if (country.flag_png) {
                    flagHtml = '<img src="' + country.flag_png + '" class="gcs-flag" />';
                } else if (country.country_code) {
                    flagHtml = countryCodeToEmoji(country.country_code) + ' ';
                } else {
                    flagHtml = '🏴 ';
                }

                html += '<div class="gcs-country-chip" data-index="' + idx + '">';
                html += '<span class="flag">' + flagHtml + '</span>';
                html += '<span class="code">' + (country.country_code || '') + '</span>';
                html += '<span class="currency">' + (country.currency_code || '') + '</span>';
                html += '<a href="#" class="remove" data-index="' + idx + '" title="Remove">×</a>';
                html += '</div>';
            });

            if (hasSelection) {
                $('#gcs-selected-chips').html(html);
                $('#gcs-no-selection').hide();
            } else {
                $('#gcs-selected-chips').html('');
                $('#gcs-no-selection').show();
            }

            $('#gcs-selected-countries-data').val(JSON.stringify(selectedCountries));
        }

        $('#gcs-countries-select').on('change', function() {
            var raw = $(this).val();
            if (!raw) return;

            try {
                var data = JSON.parse(raw);
                var exists = selectedCountries.some(function(c) {
                    return c.country_code === data.country_code &&
                           c.currency_code === data.currency_code;
                });

                if (!exists) {
                    selectedCountries.push({
                        name: data.name,
                        country_code: data.country_code,
                        currency_code: data.currency_code,
                        flag_png: data.flag_png
                    });
                    renderSelectedChips();
                }
            } catch (e) {
                console.error('Error parsing selected option', e);
            }

            $(this).val(null).trigger('change');
        });

        $(document).on('click', '.gcs-country-chip .remove', function(e) {
            e.preventDefault();
            var index = $(this).data('index');
            selectedCountries.splice(index, 1);
            renderSelectedChips();
        });

        $('#gcs-refresh-countries').on('click', function() {
            var btn = $(this);
            var status = $('#gcs-refresh-countries-status');
            btn.prop('disabled', true);
            status.text('Refreshing...').css('color', '#666');

            $.post(ajaxurl, {
                action: 'gcs_refresh_countries',
                nonce: '<?php echo wp_create_nonce( 'gcs_refresh_countries' ); ?>'
            }).done(function(resp) {
                if (resp.success) {
                    status.text('✅ ' + resp.data.message).css('color', '#46b450');
                    setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    status.text('❌ ' + resp.data).css('color', '#dc3232');
                    btn.prop('disabled', false);
                }
            }).fail(function() {
                status.text('❌ Failed to refresh countries').css('color', '#dc3232');
                btn.prop('disabled', false);
            });
        });

        $('#gcs-refresh-rates').on('click', function() {
            var btn = $(this);
            var status = $('#gcs-refresh-rates-status');
            btn.prop('disabled', true);
            status.text('Refreshing...').css('color', '#666');

            $.post(ajaxurl, {
                action: 'gcs_refresh_rates',
                nonce: '<?php echo wp_create_nonce( 'gcs_refresh_rates' ); ?>'
            }).done(function(resp) {
                if (resp.success) {
                    status.text('✅ ' + resp.data.message).css('color', '#46b450');
                    setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    status.text('❌ ' + resp.data).css('color', '#dc3232');
                    btn.prop('disabled', false);
                }
            }).fail(function() {
                status.text('❌ Failed to refresh rates').css('color', '#dc3232');
                btn.prop('disabled', false);
            });
        });

        renderSelectedChips();
    });
    </script>
    <?php
}
