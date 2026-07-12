<?php
/**
 * Class TGS_IT_Excel_Parser
 *
 * Parse Excel data từ HTsoft và nhóm theo phiếu + website
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_IT_Excel_Parser {

    /**
     * Parse và nhóm dữ liệu Excel theo (Số phiếu + Kho nhập)
     *
     * @param array $excel_data Dữ liệu từ SheetJS
     * @param string $selected_sheet Tên sheet được chọn
     * @return array
     */
    public function parse_and_group($excel_data, $selected_sheet) {
        global $wpdb;

        if (!isset($excel_data[$selected_sheet])) {
            throw new Exception("Sheet '{$selected_sheet}' không tồn tại trong file Excel");
        }

        $rows = $excel_data[$selected_sheet];

        if (empty($rows)) {
            throw new Exception("Sheet không có dữ liệu");
        }

        // Lấy danh sách shop đang triển khai
        $deployment_table = $wpdb->base_prefix . 'global_deployment_shops';
        $deployment_shops = $wpdb->get_results(
            "SELECT tgs_site_code, blog_id, shop_name FROM {$deployment_table} WHERE is_active = 1 AND is_deleted = 0",
            OBJECT_K
        );

        if (empty($deployment_shops)) {
            throw new Exception("Chưa có shop nào trong danh sách triển khai. Vui lòng thêm shop trước.");
        }

        // Debug: Log danh sách shop
        error_log("=== Deployment Shops ===");
        foreach ($deployment_shops as $code => $shop) {
            error_log("Code: {$code}, Blog ID: {$shop->blog_id}, Name: {$shop->shop_name}");
        }

        // Lấy danh sách phiếu đã tạo
        $tracker_table = $wpdb->base_prefix . 'global_transfer_voucher_tracker';
        $created_vouchers = $wpdb->get_results(
            "SELECT voucher_code, site_code FROM {$tracker_table}",
            ARRAY_A
        );

        $created_voucher_map = array();
        foreach ($created_vouchers as $v) {
            $key = $v['voucher_code'] . '_' . $v['site_code'];
            $created_voucher_map[$key] = true;
        }

        // Parse data
        $grouped_data = array();
        $header_found = false;
        $col_indices = array();

        foreach ($rows as $row) {
            // Tìm header row
            if (!$header_found) {
                $col_indices = $this->find_columns($row);
                if ($col_indices) {
                    $header_found = true;
                }
                continue;
            }

            // Lấy giá trị từ các cột
            $confirmed = $this->get_cell_value($row, $col_indices['confirmed_col']);
            $voucher_code = $this->get_cell_value($row, $col_indices['voucher_col']);
            $warehouse_in = $this->get_cell_value($row, $col_indices['warehouse_in_col']);
            $sku = $this->get_cell_value($row, $col_indices['sku_col']);
            $product_name = $this->get_cell_value($row, $col_indices['name_col']);
            $quantity = $this->get_cell_value($row, $col_indices['qty_in_col']);
            $note = $this->get_cell_value($row, $col_indices['note_col']);

            error_log("=== Processing Row ===");
            error_log("Confirmed: {$confirmed}, Voucher: {$voucher_code}, Warehouse: {$warehouse_in}, SKU: {$sku}");

            // Bỏ qua dòng không hợp lệ
            if (empty($voucher_code) || empty($sku)) {
                error_log("SKIP: Empty voucher or SKU");
                continue;
            }

            // Kiểm tra cột 1: Đã xác nhận = TRUE (hoặc 1 nếu Excel convert boolean)
            $confirmed_normalized = strtoupper(trim($confirmed));
            if ($confirmed_normalized !== 'TRUE' && $confirmed != '1' && $confirmed !== 1) {
                error_log("SKIP: Confirmed != TRUE (value: {$confirmed})");
                continue;
            }

            // Chuẩn hóa kho nhập (bỏ số 0 đầu)
            $warehouse_in = ltrim($warehouse_in, '0');
            if (empty($warehouse_in)) {
                $warehouse_in = '0';
            }

            // Debug: Log warehouse_in
            error_log("Row SKU: {$sku}, Warehouse In (raw): " . $this->get_cell_value($row, $col_indices['warehouse_in_col']) . ", Normalized: {$warehouse_in}");

            // Kiểm tra kho nhập có trong danh sách triển khai không
            if (!isset($deployment_shops[$warehouse_in])) {
                error_log("Warehouse {$warehouse_in} NOT in deployment list - SKIPPED");
                continue;
            }

            // Kiểm tra phiếu đã được tạo chưa
            $voucher_key = $voucher_code . '_' . $warehouse_in;
            if (isset($created_voucher_map[$voucher_key])) {
                continue;
            }

            // Chuẩn hóa quantity
            $quantity = $this->parse_quantity($quantity);

            // Nhóm theo (voucher_code + warehouse_in)
            if (!isset($grouped_data[$voucher_key])) {
                $shop_info = $deployment_shops[$warehouse_in];
                $grouped_data[$voucher_key] = array(
                    'voucher_code' => $voucher_code,
                    'site_code' => $warehouse_in,
                    'blog_id' => $shop_info->blog_id,
                    'shop_name' => $shop_info->shop_name,
                    'items' => array(),
                );
            }

            $grouped_data[$voucher_key]['items'][] = array(
                'sku' => trim($sku),
                'product_name' => trim($product_name),
                'quantity' => $quantity,
                'note' => trim($note),
            );
        }

        if (!$header_found) {
            throw new Exception("Không tìm thấy header trong Excel. Cần các cột: Đã xác nhận, Số phiếu, Kho nhập, Mã hàng, SL nhập");
        }

        // Chuyển sang array indexed và thêm thống kê
        $result = array();
        foreach ($grouped_data as $data) {
            $result[] = array(
                'voucher_code' => $data['voucher_code'],
                'site_code' => $data['site_code'],
                'blog_id' => $data['blog_id'],
                'shop_name' => $data['shop_name'],
                'items' => $data['items'],
                'total_items' => count($data['items']),
            );
        }

        // Sắp xếp theo voucher_code
        usort($result, function($a, $b) {
            return strcmp($a['voucher_code'], $b['voucher_code']);
        });

        return array(
            'tabs' => $result,
            'total_vouchers' => count($result),
        );
    }

    /**
     * Tìm các cột cần thiết trong header - Dựa theo thứ tự cột cố định từ HTsoft
     */
    private function find_columns($row) {
        error_log("=== Checking Row for Header ===");
        error_log("Row count: " . count($row));
        error_log("Col 0: " . (isset($row[0]) ? $row[0] : 'EMPTY'));
        error_log("Col 1: " . (isset($row[1]) ? $row[1] : 'EMPTY'));

        // Kiểm tra xem có phải header row không (có ít nhất 10 cột)
        if (count($row) < 10) {
            error_log("Row has less than 10 columns - NOT HEADER");
            return null;
        }

        // Check cột 1 và 2 có giống header không
        $col0 = mb_strtolower(trim($row[0]));
        $col1 = mb_strtolower(trim($row[1]));

        $is_header = (strpos($col0, 'xác nhận') !== false || strpos($col0, 'confirmed') !== false) &&
                     (strpos($col1, 'phiếu') !== false || strpos($col1, 'voucher') !== false);

        error_log("Col0 normalized: {$col0}");
        error_log("Col1 normalized: {$col1}");
        error_log("Is header: " . ($is_header ? 'YES' : 'NO'));

        if (!$is_header) {
            return null;
        }

        error_log("=== HEADER FOUND ===");

        // Mapping cố định theo thứ tự cột từ HTsoft Excel
        return array(
            'confirmed_col' => 0,   // Cột A: Đã xác nhận (index 0)
            'voucher_col' => 1,     // Cột B: Số phiếu (index 1)
            'warehouse_in_col' => 6, // Cột G: Kho nhập (index 6)
            'sku_col' => 7,         // Cột H: Mã hàng (index 7)
            'name_col' => 8,        // Cột I: Tên hàng (index 8)
            'qty_in_col' => 10,     // Cột K: SL nhập (index 10)
            'note_col' => 13,       // Cột N: Diễn giải (index 13)
        );
    }

    /**
     * Lấy giá trị cell
     */
    private function get_cell_value($row, $col_index) {
        if ($col_index === null || !isset($row[$col_index])) {
            return '';
        }
        return trim($row[$col_index]);
    }

    /**
     * Parse quantity từ string sang float
     */
    private function parse_quantity($value) {
        if (empty($value)) {
            return 0;
        }

        // Xóa dấu phẩy ngăn cách hàng nghìn
        $value = str_replace(',', '', $value);

        // Convert sang float
        return floatval($value);
    }
}
