<?php
/**
 * Class TGS_IT_Voucher_Creator
 *
 * Tạo phiếu mua nội bộ (type 13) + phiếu nhập tự động (type 1) cho website đích
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ném ra khi phiếu (voucher_code + site_code) đã được người khác tạo trước đó.
 * Tách riêng để tầng AJAX trả về mã lỗi 'already_created' cho JS xử lý.
 */
class TGS_IT_Duplicate_Voucher_Exception extends Exception {}

class TGS_IT_Voucher_Creator {

    private $blog_id;
    private $wpdb;
    private $created_products = array();

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
        $this->created_products = array();

        // Chặn sớm (không lock): phiếu đã tạo xong từ trước thì báo luôn,
        // tránh phải chờ lock ở bước claim bên dưới.
        $existing = $this->find_tracker_row($voucher_code, $site_code);
        if ($existing) {
            throw new TGS_IT_Duplicate_Voucher_Exception($this->duplicate_message($existing));
        }

        // Switch sang blog đích
        switch_to_blog($this->blog_id);

        try {
            $this->wpdb->query('START TRANSACTION');

            // 0. Chốt chỗ trong tracker TRƯỚC khi tạo bất cứ thứ gì.
            //    UNIQUE KEY (voucher_code, site_code) chính là chốt chống trùng
            //    ở tầng DB: 2 người bấm cùng lúc thì người sau bị chặn ngay,
            //    chưa tạo phiếu/dòng hàng nào nên không có gì phải dọn.
            $tracker_id = $this->claim_tracker_slot($voucher_code, $site_code);

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

            // 8. Ghi ID phiếu vào dòng tracker đã chốt ở bước 0
            $this->update_tracker_ledger_ids(
                $tracker_id,
                $purchase_ledger_id,
                $import_ledger_id,
                $this->created_products
            );

            $this->wpdb->query('COMMIT');

            restore_current_blog();

            return array(
                'purchase_ledger_id' => $purchase_ledger_id,
                'import_ledger_id' => $import_ledger_id,
                'transfer_ledger_id' => $transfer_ledger_id,
                'total_items' => count($item_ids),
                'created_products' => $this->created_products,
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
            $product = $this->find_or_create_product($item);

            $data = array(
                'local_ledger_id' => $ledger_id,
                'global_product_name_id' => $product->global_product_name_id,
                'local_product_sku' => $product->global_product_sku,
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
     * Lấy sản phẩm theo SKU hoặc tự tạo từ Mã hàng + Tên hàng trong file Excel.
     *
     * Sản phẩm được tạo trong cùng transaction với phiếu, nên sẽ tự rollback nếu
     * việc tạo phiếu thất bại ở bước sau.
     */
    private function find_or_create_product($item) {
        $table = $this->wpdb->base_prefix . 'global_product_name';
        $sku = sanitize_text_field(trim((string) ($item['sku'] ?? '')));
        $product_name = sanitize_text_field(trim((string) ($item['product_name'] ?? '')));

        if ($sku === '') {
            throw new Exception('Mã hàng không được để trống.');
        }

        // Lấy cả bản ghi đã xóa mềm để có thể khôi phục, tránh lỗi trùng SKU.
        $product = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT global_product_name_id, global_product_name, global_product_sku, is_deleted
             FROM {$table}
             WHERE global_product_sku = %s
             LIMIT 1",
            $sku
        ));

        if ($product && intval($product->is_deleted) === 0) {
            return $product;
        }

        if ($product_name === '') {
            throw new Exception("Không thể tạo sản phẩm SKU {$sku}: thiếu Tên hàng.");
        }

        $now = current_time('mysql');

        if ($product) {
            $updated = $this->wpdb->update(
                $table,
                array(
                    'global_blog_id' => get_current_blog_id(),
                    'global_product_name' => $product_name,
                    'global_product_status' => '1',
                    'is_deleted' => 0,
                    'deleted_at' => null,
                    'updated_at' => $now,
                ),
                array('global_product_name_id' => $product->global_product_name_id)
            );

            if ($updated === false) {
                throw new Exception("Không thể khôi phục sản phẩm SKU {$sku}: {$this->wpdb->last_error}");
            }

            $product->global_product_name = $product_name;
            $product->global_product_sku = $sku;
            $product->is_deleted = 0;
            return $product;
        }

        $meta = wp_json_encode(array(
            'sku' => $sku,
            'weight' => 0,
            'unit' => 'cái',
            'brand' => '',
            'origin' => '',
            'gallery' => array(),
        ), JSON_UNESCAPED_UNICODE);

        $inserted = $this->wpdb->insert($table, array(
            'global_blog_id' => get_current_blog_id(),
            'global_product_thumbnail' => '',
            'global_product_name' => $product_name,
            'global_product_description' => '',
            'global_product_content' => '',
            'global_product_status' => '1',
            'global_product_meta' => $meta,
            'global_product_point' => 0,
            'global_product_barcode_url_main' => '',
            'global_product_is_tracking' => 0,
            'global_product_tag' => 0,
            'user_id' => get_current_user_id(),
            'is_deleted' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'global_product_price' => 0,
            'global_product_tax' => 0,
            'global_product_price_after_tax' => 0,
            'global_product_sku' => $sku,
            'global_product_unit' => 'cái',
            'global_product_category_path' => '',
            'global_product_barcode_main' => '',
            'global_product_list_category_id' => wp_json_encode(array()),
            'weighted_avg_cost' => 0,
        ));

        if ($inserted !== false) {
            $this->created_products[$sku] = $product_name;

            return (object) array(
                'global_product_name_id' => $this->wpdb->insert_id,
                'global_product_name' => $product_name,
                'global_product_sku' => $sku,
                'is_deleted' => 0,
            );
        }

        // Nếu hai request đồng thời cùng tạo một SKU, dùng bản ghi request kia vừa tạo.
        $insert_error = $this->wpdb->last_error;
        $product = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT global_product_name_id, global_product_name, global_product_sku, is_deleted
             FROM {$table}
             WHERE global_product_sku = %s AND is_deleted = 0
             LIMIT 1",
            $sku
        ));

