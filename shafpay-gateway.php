<?php
/**
 * Plugin Name: Shafpay Private Payment Gateway
 * Description: Integrasi pembayaran QRIS otomatis terpusat melalui shafpay.tsirwah.com untuk WooCommerce.
 * Version: 1.0.0
 * Author: Shafwah Developer Team
 * Author URI: https://shafpay.tsirwah.com
 * Text Domain: shafpay-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Tampilkan notice jika WooCommerce belum aktif
add_action( 'admin_notices', 'shafpay_admin_missing_woocommerce_notice' );
function shafpay_admin_missing_woocommerce_notice() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        ?>
        <div className="notice notice-warning is-dismissible">
            <p><?php _e( '<strong>Shafpay Private Payment Gateway</strong> membutuhkan plugin WooCommerce untuk diaktifkan terlebih dahulu.', 'shafpay-gateway' ); ?></p>
        </div>
        <?php
    }
}

// 1. Inisialisasi Gateway saat WooCommerce dimuat
add_action( 'plugins_loaded', 'shafpay_gateway_init', 11 );

function shafpay_gateway_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    class WC_Gateway_Shafpay extends WC_Payment_Gateway {
        
        public function __construct() {
            $this->id                 = 'shafpay';
            $this->icon               = ''; 
            $this->has_fields         = false;
            $this->method_title       = 'Shafpay';
            $this->method_description = 'Metode pembayaran QRIS otomatis menggunakan sistem terpusat Shafpay.';

            // Muat pengaturan admin
            $this->init_form_fields();
            $this->init_settings();

            // Set variables
            $this->title        = $this->get_option( 'title', 'Bayar Online via QRIS' );
            $this->description  = $this->get_option( 'description', 'Selesaikan pembayaran menggunakan aplikasi m-banking atau e-wallet (GoPay, OVO, ShopeePay, dll).' );
            $this->client_id    = $this->get_option( 'client_id' );
            $this->api_key      = $this->get_option( 'api_key' );
            $this->webhook_secret = $this->get_option( 'webhook_secret' );
            
            // API Endpoint Pusat
            $this->api_url      = 'https://shafpay.tsirwah.com/api/v1/payments';

            // Simpan setelan admin
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        // Form Pengaturan Admin di WooCommerce Settings
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Aktifkan/Nonaktifkan',
                    'type'    => 'checkbox',
                    'label'   => 'Aktifkan Shafpay QRIS Gateway',
                    'default' => 'no'
                ),
                'title' => array(
                    'title'       => 'Judul Pembayaran',
                    'type'        => 'text',
                    'description' => 'Teks yang akan dilihat pembayar saat checkout.',
                    'default'     => 'Bayar Online via QRIS',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Deskripsi Pembayaran',
                    'type'        => 'textarea',
                    'description' => 'Petunjuk tambahan yang tampil di halaman checkout.',
                    'default'     => 'Bayar mudah, aman, dan instan menggunakan QRIS.',
                ),
                'client_id' => array(
                    'title'       => 'Client ID Lembaga',
                    'type'        => 'text',
                    'description' => 'Diperoleh dari Dashboard Pusat Shafpay (e.g. SHF-PNDK-014).',
                ),
                'api_key' => array(
                    'title'       => 'API Key',
                    'type'        => 'password',
                    'description' => 'Kunci API rahasia untuk otentikasi transaksi.',
                ),
                'webhook_secret' => array(
                    'title'       => 'Webhook Secret Token',
                    'type'        => 'password',
                    'description' => 'Digunakan untuk memvalidasi callback pembayaran lunas dari pusat.',
                ),
            );
        }

        // Memproses Pembayaran saat Klik "Bayar Sekarang" di WooCommerce
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            // Siapkan data wali santri
            $payer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $payer_email = $order->get_billing_email();
            $payer_phone = $order->get_billing_phone();

            // Payload untuk API Pusat
            $payload = array(
                'order_id'    => (string)$order_id,
                'amount_spp'  => (float)$order->get_total(),
                'payer_name'  => $payer_name ? $payer_name : 'Wali Santri',
                'payer_email' => $payer_email ? $payer_email : 'santri@shafwah.id',
                'payer_phone' => $payer_phone ? $payer_phone : '',
                'return_url'  => $this->get_return_url( $order )
            );

            // Request POST ke API Pusat
            $response = wp_remote_post( $this->api_url, array(
                'method'    => 'POST',
                'headers'   => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->api_key
                ),
                'body'      => json_encode( $payload ),
                'timeout'   => 15
            ));

            if ( is_wp_error( $response ) ) {
                $error_message = $response->get_error_message();
                wc_add_notice( 'Koneksi ke sistem Shafpay gagal: ' . $error_message, 'error' );
                return;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( isset( $body['success'] ) && $body['success'] ) {
                // Simpan ID Invoice Xendit ke metadata order untuk referensi
                $order->update_meta_data( '_shafpay_invoice_id', $body['invoice_id'] );
                $order->update_meta_data( '_shafpay_amount_gross', $body['amount_gross'] );
                $order->save();

                // Redirect wali santri ke halaman QRIS Xendit
                return array(
                    'result'   => 'success',
                    'redirect' => $body['payment_url']
                );
            } else {
                $err_msg = isset( $body['error'] ) ? $body['error'] : 'Terjadi kesalahan internal.';
                wc_add_notice( 'Gagal memproses pembayaran: ' . $err_msg, 'error' );
                return;
            }
        }
    }
}

// Registrasi gateway ke daftar WooCommerce (Globally agar stabil)
add_filter( 'woocommerce_payment_gateways', 'shafpay_add_to_gateways' );
function shafpay_add_to_gateways( $gateways ) {
    if ( class_exists( 'WC_Gateway_Shafpay' ) ) {
        $gateways[] = 'WC_Gateway_Shafpay';
    }
    return $gateways;
}

// 2. Buat REST API Endpoint Webhook di WordPress
add_action( 'rest_api_init', 'shafpay_register_webhook_routes' );
function shafpay_register_webhook_routes() {
    register_rest_route( 'shafpay/v1', '/webhook', array(
        'methods'             => 'POST',
        'callback'            => 'shafpay_webhook_callback_handler',
        'permission_callback' => '__return_true', // Proteksi dilakukan via HMAC di callback
    ));
}

function shafpay_webhook_callback_handler( WP_REST_Request $request ) {
    $raw_body = $request->get_body();
    $incoming_sig = $request->get_header('x-shafpay-signature');

    // Inisialisasi Logger WooCommerce
    $logger = wc_get_logger();
    $context = array( 'source' => 'shafpay-gateway' );

    $logger->info( '--- Webhook Callback Diterima ---', $context );
    $logger->info( 'Headers: ' . print_r( $request->get_headers(), true ), $context );
    $logger->info( 'Incoming Signature: ' . $incoming_sig, $context );
    $logger->info( 'Raw Body: ' . $raw_body, $context );

    // Ambil kunci Webhook Secret dari opsi database WP
    $options = get_option( 'woocommerce_shafpay_settings' );
    $webhook_secret = isset( $options['webhook_secret'] ) ? $options['webhook_secret'] : '';

    if ( empty( $webhook_secret ) ) {
        $logger->error( 'Gagal: Webhook Secret Token belum dikonfigurasi di WP.', $context );
        return new WP_REST_Response( array( 'error' => 'Webhook secret is not configured' ), 500 );
    }

    // Hitung signature HMAC-SHA256
    $calculated_sig = hash_hmac( 'sha256', $raw_body, $webhook_secret );
    $logger->info( 'Calculated Signature: ' . $calculated_sig, $context );

    if ( ! hash_equals( $calculated_sig, $incoming_sig ) ) {
        $logger->error( 'Gagal: Signature mismatch / tidak cocok.', $context );
        return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
    }

    // Parse data
    $data = json_decode( $raw_body, true );
    $order_id = isset( $data['order_id'] ) ? $data['order_id'] : '';
    $status = isset( $data['status'] ) ? $data['status'] : '';

    $logger->info( 'Parsed Order ID: ' . $order_id . ' | Status: ' . $status, $context );

    if ( empty( $order_id ) || $status !== 'success' ) {
        $logger->error( 'Gagal: Payload tidak valid (order_id kosong atau status bukan success).', $context );
        return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        $logger->error( 'Gagal: Order #' . $order_id . ' tidak ditemukan di WooCommerce.', $context );
        return new WP_REST_Response( array( 'error' => 'Order not found' ), 404 );
    }

    // Pelunasan otomatis order WooCommerce
    if ( ! $order->is_paid() ) {
        $order->payment_complete();
        // Paksa status order langsung menjadi 'completed' (Selesai)
        $order->update_status( 'completed', __( 'Pembayaran lunas terverifikasi secara otomatis oleh Shafpay QRIS Gateway.', 'shafpay-gateway' ) );
        $logger->info( 'Sukses: Order #' . $order_id . ' telah ditandai sebagai Lunas (Paid) dan dipaksa ke status Completed.', $context );
        return new WP_REST_Response( array( 'success' => true, 'message' => 'Order marked as completed' ), 200 );
    }

    $logger->info( 'Info: Order #' . $order_id . ' sudah berstatus Lunas sebelumnya.', $context );
    return new WP_REST_Response( array( 'success' => true, 'message' => 'Order was already paid' ), 200 );
}

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
    $options = get_option( 'woocommerce_shafpay_settings' );
    $api_key = isset( $options['api_key'] ) ? $options['api_key'] : '';
    
    $balance_net = 0;
    $bank_details = array();
    $error_msg = '';

    // Lakukan API Call ke Pusat jika API Key terpasang
    if ( ! empty( $api_key ) ) {
        $response = wp_remote_get( 'https://shafpay.tsirwah.com/api/v1/balance', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key
            ),
            'timeout' => 10
        ));

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['success'] ) && $body['success'] ) {
                $balance_net = $body['balance_net'];
                $bank_details = $body['bank_details'];
            } else {
                $error_msg = isset( $body['error'] ) ? $body['error'] : 'Gagal mengambil data saldo.';
            }
        } else {
            $error_msg = 'Koneksi API Pusat Gagal: ' . $response->get_error_message();
        }
    } else {
        $error_msg = 'API Key belum dikonfigurasi di pengaturan WooCommerce Payment.';
    }

    // Proses Form Pencairan jika disubmit
    if ( isset( $_POST['shafpay_withdraw_nonce'] ) && wp_verify_nonce( $_POST['shafpay_withdraw_nonce'], 'shafpay_withdraw' ) ) {
        $withdraw_amount = isset( $_POST['withdraw_amount'] ) ? floatval( $_POST['withdraw_amount'] ) : 0;
        
        if ( $withdraw_amount <= 0 ) {
            echo '<div className="notice notice-error"><p>Nominal pencairan harus lebih besar dari 0.</p></div>';
        } elseif ( $withdraw_amount > $balance_net ) {
            echo '<div className="notice notice-error"><p>Nominal pencairan melebihi saldo bersih Anda.</p></div>';
        } else {
            // Panggil API Pencairan
            $wd_response = wp_remote_post( 'https://shafpay.tsirwah.com/api/v1/withdrawals', array(
                'method' => 'POST',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key
                ),
                'body' => json_encode( array( 'amount' => $withdraw_amount ) ),
                'timeout' => 15
            ));

            if ( ! is_wp_error( $wd_response ) ) {
                $wd_body = json_decode( wp_remote_retrieve_body( $wd_response ), true );
                if ( isset( $wd_body['success'] ) && $wd_body['success'] ) {
                    echo '<div className="notice notice-success"><p>' . esc_html( $wd_body['message'] ) . '</p></div>';
                    // Reload data saldo
                    $balance_net -= $withdraw_amount;
                } else {
                    $err = isset( $wd_body['error'] ) ? $wd_body['error'] : 'Gagal mengirim pengajuan.';
                    echo '<div className="notice notice-error"><p>Gagal: ' . esc_html( $err ) . '</p></div>';
                }
            } else {
                echo '<div className="notice notice-error"><p>Koneksi Gagal: ' . esc_html( $wd_response->get_error_message() ) . '</p></div>';
            }
        }
    }

    ?>
    <div className="wrap">
        <h1>Manajemen Saldo Shafpay</h1>
        <p>Halaman ini menampilkan saldo virtual dan permohonan pencairan langsung ke rekening lembaga Anda.</p>

        <?php if ( ! empty( $error_msg ) ) : ?>
            <div className="notice notice-warning is-dismissible">
                <p><?php echo esc_html( $error_msg ); ?></p>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; max-width: 1000px;">
            
            <!-- CARD SALDO & BANK -->
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h2 style="margin-top:0;">Saldo Bersih (Bisa Cair)</h2>
                <div style="font-size: 32px; font-weight: 800; font-family: monospace; color: #155EEF; margin: 15px 0;">
                    Rp <?php echo number_format( $balance_net, 0, ',', '.' ); ?>
                </div>
                
                <hr style="border: none; border-top: 1px solid #eee; margin: 15px 0;" />
                
                <h3 style="margin-top:0; font-size: 14px;">Rekening Bank Tujuan Terdaftar:</h3>
                <?php if ( ! empty( $bank_details ) ) : ?>
                    <table style="width: 100%; border-collapse: collapse; font-size:13px;">
                        <tr>
                            <td style="padding: 6px 0; font-weight:600; width:120px;">Bank:</td>
                            <td style="padding: 6px 0;"><?php echo esc_html( $bank_details['bank_code'] ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 0; font-weight:600;">No. Rekening:</td>
                            <td style="padding: 6px 0; font-family: monospace;"><?php echo esc_html( $bank_details['account_number'] ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 0; font-weight:600;">Nama Pemilik:</td>
                            <td style="padding: 6px 0;"><?php echo esc_html( $bank_details['account_holder_name'] ); ?></td>
                        </tr>
                    </table>
                <?php else : ?>
                    <p style="color:#999; font-style:italic;">Data bank tidak tersedia. Hubungi Administrator Pusat Shafpay.</p>
                <?php endif; ?>
                
                <p style="font-size: 11px; color: #999; margin-top:15px; line-height: 1.4;">
                    *Perubahan detail rekening bank settlement hanya dapat dilakukan secara aman melalui permohonan ke admin pusat Shafpay.
                </p>
            </div>

            <!-- FORM PENARIKAN -->
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h2 style="margin-top:0;">Pengajuan Pencairan Saldo (Withdrawal)</h2>
                
                <form method="post" action="">
                    <?php wp_nonce_field( 'shafpay_withdraw', 'shafpay_withdraw_nonce' ); ?>
                    
                    <p>Masukkan jumlah saldo virtual bersih yang ingin Anda cairkan ke rekening terdaftar:</p>
                    
                    <div style="margin: 15px 0;">
                        <label style="display:block; font-weight:600; margin-bottom: 5px;" for="withdraw_amount">Jumlah Pencairan (Rupiah)</label>
                        <input 
                            type="number" 
                            name="withdraw_amount" 
                            id="withdraw_amount" 
                            className="regular-text" 
                            placeholder="e.g. 5000000" 
                            min="100000" 
                            max="<?php echo esc_attr( $balance_net ); ?>"
                            style="width: 100%; max-width: 350px; font-size:16px; padding: 6px 10px;"
                            required
                        />
                        <p className="description" style="margin-top: 5px;">Batas minimal pencairan Rp100.000. Biaya transfer bank Rp5.000 otomatis didebit dari saldo terkirim.</p>
                    </div>

                    <button 
                        type="submit" 
                        className="button button-primary button-large"
                        <?php echo ( $balance_net < 100000 ) ? 'disabled' : ''; ?>
                    >
                        Cairkan Saldo Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php
}
