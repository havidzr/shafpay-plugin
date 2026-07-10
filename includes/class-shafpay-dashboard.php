<?php
/**
 * Shafpay Frontend Dashboard Shortcode [dashboard_shafpay]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shafpay_Dashboard {

    public static function init() {
        // Registrasi Shortcode
        add_shortcode( 'dashboard_shafpay', array( __CLASS__, 'render_dashboard' ) );

        // Enqueue Assets di Frontend
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        // Enqueue Assets di WordPress Admin
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
    }

    public static function enqueue_assets() {
        // Cek apakah halaman memuat shortcode shafpay untuk efisiensi load
        global $post;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'dashboard_shafpay' ) ) {
            wp_enqueue_style(
                'shafpay-dashboard-css',
                plugins_url( 'assets/css/dashboard.css', dirname( __FILE__ ) ),
                array(),
                '1.1.0'
            );
        }
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_shafpay-saldo' === $hook ) {
            wp_enqueue_style(
                'shafpay-dashboard-css',
                plugins_url( 'assets/css/dashboard.css', dirname( __FILE__ ) ),
                array(),
                '1.1.0'
            );
        }
    }

    public static function render_dashboard() {
        // 1. Validasi Keamanan & Hak Akses (Role-Lock)
        if ( ! is_user_logged_in() ) {
            return '<div class="shafpay-alert shafpay-danger">Anda harus masuk (login) terlebih dahulu untuk mengakses portal ini.</div>';
        }

        $current_user = wp_get_current_user();
        $allowed_roles = array( 'administrator', 'um_admin-aplikasi' );
        $has_access = array_intersect( $allowed_roles, (array) $current_user->roles );

        if ( empty( $has_access ) ) {
            return '<div class="shafpay-alert shafpay-danger">Akses Ditolak: Anda tidak memiliki wewenang untuk melihat data keuangan ini.</div>';
        }

        // 2. Ambil Pengaturan Gateway (API Key & Client ID)
        $options = get_option( 'woocommerce_shafpay_settings' );
        $api_key = isset( $options['api_key'] ) ? $options['api_key'] : '';
        $client_id = isset( $options['client_id'] ) ? $options['client_id'] : '';

        if ( empty( $api_key ) ) {
            return '<div class="shafpay-alert shafpay-warning">Sistem Shafpay belum dikonfigurasi. Hubungi developer untuk bantuan integrasi.</div>';
        }

        $balance_virtual = 0;
        $balance_withdrawable = 0;
        $balance_settled = 0;
        $bank_details = array();
        $error_msg = '';
        $success_msg = '';

        // 3. Proses Formulir Pencairan (Withdrawal Submit)
        if ( isset( $_POST['shafpay_withdraw_nonce'] ) && wp_verify_nonce( $_POST['shafpay_withdraw_nonce'], 'shafpay_withdraw' ) ) {
            $withdraw_amount = isset( $_POST['withdraw_amount'] ) ? floatval( $_POST['withdraw_amount'] ) : 0;
            
            if ( $withdraw_amount <= 0 ) {
                $error_msg = 'Nominal pencairan harus lebih besar dari 0.';
            } else {
                // Tembak API Pencairan Pusat
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
                        $success_msg = $wd_body['message'];
                    } else {
                        $error_msg = isset( $wd_body['error'] ) ? $wd_body['error'] : 'Gagal mengirim pengajuan.';
                    }
                } else {
                    $error_msg = 'Koneksi Gagal: ' . $wd_response->get_error_message();
                }
            }
        }

        // 4. Fetch Data Saldo Terbaru
        $response = wp_remote_get( 'https://shafpay.tsirwah.com/api/v1/balance', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key
            ),
            'timeout' => 10
        ));

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['success'] ) && $body['success'] ) {
                $balance_virtual = isset( $body['balance_virtual'] ) ? floatval( $body['balance_virtual'] ) : 0;
                $balance_withdrawable = isset( $body['balance_withdrawable'] ) ? floatval( $body['balance_withdrawable'] ) : 0;
                $balance_settled = isset( $body['balance_settled'] ) ? floatval( $body['balance_settled'] ) : 0;
                $bank_details = $body['bank_details'];
            } else {
                $error_msg = isset( $body['error'] ) ? $body['error'] : 'Gagal memuat rincian saldo.';
            }
        } else {
            $error_msg = 'Gagal terhubung ke server kas: ' . $response->get_error_message();
        }

        // 5. Render HTML Output Dashboard Keuangan
        ob_start();
        ?>
        <div class="shafpay-dashboard">
            <!-- Header dengan Logo Branded -->
            <div class="shafpay-dash-header" style="display: flex; align-items: center; gap: 14px; margin-bottom: 24px;">
                <img src="https://shafpay.tsirwah.com/logo.png" alt="Shafpay Logo" style="width: 44px; height: 44px; object-fit: contain; flex-shrink: 0;" />
                <div>
                    <h2 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0; letter-spacing: -0.5px;">Portal Keuangan Lembaga</h2>
                    <p>Pantau laporan saldo virtual bersih dan ajukan pencairan dana otomatis secara langsung.</p>
                </div>
            </div>

            <!-- Messages Box -->
            <?php if ( ! empty( $error_msg ) ) : ?>
                <div class="shafpay-alert shafpay-danger">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?php echo esc_html( $error_msg ); ?></span>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $success_msg ) ) : ?>
                <div class="shafpay-alert shafpay-success">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    <span><?php echo esc_html( $success_msg ); ?></span>
                </div>
            <?php endif; ?>

            <!-- Grid 3 Kolom - Stat Cards -->
            <div class="shafpay-grid-3">
                <!-- Card 1: Saldo Virtual -->
                <div class="shafpay-card shafpay-stat-card">
                  <div class="shafpay-icon-wrapper blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/><path d="M16 8h2v2h-2zM16 12h2v2h-2zM6 8h6v8H6z"/></svg>
                  </div>
                  <div class="shafpay-label">Saldo Virtual (Net SPP)</div>
                  <div class="shafpay-value">Rp <?php echo number_format( $balance_virtual, 0, ',', '.' ); ?></div>
                  <div class="shafpay-sub">Akumulasi pendapatan bersih SPP terhimpun</div>
                </div>

                <!-- Card 2: Saldo Bisa Dicairkan -->
                <div class="shafpay-card shafpay-stat-card">
                  <div class="shafpay-icon-wrapper amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>
                  </div>
                  <div class="shafpay-label">Saldo Bisa Dicairkan</div>
                  <div class="shafpay-value" style="color: #d97706;">Rp <?php echo number_format( $balance_withdrawable, 0, ',', '.' ); ?></div>
                  <div class="shafpay-sub">Dana settled siap dipindahkan ke rekening bank</div>
                </div>

                <!-- Card 3: Saldo Terbayar -->
                <div class="shafpay-card shafpay-stat-card">
                  <div class="shafpay-icon-wrapper green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v6h6M20 20v-6h-6"/><path d="M20 8a8 8 0 00-14.6-3M4 16a8 8 0 0014.6 3"/></svg>
                  </div>
                  <div class="shafpay-label">Total Saldo Terbayar</div>
                  <div class="shafpay-value">Rp <?php echo number_format( $balance_settled, 0, ',', '.' ); ?></div>
                  <div class="shafpay-sub">Akumulasi dana sukses dicairkan ke bank</div>
                </div>
            </div>

            <!-- Panduan/Instruksi Cek Ledger Audit -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 20px; text-align: center; margin-bottom: 24px; font-size: 13px; color: #475569;">
                📊 Ingin melihat buku kas atau laporan mutasi audit lengkap? Kunjungi 
                <a href="https://shafpay.tsirwah.com" target="_blank" style="color: #1c64f2; font-weight: 700; text-decoration: none;">Portal Hub Shafpay &rarr;</a>
            </div>

            <!-- Grid 2 Kolom - Rekening & Form -->
            <div class="shafpay-grid-2">
                <!-- Info Bank Terdaftar (Redesigned Banking Card Style) -->
                <div class="shafpay-card shafpay-section-card">
                    <h3>🏦 Rekening Bank Tujuan Transfer</h3>
                    <p class="shafpay-desc" style="margin-bottom: 15px;">Pencairan dana otomatis dikirim langsung ke rekening bank penampung resmi lembaga Anda.</p>
                    
                    <?php if ( ! empty( $bank_details ) ) : ?>
                        <?php
                        $acc_num = isset($bank_details['account_number']) ? $bank_details['account_number'] : '';
                        $formatted_number = str_replace( '••••', ' &nbsp;••••&nbsp; ••••&nbsp; ', $acc_num );
                        ?>
                        <div class="card-visual">
                            <div class="card-top">
                                <div class="chip"></div>
                                <div class="card-badge">
                                    <svg viewBox="0 0 24 24" fill="none"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z" stroke="white" stroke-width="1.8"/></svg>
                                    Terverifikasi
                                </div>
                            </div>
                            <div class="card-number"><?php echo $formatted_number; ?></div>
                            <div class="card-bottom">
                                <div>
                                    <div class="card-label">Pemegang Rekening</div>
                                    <div class="card-name"><?php echo esc_html( $bank_details['account_holder_name'] ); ?></div>
                                </div>
                                <div class="card-bank"><?php echo esc_html( $bank_details['bank_code'] ); ?></div>
                            </div>
                        </div>
                    <?php else : ?>
                        <p style="color: #94a3b8; font-style: italic; font-size: 13px;">Informasi rekening bank belum diatur. Silakan berkoordinasi dengan developer pusat.</p>
                    <?php endif; ?>
                    
                    <div class="card-footnote">
                        <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 8v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="16" r="0.9" fill="currentColor"/></svg>
                        <span>Perubahan rekening tujuan tidak dapat dilakukan di sini demi keamanan dana. Ajukan permohonan ke <a href="https://shafpay.tsirwah.com" target="_blank">admin pusat</a> untuk melakukan koreksi.</span>
                    </div>
                </div>

                <!-- Form Pengajuan Pencairan -->
                <div class="shafpay-card shafpay-section-card">
                    <h3>💸 Pengajuan Pencairan Dana</h3>
                    <p class="shafpay-desc">Ajukan pemindahan saldo settled Anda ke rekening bank tujuan terdaftar di sebelah.</p>
                    
                    <form method="post" action="">
                        <?php wp_nonce_field( 'shafpay_withdraw', 'shafpay_withdraw_nonce' ); ?>
                        
                        <div class="shafpay-field-group">
                            <label class="shafpay-field-label" for="withdraw_amount">Jumlah Penarikan (Rupiah)</label>
                            <input 
                                type="number" 
                                name="withdraw_amount" 
                                id="withdraw_amount" 
                                class="shafpay-input"
                                placeholder="e.g. 500000" 
                                min="100000" 
                                max="<?php echo esc_attr( $balance_withdrawable ); ?>"
                                required
                            />
                            <p class="shafpay-input-description">Batas penarikan minimum Rp100.000. Biaya admin kirim dana flat Rp5.000 otomatis dipotong dari saldo penarikan Anda.</p>
                        </div>

                        <button 
                            type="submit" 
                            class="shafpay-btn shafpay-btn-primary"
                            <?php echo ( $balance_withdrawable < 100000 ) ? 'disabled' : ''; ?>
                        >
                            Ajukan Pencairan Sekarang
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
 Shafpay_Dashboard::init();