        if ($product) {
            return $product;
        }

        throw new Exception("Không thể tạo sản phẩm SKU {$sku}: {$insert_error}");
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
     * Tên bảng tracker (bảng global, dùng base_prefix nên không đổi khi switch_to_blog)
     */
    private function tracker_table() {
        return $this->wpdb->base_prefix . 'global_transfer_voucher_tracker';
    }

    /**
     * Tìm dòng tracker của 1 phiếu (nếu đã tạo)
     */
    private function find_tracker_row($voucher_code, $site_code) {
        $table = $this->tracker_table();

        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, blog_id, purchase_ledger_id, import_ledger_id, user_id, created_at
             FROM {$table}
             WHERE voucher_code = %s AND site_code = %s",
            $voucher_code,
            $site_code
        ));
    }

    /**
     * Chốt chỗ trong tracker trước khi tạo phiếu.
     *
     * Insert này chạy trong transaction, dựa vào UNIQUE KEY (voucher_code, site_code):
     * - Nếu người khác đang tạo dở cùng phiếu, InnoDB giữ lock -> request này chờ,
     *   rồi nhận lỗi duplicate khi bên kia commit (hoặc chốt được nếu bên kia rollback).
     * - Nếu phiếu đã tạo xong -> lỗi duplicate ngay.
     *
     * @return int ID dòng tracker vừa chốt
     * @throws TGS_IT_Duplicate_Voucher_Exception|Exception
     */
    private function claim_tracker_slot($voucher_code, $site_code) {
        $table = $this->tracker_table();

        $data = array(
            'voucher_code' => $voucher_code,
            'site_code' => $site_code,
            'blog_id' => $this->blog_id,
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        );

        // Tắt in lỗi SQL: lỗi duplicate là tình huống bình thường ở đây, và nếu
        // để WP echo ra thì HTML lỗi sẽ lẫn vào JSON response của AJAX.
        $suppress = $this->wpdb->suppress_errors(true);

        // Nếu người khác đang tạo dở cùng phiếu, insert này sẽ phải chờ lock.
        // Hạ timeout xuống để báo lỗi sớm thay vì treo request tới 50s (mặc định).
        $this->wpdb->query('SET SESSION innodb_lock_wait_timeout = 15');

        $inserted = $this->wpdb->insert($table, $data);
        $last_error = $this->wpdb->last_error;
        $this->wpdb->suppress_errors($suppress);

        if ($inserted) {
            return $this->wpdb->insert_id;
        }

        if (stripos($last_error, 'duplicate') !== false) {
            $existing = $this->find_tracker_row($voucher_code, $site_code);
            throw new TGS_IT_Duplicate_Voucher_Exception($this->duplicate_message($existing));
        }

        // Lock wait timeout / deadlock = người khác đang tạo phiếu này ngay lúc này.
        // Không có gì được ghi (transaction sẽ rollback), chỉ cần thử lại sau.
        if (stripos($last_error, 'lock wait timeout') !== false || stripos($last_error, 'deadlock') !== false) {
            throw new Exception(
                "Phiếu {$voucher_code} (kho {$site_code}) đang được người khác tạo ngay lúc này. "
                . "Chưa có dữ liệu nào được ghi - vui lòng chờ vài giây rồi kiểm tra tab \"Lịch sử phiếu đã tạo\"."
            );
        }

        throw new Exception("Không ghi được tracker phiếu {$voucher_code} / kho {$site_code}: {$last_error}");
    }

    /**
     * Ghi ID 2 phiếu vào dòng tracker đã chốt
     */
    private function update_tracker_ledger_ids($tracker_id, $purchase_ledger_id, $import_ledger_id, $created_products) {
        $updated = $this->wpdb->update(
            $this->tracker_table(),
            array(
                'purchase_ledger_id' => $purchase_ledger_id,
                'import_ledger_id' => $import_ledger_id,
                'created_product_skus' => wp_json_encode((object) $created_products, JSON_UNESCAPED_UNICODE),
            ),
            array('id' => $tracker_id)
        );

        if ($updated === false) {
            throw new Exception("Không cập nhật được tracker (ID {$tracker_id}): " . $this->wpdb->last_error);
        }
    }

    /**
     * Thông báo cho trường hợp phiếu đã được người khác tạo
     */
    private function duplicate_message($existing) {
        if (!$existing) {
            return 'Phiếu này đã được tạo trước đó, đã bỏ qua để tránh tạo trùng.';
        }

        $who = '';
        if (!empty($existing->user_id)) {
            $user = get_userdata($existing->user_id);
            if ($user) {
                $who = ' bởi ' . $user->display_name;
            }
        }

        $when = !empty($existing->created_at)
            ? date_i18n('d/m/Y H:i', strtotime($existing->created_at))
            : '';

        return sprintf(
            'Phiếu này đã được tạo%s%s (phiếu mua nội bộ ID %s). Đã bỏ qua để tránh tạo trùng.',
            $who,
            $when ? ' lúc ' . $when : '',
            $existing->purchase_ledger_id ?: '-'
        );
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
