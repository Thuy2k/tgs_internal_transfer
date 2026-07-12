<?php
/**
 * Class TGS_IT_Voucher_Creator
 *
 * Tạo phiếu mua nội bộ (type 13) + phiếu nhập tự động (type 1) cho website đích
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_IT_Voucher_Creator {

    private $blog_id;
    private $wpdb;

    public function __construct($blog_id) {
        global $wpdb;
        $this->blog_id = $blog_id;
        $this->wpdb = $wpdb;
    }

    /**
     * Tạo phiếu mua nội bộ + phiếu nhập tự động
     *
     * @param string $voucher_code Mã phiếu từ Excel
     * @param string $site_code Mã kho nhập
     * @param array $items Danh sách items
     * @param string $note Ghi chú chung
     * @return array
     */
    public function create_vouchers($voucher_code, $site_code, $items, $note) {
        // Switch sang blog đích
        switch_to_blog($this->blog_id);

        try {
            $this->wpdb->query('START TRANSACTION');

            // 1. Tạo phiếu Mua Nội Bộ (type 13)
            $purchase_ledger_id = $this->create_purchase_ledger($voucher_code, $note);

            // 2. Tạo phiếu Nhập tự động (type 1)
            $import_ledger_id = $this->create_import_ledger($voucher_code, $purchase_ledger_id);

            // 3. Tạo items cho phiếu nhập
            $item_ids = $this->create_ledger_items($import_ledger_id, $items);

            // 4. Cập nhật local_ledger_item_id cho cả 2 phiếu
            $this->update_ledger_items($purchase_ledger_id, $item_ids);
            $this->update_ledger_items($import_ledger_id, $item_ids);

            // 5. Tạo ledger meta cho phiếu mua nội bộ
            $meta_id = $this->create_ledger_meta($purchase_ledger_id, $import_ledger_id, $items);

            // 6. Cập nhật local_ledger_meta_id cho phiếu mua nội bộ
            $this->update_ledger_meta_id($purchase_ledger_id, $meta_id);

            // 7. Tạo bản ghi transfer_ledger
            $transfer_ledger_id = $this->create_transfer_ledger($purchase_ledger_id, $item_ids);

            // 8. Lưu vào tracker
            $this->save_to_tracker($voucher_code, $site_code, $purchase_ledger_id, $import_ledger_id);

            $this->wpdb->query('COMMIT');

            restore_current_blog();

            return array(
                'purchase_ledger_id' => $purchase_ledger_id,
                'import_ledger_id' => $import_ledger_id,
                'transfer_ledger_id' => $transfer_ledger_id,
                'total_items' => count($item_ids),
                'message' => 'Tạo phiếu thành công',
            );

        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            restore_current_blog();
            throw $e;
        }
    }

    /**
     * Tạo phiếu Mua Nội Bộ (type 13)
     */
    private function create_purchase_ledger($voucher_code, $note) {
        $table = $this->wpdb->prefix . 'local_ledger';
        $code = $this->generate_code('MNB');
        $title = "Phiếu mua nhập từ mã phiếu {$voucher_code}";

        if (empty($note)) {
            $note = $title;
        }

        $data = array(
            'local_ledger_code' => $code,
            'local_ledger_code_source' => $voucher_code,
            'local_ledger_title' => $title,
            'local_ledger_type' => 13, // Mua nội bộ
            'local_ledger_status' => 4, // Hoàn thành
            'local_ledger_approver_id' => get_current_user_id(),
            'local_ledger_approver_status' => 1, // Đã duyệt
            'local_ledger_note' => $note,
            'user_id' => get_current_user_id(),
            'is_deleted' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        $this->wpdb->insert($table, $data);
        return $this->wpdb->insert_id;
    }

    /**
     * Tạo phiếu Nhập tự động (type 1)
     */
    private function create_import_ledger($voucher_code, $parent_id) {
        $table = $this->wpdb->prefix . 'local_ledger';
        $code = $this->generate_code('AMN');
        $title = "Phiếu nhập từ kho";

        $data = array(
            'local_ledger_code' => $code,
            'local_ledger_code_source' => $voucher_code,
            'local_ledger_title' => $title,
            'local_ledger_type' => 1, // Nhập kho
            'local_ledger_parent_id' => $parent_id,
            'local_ledger_status' => 4, // Hoàn thành
            'local_ledger_approver_id' => 1,
            'local_ledger_approver_status' => 1,
            'user_id' => get_current_user_id(),
            'is_deleted' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        $this->wpdb->insert($table, $data);
        return $this->wpdb->insert_id;
    }

    /**
     * Tạo items cho phiếu nhập
     */
    private function create_ledger_items($ledger_id, $items) {
        $table = $this->wpdb->prefix . 'local_ledger_item';
        $item_ids = array();

        foreach ($items as $item) {
            // Query global_product_name_id từ SKU
            $product = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT global_product_name_id, global_product_name, global_product_sku
                 FROM wp_global_product_name
                 WHERE global_product_sku = %s AND is_deleted = 0",
                $item['sku']
            ));

            if (!$product) {
                throw new Exception("Không tìm thấy sản phẩm với SKU: {$item['sku']}");
            }

            $data = array(
                'local_ledger_id' => $ledger_id,
                'global_product_name_id' => $product->global_product_name_id,
                'local_product_sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'local_ledger_item_note' => $item['note'],
                'local_ledger_item_type' => 1,
                'is_tracking' => 0,
                'user_id' => get_current_user_id(),
                'is_deleted' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            );

            $this->wpdb->insert($table, $data);
            $item_ids[] = $this->wpdb->insert_id;
        }

        return $item_ids;
    }

    /**
     * Tạo ledger meta với logs
     */
    private function create_ledger_meta($purchase_ledger_id, $import_ledger_id, $items) {
        $table = $this->wpdb->prefix . 'local_ledger_meta';

        // Lấy thông tin user hiện tại
        $current_user = wp_get_current_user();
        $user_info = array(
            'user_id' => $current_user->ID,
            'user_name' => $current_user->display_name,
            'user_login' => $current_user->user_login,
            'user_email' => $current_user->user_email,
            'user_roles' => $current_user->roles,
        );

        // Lấy thông tin phiếu
        $ledger_table = $this->wpdb->prefix . 'local_ledger';
        $ledger = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$ledger_table} WHERE local_ledger_id = %d",
            $purchase_ledger_id
        ));

        // Tính tổng tiền
        $total_amount = 0;
        foreach ($items as $item) {
            $total_amount += floatval($item['quantity']) * 0; // Không có giá trong Excel
        }

        $timestamp = current_time('mysql');

        // Tạo logs
        $logs = array(
            array(
                'action' => 'approve',
                'user_id' => $current_user->ID,
                'user_name' => $current_user->display_name,
                'user_login' => $current_user->user_login,
                'user_email' => $current_user->user_email,
                'user_roles' => $current_user->roles,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '::1',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ticket_id' => $purchase_ledger_id,
                'ticket_code' => $ledger->local_ledger_code,
                'ticket_type' => 13,
                'ticket_type_name' => 'Mua nội bộ',
                'person_name' => '',
                'total_amount' => $total_amount,
                'timestamp' => $timestamp,
                'note' => 'Duyệt phiếu tự động',
                'data' => array(
                    'approver_id' => $current_user->ID,
                    'note' => '',
                ),
            ),
            array(
                'action' => 'create',
                'user_id' => $current_user->ID,
                'user_name' => $current_user->display_name,
                'user_login' => $current_user->user_login,
                'user_email' => $current_user->user_email,
                'user_roles' => $current_user->roles,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '::1',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ticket_id' => $purchase_ledger_id,
                'ticket_code' => $ledger->local_ledger_code,
                'ticket_type' => 13,
                'ticket_type_name' => 'Mua nội bộ',
                'person_name' => '',
                'total_amount' => $total_amount,
                'timestamp' => $timestamp,
                'note' => 'Tạo phiếu mua nội bộ từ HTsoft Excel',
                'data' => array(
                    'source_blog_id' => 17, // Chi nhánh Phú Thọ (fake)
                    'source_shop_name' => 'Chi nhánh Phú Thọ',
                    'items_count' => count($items),
                    'total_amount' => $total_amount,
                    'is_partial' => false,
                    'auto_import_ledger_id' => $import_ledger_id,
                    'auto_import_code' => '',
                    'transfer_type' => 'import',
                ),
            ),
        );

        $meta_value = json_encode(array('logs' => $logs));

        $data = array(
            'local_ledger_meta_value' => $meta_value,
            'user_id' => get_current_user_id(),
            'is_deleted' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        );

        $this->wpdb->insert($table, $data);
        return $this->wpdb->insert_id;
    }

    /**
     * Cập nhật local_ledger_meta_id cho phiếu
     */
    private function update_ledger_meta_id($ledger_id, $meta_id) {
        $table = $this->wpdb->prefix . 'local_ledger';

        $this->wpdb->update(
            $table,
            array('local_ledger_meta_id' => $meta_id),
            array('local_ledger_id' => $ledger_id)
        );
    }

    /**
     * Cập nhật local_ledger_item_id (JSON) cho phiếu
     */
    private function update_ledger_items($ledger_id, $item_ids) {
        $table = $this->wpdb->prefix . 'local_ledger';

        $this->wpdb->update(
            $table,
            array('local_ledger_item_id' => json_encode($item_ids)),
            array('local_ledger_id' => $ledger_id)
        );
    }

    /**
     * Tạo bản ghi transfer_ledger
     */
    private function create_transfer_ledger($purchase_ledger_id, $item_ids) {
        $table = $this->wpdb->prefix . 'transfer_ledger';
        $timestamp = current_time('mysql');
        $user_id = get_current_user_id();

        $data = array(
            'source_blog_id' => 17, // Chi nhánh Phú Thọ
            'source_ledger_id' => 0, // Không có phiếu xuất thực tế
            'source_ledger_item_id' => json_encode([]),
            'destination_blog_id' => $this->blog_id,
            'destination_ledger_id' => $purchase_ledger_id,
            'destination_ledger_item_id' => json_encode($item_ids),
            'transfer_status' => 1,
            'transfer_type' => 1,
            'created_at' => $timestamp,
            'accepted_at' => $timestamp,
            'created_by_user_id' => $user_id,
            'accepted_by_user_id' => $user_id,
            'updated_at' => $timestamp,
            'is_deleted' => 0,
        );

        $this->wpdb->insert($table, $data);
        return $this->wpdb->insert_id;
    }

    /**
     * Lưu vào bảng tracker
     */
    private function save_to_tracker($voucher_code, $site_code, $purchase_ledger_id, $import_ledger_id) {
        $table = $this->wpdb->base_prefix . 'global_transfer_voucher_tracker';

        $data = array(
            'voucher_code' => $voucher_code,
            'site_code' => $site_code,
            'blog_id' => $this->blog_id,
            'purchase_ledger_id' => $purchase_ledger_id,
            'import_ledger_id' => $import_ledger_id,
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        );

        $this->wpdb->insert($table, $data);
    }

    /**
     * Generate mã phiếu ngẫu nhiên
     */
    private function generate_code($prefix) {
        $date = date('ymd'); // 260712
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
        return "{$prefix}-{$date}-{$random}";
    }
}
