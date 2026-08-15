<?php
/**
 * Plugin Name: TGS Internal Transfer
 * Plugin URI: https://bizgpt.vn/
 * Description: Plugin quản lý chuyển kho nội bộ từ HTsoft Excel - Tạo phiếu mua nội bộ và nhập tự động
 * Version: 1.1.1
 * Author: BIZGPT_AI
 * Author URI: https://bizgpt.vn/
 * License: GPL v2 or later
 * Text Domain: tgs-internal-transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('TGS_INTERNAL_TRANSFER_VERSION', '1.1.1');
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
        add_action('wp_ajax_tgs_it_get_history', array($this, 'ajax_get_history'));

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

    /**
     * Kiểm tra nonce + quyền cho mọi AJAX của plugin
     */
    private function check_request() {
        check_ajax_referer('tgs_internal_transfer_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện thao tác này'), 403);
        }
    }

    public function ajax_parse_excel() {
        $this->check_request();

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
        $this->check_request();

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

        } catch (TGS_IT_Duplicate_Voucher_Exception $e) {
            // Phiếu đã được người khác tạo -> không phải lỗi, chỉ cần báo để UI đánh dấu
            wp_send_json_error(array(
                'code' => 'already_created',
                'message' => $e->getMessage(),
            ));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_get_deployment_shops() {
        $this->check_request();

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
        $this->check_request();

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
        $this->check_request();

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

    /**
     * Lịch sử phiếu đã tạo - lọc theo ngày tạo + tìm theo mã shop / tên shop / mã phiếu
     */
    public function ajax_get_history() {
        $this->check_request();

        try {
            global $wpdb;

            $tracker_table = $wpdb->base_prefix . 'global_transfer_voucher_tracker';
            $shops_table = $wpdb->base_prefix . 'global_deployment_shops';

            $date_from = $this->sanitize_date(isset($_POST['date_from']) ? $_POST['date_from'] : '');
            $date_to = $this->sanitize_date(isset($_POST['date_to']) ? $_POST['date_to'] : '');
            $search = isset($_POST['search']) ? trim(sanitize_text_field($_POST['search'])) : '';
            $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
            $per_page = isset($_POST['per_page']) ? min(200, max(10, intval($_POST['per_page']))) : 50;

            $where = array('1=1');
            $args = array();

            if ($date_from) {
                $where[] = 't.created_at >= %s';
                $args[] = $date_from . ' 00:00:00';
            }
            if ($date_to) {
                $where[] = 't.created_at <= %s';
                $args[] = $date_to . ' 23:59:59';
            }
            if ($search !== '') {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $where[] = '(t.site_code LIKE %s OR s.shop_name LIKE %s OR t.voucher_code LIKE %s OR t.created_product_skus LIKE %s)';
                $args[] = $like;
                $args[] = $like;
                $args[] = $like;
                $args[] = $like;
            }

            $where_sql = implode(' AND ', $where);

            $from_sql = "FROM {$tracker_table} t
                         LEFT JOIN {$shops_table} s ON s.tgs_site_code = t.site_code
                         WHERE {$where_sql}";

            // Tổng quan: bao nhiêu phiếu, bao nhiêu shop trong khoảng lọc
            $summary_sql = "SELECT COUNT(*) AS total_vouchers,
                                   COUNT(DISTINCT t.site_code) AS total_shops
                            {$from_sql}";
            $summary = $wpdb->get_row(
                $args ? $wpdb->prepare($summary_sql, $args) : $summary_sql,
                ARRAY_A
            );

            $total = intval($summary['total_vouchers']);

            $rows_sql = "SELECT t.id, t.voucher_code, t.site_code, t.blog_id,
                                t.purchase_ledger_id, t.import_ledger_id,
                                t.created_product_skus, t.user_id, t.created_at,
                                s.shop_name, s.is_active AS shop_is_active,
                                u.display_name AS user_name
                         FROM {$tracker_table} t
                         LEFT JOIN {$shops_table} s ON s.tgs_site_code = t.site_code
                         LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
                         WHERE {$where_sql}
                         ORDER BY t.created_at DESC, t.id DESC
                         LIMIT %d OFFSET %d";

            $rows_args = array_merge($args, array($per_page, ($page - 1) * $per_page));
            $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $rows_args), ARRAY_A);

            foreach ($rows as &$row) {
                $stored_products = json_decode((string) $row['created_product_skus']);
                $created_products = array();

                // Dữ liệu mới: {"SKU": "Tên sản phẩm"}.
                if (is_object($stored_products)) {
                    foreach (get_object_vars($stored_products) as $sku => $product_name) {
                        if (is_string($product_name)) {
                            $created_products[(string) $sku] = $product_name;
                        }
                    }
                } elseif (is_array($stored_products)) {
                    // Tương thích dữ liệu cũ: ["SKU1", "SKU2"].
                    foreach ($stored_products as $sku) {
                        if (is_string($sku)) {
                            $created_products[$sku] = '';
                        }
                    }
                }

                $row['created_products'] = (object) $created_products;
                unset($row['created_product_skus']);
            }
            unset($row);

            $rows = $this->enrich_history_rows($rows);

            wp_send_json_success(array(
                'rows' => $rows,
                'total' => $total,
                'total_shops' => intval($summary['total_shops']),
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
            ));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Bổ sung mã phiếu + số dòng hàng cho từng dòng lịch sử.
     * Phiếu nằm ở bảng của từng blog nên phải query theo nhóm blog_id.
     */
    private function enrich_history_rows($rows) {
        global $wpdb;

        if (empty($rows)) {
            return array();
        }

        $by_blog = array();
        foreach ($rows as $index => $row) {
            $by_blog[intval($row['blog_id'])][] = $index;
        }

        // Blog có thể đã bị xóa -> bảng không tồn tại, không để lỗi SQL echo ra JSON
        $suppress = $wpdb->suppress_errors(true);

        foreach ($by_blog as $blog_id => $indexes) {
            if ($blog_id <= 0) {
                continue;
            }

            $prefix = $wpdb->get_blog_prefix($blog_id);

            $ledger_ids = array();
            $import_ids = array();
            foreach ($indexes as $index) {
                if (!empty($rows[$index]['purchase_ledger_id'])) {
                    $ledger_ids[] = intval($rows[$index]['purchase_ledger_id']);
                }
                if (!empty($rows[$index]['import_ledger_id'])) {
                    $ledger_ids[] = intval($rows[$index]['import_ledger_id']);
                    $import_ids[] = intval($rows[$index]['import_ledger_id']);
                }
            }

            $ledgers = array();
            if ($ledger_ids) {
                $in = implode(',', array_unique($ledger_ids));
                $results = $wpdb->get_results(
                    "SELECT local_ledger_id, local_ledger_code, is_deleted
                     FROM {$prefix}local_ledger
                     WHERE local_ledger_id IN ({$in})",
                    ARRAY_A
                );
                foreach ((array) $results as $ledger) {
                    $ledgers[intval($ledger['local_ledger_id'])] = $ledger;
                }
            }

            $item_stats = array();
            if ($import_ids) {
                $in = implode(',', array_unique($import_ids));
                $results = $wpdb->get_results(
                    "SELECT local_ledger_id, COUNT(*) AS items_count, SUM(quantity) AS total_qty
                     FROM {$prefix}local_ledger_item
                     WHERE local_ledger_id IN ({$in}) AND is_deleted = 0
                     GROUP BY local_ledger_id",
                    ARRAY_A
                );
                foreach ((array) $results as $stat) {
                    $item_stats[intval($stat['local_ledger_id'])] = $stat;
                }
            }

            foreach ($indexes as $index) {
                $purchase_id = intval($rows[$index]['purchase_ledger_id']);
                $import_id = intval($rows[$index]['import_ledger_id']);

                $rows[$index]['purchase_ledger_code'] = isset($ledgers[$purchase_id])
                    ? $ledgers[$purchase_id]['local_ledger_code'] : '';
                $rows[$index]['import_ledger_code'] = isset($ledgers[$import_id])
                    ? $ledgers[$import_id]['local_ledger_code'] : '';
                $rows[$index]['is_ledger_deleted'] = isset($ledgers[$purchase_id])
                    ? intval($ledgers[$purchase_id]['is_deleted']) : 0;
                $rows[$index]['ledger_missing'] = ($purchase_id > 0 && !isset($ledgers[$purchase_id])) ? 1 : 0;
                $rows[$index]['items_count'] = isset($item_stats[$import_id])
                    ? intval($item_stats[$import_id]['items_count']) : 0;
                $rows[$index]['total_qty'] = isset($item_stats[$import_id])
                    ? floatval($item_stats[$import_id]['total_qty']) : 0;

                // Shop đã bị xóa khỏi danh sách triển khai -> lấy tên site làm fallback
                if (empty($rows[$index]['shop_name'])) {
                    $rows[$index]['shop_name'] = get_blog_option($blog_id, 'blogname', '');
                }
            }
        }

        $wpdb->suppress_errors($suppress);

        return $rows;
    }

    /**
     * Chỉ nhận ngày dạng YYYY-MM-DD
     */
    private function sanitize_date($value) {
        $value = trim(sanitize_text_field($value));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }
}

// Initialize plugin
add_action('plugins_loaded', array('TGS_Internal_Transfer', 'get_instance'));
