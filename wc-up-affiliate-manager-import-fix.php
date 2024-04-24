<?php
/**
 * Plugin Name: WooCommerce UP Affiliate Manager Import Fix
 * Plugin URI: https://gitlab.com/wp-dmd/wc-up-affiliate-manager
 * Description: Provides import fix of Affiliate functionality for WooCommerce.
 * Version: 1.0.0
 * Author: Andrii Serb
 * Author URI: https://www.developtimization.com/imsadhappy
 * Requires at least: 5.5
 * Requires PHP: 7.2
 * Text Domain: wc-up-affiliate-manager
 * Domain Path: /languages/
 * License: MIT
 */

defined('ABSPATH') || exit;

add_action('plugins_loaded', function () {

    if (!defined('UP_AFFILIATE_MANAGER_PROJECT')) return;

    require_once __DIR__ . '/functions.php';

    add_action('admin_menu', function(){
        $menu_slug = plugin_basename( UP_AFFILIATE_MANAGER_PROJECT );
        $hookname = get_plugin_page_hookname( $menu_slug, '' );
        if (isset($_POST['import2']) && !empty($hookname)) {
            add_action( $hookname, function()  {
                $msg = upAffiliateManagerImportProducts2();
                show_message('
                    <div class="notice notice-success is-dismissible">
                        <p id="import_process">' . $msg . '</p>
                    </div><style>.notice-success ~ .notice-error {display:none}</style>'
                );
            }, 9 );
        }
    });

    add_action( 'admin_init', function(){
        add_settings_field(
            'wc_up_affiliate_manager_setting_import_batch_size',
            esc_attr__('Import Batch Size', UP_AFFILIATE_MANAGER_PROJECT),
            'upAffiliateManagerSettingsRegisterBatchSize',
            UP_AFFILIATE_MANAGER_PAGE,
            'api_settings'
        );
    }, 11);

    add_filter( 'pre_update_option', function ( $value, $option, $old_value ) {
        if ($option == UP_AFFILIATE_MANAGER_OPTIONS) {
            $n = intval($_POST[UP_AFFILIATE_MANAGER_OPTIONS]['batch_size']);
            $value['batch_size'] = $n > 0 ? $n : 50;
        }
        return $value;
    }, 11, 3 );

    add_action( 'admin_footer', function() {
        if (isset($_GET['page']) && UP_AFFILIATE_MANAGER_PROJECT == $_GET['page']) {
            ?><script>
                document.getElementById('import').setAttribute('name', 'import2')
            </script><?php
        }
    });

    add_action( 'wp_ajax_wc-up-affiliate-import-run', function () {
        $run = intval($_GET['run']);
        if ($run > 0) {
            exit(upAffiliateManagerImportProducts2($run));
        } else {
            exit('0');
        }
    } );

}, 99);
