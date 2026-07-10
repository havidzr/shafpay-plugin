<?php
/**
 * Plugin Name: Shafpay Private Payment Gateway
 * Description: Integrasi pembayaran QRIS otomatis terpusat melalui shafpay.tsirwah.com untuk WooCommerce dengan shortcode dashboard.
 * Version: 1.1.0
 * Author: Shafwah Developer Team
 * Author URI: https://shafpay.tsirwah.com
 * Text Domain: shafpay-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// 1. Tampilkan notice jika WooCommerce belum aktif
add_action( 'admin_notices', 'shafpay_admin_missing_woocommerce_notice' );
function shafpay_admin_missing_woocommerce_notice() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php _e( '<strong>Shafpay Private Payment Gateway</strong> membutuhkan plugin WooCommerce untuk diaktifkan terlebih dahulu.', 'shafpay-gateway' ); ?></p>
        </div>
        <?php
    }
}

// 2. Load File Modular Includes
require_once plugin_dir_path( __FILE__ ) . 'includes/class-shafpay-gateway.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-shafpay-dashboard.php';

// 3. Tambahkan Halaman Menu "Saldo Shafpay" di Admin WordPress Sidebar
add_action( 'admin_menu', 'shafpay_add_admin_menu' );
function shafpay_add_admin_menu() {
    add_menu_page(
        'Saldo Shafpay',
        'Saldo Shafpay',
        'manage_options',
        'shafpay-saldo',
        'shafpay_saldo_page_callback',
        'dashicons-money-alt',
        56
    );
}

function shafpay_saldo_page_callback() {
    ?>
    <div class="wrap" style="background:#f1f5f9; padding: 20px; border-radius: 12px; margin-top:20px;">
        <h1 style="font-size:22px; font-weight:800; color:#0f172a; margin-bottom:10px;">Manajemen Saldo Shafpay</h1>
        <p style="color:#64748b; font-size:13px; margin:0 0 20px 0;">Halaman ini menampilkan saldo virtual dan permohonan pencairan langsung ke rekening lembaga Anda.</p>
        
        <?php
        // Menggunakan renderer dashboard yang sama dengan shortcode untuk efisiensi & konsistensi UI
        if ( class_exists( 'Shafpay_Dashboard' ) ) {
            echo Shafpay_Dashboard::render_dashboard();
        }
        ?>
    </div>
    <?php
}

// 4. GitHub Auto-Updater (Non-Blocking Update Checker)
add_filter( 'pre_set_site_transient_update_plugins', 'shafpay_check_for_plugin_update' );
function shafpay_check_for_plugin_update( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_slug = 'shafpay-plugin/shafpay-gateway.php';
    $current_version = '1.1.0';

    $response = wp_remote_get( 'https://api.github.com/repos/havidzr/shafpay-plugin/releases/latest', array(
        'headers' => array(
            'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' )
        ),
        'timeout' => 10
    ) );

    if ( ! is_wp_error( $response ) ) {
        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $release['tag_name'] ) ) {
            $new_version = ltrim( $release['tag_name'], 'v' );
            if ( version_compare( $current_version, $new_version, '<' ) ) {
                $obj = new stdClass();
                $obj->slug = 'shafpay-plugin';
                $obj->plugin = $plugin_slug;
                $obj->new_version = $new_version;
                $obj->url = 'https://github.com/havidzr/shafpay-plugin';
                $obj->package = $release['zipball_url']; // Download zip ball langsung dari rilis GitHub

                $transient->response[$plugin_slug] = $obj;
            }
        }
    }
    return $transient;
}

// 5. Perbaikan Penamaan Folder Pasca Instalasi Update GitHub
add_filter( 'upgrader_post_install', 'shafpay_rename_plugin_folder', 10, 3 );
function shafpay_rename_plugin_folder( $response, $hook_extra, $result ) {
    global $wp_filesystem;
    $plugin_folder = 'shafpay-plugin';
    $install_directory = WP_PLUGIN_DIR . '/' . $plugin_folder;

    if ( isset( $result['destination'] ) && strpos( basename( $result['destination'] ), 'shafpay-plugin' ) !== false ) {
        $wp_filesystem->move( $result['destination'], $install_directory );
        $result['destination'] = $install_directory;
    }
    return $response;
}
