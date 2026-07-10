<?php
/**
 * Shafpay WooCommerce Gateway and Webhook Handlers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
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

// Registrasi gateway ke daftar WooCommerce
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
        'permission_callback' => '__return_true',
    ));
}

function shafpay_webhook_callback_handler( WP_REST_Request $request ) {
    $raw_body = $request->get_body();
    $incoming_sig = $request->get_header('x-shafpay-signature');

    $logger = wc_get_logger();
    $context = array( 'source' => 'shafpay-gateway' );

    $logger->info( '--- Webhook Callback Diterima ---', $context );
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

    if ( ! hash_equals( $calculated_sig, $incoming_sig ) ) {
        $logger->error( 'Gagal: Signature mismatch / tidak cocok.', $context );
        return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
    }

    // Parse data
    $data = json_decode( $raw_body, true );
    $order_id = isset( $data['order_id'] ) ? $data['order_id'] : '';
    $status = isset( $data['status'] ) ? $data['status'] : '';

    if ( empty( $order_id ) || $status !== 'success' ) {
        $logger->error( 'Gagal: Payload tidak valid.', $context );
        return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        $logger->error( 'Gagal: Order #' . $order_id . ' tidak ditemukan.', $context );
        return new WP_REST_Response( array( 'error' => 'Order not found' ), 404 );
    }

    // Pelunasan otomatis order WooCommerce
    if ( ! $order->is_paid() ) {
        $order->payment_complete();
        $order->update_status( 'completed', __( 'Pembayaran lunas terverifikasi secara otomatis oleh Shafpay QRIS Gateway.', 'shafpay-gateway' ) );
        $logger->info( 'Sukses: Order #' . $order_id . ' telah ditandai sebagai Lunas.', $context );
        return new WP_REST_Response( array( 'success' => true, 'message' => 'Order marked as completed' ), 200 );
    }

    return new WP_REST_Response( array( 'success' => true, 'message' => 'Order was already paid' ), 200 );
}
