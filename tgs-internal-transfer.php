<?php
/**
 * Plugin Name: TGS Internal Transfer
 * Plugin URI: https://bizgpt.vn/
 * Description: Plugin quản lý chuyển kho nội bộ từ HTsoft Excel - Tạo phiếu mua nội bộ và nhập tự động
 * Version: 1.0.0
 * Author: BIZGPT_AI
 * Author URI: https://bizgpt.vn/
 * License: GPL v2 or later
 * Text Domain: tgs-internal-transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('TGS_INTERNAL_TRANSFER_VERSION', '1.0.0');
define('TGS_INTERNAL_TRANSFER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_INTERNAL_TRANSFER_PLUGIN_URL', plugin_dir_url(__FILE__));

class TGS_Internal_Transfer {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook vào workflow nav để thêm menu
        add_filter('tgs_shop_workflow_nav', array($this, 'add_to_workflow_nav'), 10, 2);

        // Enqueue scripts và styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX handlers
        add_action('wp_ajax_tgs_it_parse_excel', array($this, 'ajax_parse_excel'));
        add_action('wp_ajax_tgs_it_create_vouchers', array($this, 'ajax_create_vouchers'));
        add_action('wp_ajax_tgs_it_get_deployment_shops', array($this, 'ajax_get_deployment_shops'));
        add_action('wp_ajax_tgs_it_save_deployment_shop', array($this, 'ajax_save_deployment_shop'));
        add_action('wp_ajax_tgs_it_delete_deployment_shop', array($this, 'ajax_delete_deployment_shop'));

        // Include required files
        $this->includes();
    }

    /**
     * Thêm menu vào workflow nav (trong menu Quản trị -> Hệ thống)
     */
    public function add_to_workflow_nav($workflow_nav, $current_view) {
        if (isset($workflow_nav['admin']['sections'])) {
            // Tìm section "Hệ thống" (heading: "Hệ thống")
            foreach ($workflow_nav['admin']['sections'] as $key => $section) {
                if (isset($section['heading']) && $section['heading'] === 'Hệ thống') {
                    // Thêm menu "Chuyển kho nội bộ" vào danh sách items
                    $workflow_nav['admin']['sections'][$key]['items'][] = array(
                        'view' => 'internal-transfer',
                        'label' => 'Chuyển kho nội bộ',
                        'icon' => 'bx bx-transfer',
                    );
                    break;
                }
            }
        }
        return $workflow_nav;
    }

    private function includes() {
        require_once TGS_INTERNAL_TRANSFER_PLUGIN_DIR . 'includes/class-excel-parser.php';
        require_once TGS_INTERNAL_TRANSFER_PLUGIN_DIR . 'includes/class-voucher-creator.php';
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_tgs-shop-management') {
            return;
        }

        $current_view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '';
        if ($current_view !== 'internal-transfer') {
            return;
        }

        // SheetJS library
        wp_enqueue_script(
            'sheetjs',
            'https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js',
            array(),
            '1.2.0',
            true
        );

        wp_enqueue_script(
            'tgs-internal-transfer',
            TGS_INTERNAL_TRANSFER_PLUGIN_URL . 'assets/js/internal-transfer.js',
            array('jquery', 'sheetjs'),
            TGS_INTERNAL_TRANSFER_VERSION,
            true
        );

        wp_enqueue_style(
            'tgs-internal-transfer',
            TGS_INTERNAL_TRANSFER_PLUGIN_URL . 'assets/css/internal-transfer.css',
            array(),
            TGS_INTERNAL_TRANSFER_VERSION
        );

        wp_localize_script('tgs-internal-transfer', 'tgsInternalTransfer', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tgs_internal_transfer_nonce'),
        ));
    }

    public function ajax_parse_excel() {
        check_ajax_referer('tgs_internal_transfer_nonce', 'nonce');

        try {
            $excel_data = json_decode(stripslashes($_POST['excel_data']), true);
            $selected_sheet = sanitize_text_field($_POST['selected_sheet']);

            $parser = new TGS_IT_Excel_Parser();
            $result = $parser->parse_and_group($excel_data, $selected_sheet);

            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_create_vouchers() {
        check_ajax_referer('tgs_internal_transfer_nonce', 'nonce');

        try {
            $voucher_code = sanitize_text_field($_POST['voucher_code']);
            $site_code = sanitize_text_field($_POST['site_code']);
            $blog_id = intval($_POST['blog_id']);
            $items = json_decode(stripslashes($_POST['items']), true);
            $note = sanitize_text_field($_POST['note']);

            if ($blog_id <= 0) {
                throw new Exception("Blog ID không hợp lệ: {$blog_id}");
            }

            $creator = new TGS_IT_Voucher_Creator($blog_id);
            $result = $creator->create_vouchers($voucher_code, $site_code, $items, $note);

            wp_send_json_success($result);

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_get_deployment_shops() {
        check_ajax_referer('tgs_internal_transfer_nonce', 'nonce');

        try {
            global $wpdb;
            $table = $wpdb->base_prefix . 'global_deployment_shops';

            $shops = $wpdb->get_results(
                "SELECT * FROM {$table} WHERE is_deleted = 0 ORDER BY created_at DESC",
                ARRAY_A
            );

            wp_send_json_success(array('shops' => $shops));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_save_deployment_shop() {
        check_ajax_referer('tgs_internal_transfer_nonce', 'nonce');

        try {
            global $wpdb;
            $table = $wpdb->base_prefix . 'global_deployment_shops';

            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $tgs_site_code = sanitize_text_field($_POST['tgs_site_code']);
            $note = sanitize_text_field($_POST['note']);
            $is_active = intval($_POST['is_active']);

            // Tìm blog_id từ tgs_site_code
            $blog = $wpdb->get_row($wpdb->prepare(
                "SELECT blog_id FROM {$wpdb->blogs} WHERE tgs_site_code = %s",
                $tgs_site_code
            ));

            if (!$blog) {
                throw new Exception("Không tìm thấy website với mã: {$tgs_site_code}");
            }

            // Lấy tên shop
            switch_to_blog($blog->blog_id);
            $shop_name = get_bloginfo('name');
            restore_current_blog();

            $data = array(
                'tgs_site_code' => $tgs_site_code,
                'blog_id' => $blog->blog_id,
                'shop_name' => $shop_name,
                'note' => $note,
                'is_active' => $is_active,
                'user_id' => get_current_user_id(),
                'updated_at' => current_time('mysql'),
            );

            if ($id > 0) {
                // Update
                $wpdb->update($table, $data, array('id' => $id));
            } else {
                // Insert
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($table, $data);
                $id = $wpdb->insert_id;
            }

            wp_send_json_success(array('id' => $id, 'message' => 'Đã lưu thành công'));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_delete_deployment_shop() {
        check_ajax_referer('tgs_internal_transfer_nonce', 'nonce');

        try {
            global $wpdb;
            $table = $wpdb->base_prefix . 'global_deployment_shops';
            $id = intval($_POST['id']);

            $wpdb->update(
                $table,
                array('is_deleted' => 1, 'deleted_at' => current_time('mysql')),
                array('id' => $id)
            );

            wp_send_json_success(array('message' => 'Đã xóa thành công'));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}

// Initialize plugin
add_action('plugins_loaded', array('TGS_Internal_Transfer', 'get_instance'));
