<?php
/**
 * View: Chuyển kho nội bộ - Import phiếu chuyển từ HTsoft
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="tgs-page-header">
    <h1 class="page-title">
        <i class="bx bx-transfer me-2"></i>
        Chuyển kho nội bộ
    </h1>
    <p class="text-muted">Import phiếu chuyển từ HTsoft Excel và tạo phiếu mua nội bộ + nhập tự động cho từng shop</p>
</div>

<!-- Alert area -->
<div id="itAlert" class="alert d-none" role="alert">
    <i class="bx bx-info-circle me-2"></i>
    <span id="itAlertText"></span>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="import-tab" data-bs-toggle="tab" data-bs-target="#import-panel" type="button">
            <i class="bx bx-upload me-1"></i>Import Excel
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-panel" type="button">
            <i class="bx bx-history me-1"></i>Lịch sử phiếu đã tạo
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="shops-tab" data-bs-toggle="tab" data-bs-target="#shops-panel" type="button">
            <i class="bx bx-store me-1"></i>Quản lý Shop triển khai
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- Tab 1: Import Excel -->
    <div class="tab-pane fade show active" id="import-panel">
        <!-- Upload Card -->
        <div class="card mb-4" id="uploadCard">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="bx bx-upload me-2"></i>Bước 1: Upload file Excel từ HTsoft</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="itExcelFile" class="form-label">Chọn file Excel (.xlsx, .xls)</label>
                    <input type="file" class="form-control" id="itExcelFile" accept=".xlsx, .xls">
                    <div class="form-text">
                        File Excel phải có các cột: <strong>Đã xác nhận, Số phiếu, Kho nhập, Mã hàng, SL nhập, Diễn giải</strong>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="itSheetSelect" class="form-label">Chọn Sheet</label>
                    <select class="form-select" id="itSheetSelect" disabled>
                        <option value="">-- Chọn sheet --</option>
                    </select>
                </div>

                <button type="button" class="btn btn-primary" id="itAnalyzeBtn" disabled>
                    <i class="bx bx-analyse me-1"></i>Phân tích nhanh
                </button>
            </div>
        </div>

        <!-- Analysis Result -->
        <div class="card d-none" id="analysisCard">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bx bx-check-circle text-success me-2"></i>
                    Kết quả phân tích
                    <span class="badge bg-primary ms-2" id="totalVouchersBadge">0 phiếu</span>
                </h5>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                    <i class="bx bx-refresh me-1"></i>Upload file mới
                </button>
            </div>
            <div class="card-body p-0">
                <div class="row g-0">
                    <!-- Left sidebar: Tabs dọc -->
                    <div class="col-md-3 border-end it-sidebar">
                        <div class="p-3 bg-light border-bottom">
                            <h6 class="mb-0 text-muted fw-bold">DANH SÁCH PHIẾU</h6>
                        </div>
                        <div class="list-group list-group-flush it-voucher-list" id="voucherList">
                            <!-- Tabs sẽ được render vào đây -->
                        </div>
                    </div>

                    <!-- Right content: Chi tiết phiếu -->
                    <div class="col-md-9 it-content">
                        <div id="voucherTabsContent" class="p-4">
                            <div class="text-center text-muted py-5">
                                <i class="bx bx-info-circle" style="font-size: 48px;"></i>
                                <p class="mt-3">Chọn phiếu bên trái để xem chi tiết</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 2: Lịch sử phiếu đã tạo -->
    <div class="tab-pane fade" id="history-panel">
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label for="histDateFrom" class="form-label mb-1 small fw-bold">Từ ngày</label>
                        <input type="date" class="form-control" id="histDateFrom">
                    </div>
                    <div class="col-md-2">
                        <label for="histDateTo" class="form-label mb-1 small fw-bold">Đến ngày</label>
                        <input type="date" class="form-control" id="histDateTo">
                    </div>
                    <div class="col-md-4">
                        <label for="histSearch" class="form-label mb-1 small fw-bold">Tìm kiếm</label>
                        <input type="text" class="form-control" id="histSearch" placeholder="Mã shop, tên shop, mã phiếu hoặc SKU mới...">
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-primary" id="histFilterBtn">
                            <i class="bx bx-search me-1"></i>Lọc
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="histTodayBtn">Hôm nay</button>
                        <button type="button" class="btn btn-outline-secondary" id="histWeekBtn">7 ngày</button>
                        <button type="button" class="btn btn-outline-secondary" id="histAllBtn">Tất cả</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bx bx-history me-2"></i>Phiếu đã tạo
                    <span class="badge bg-primary ms-2" id="histTotalBadge">0 phiếu</span>
                    <span class="badge bg-info ms-1" id="histShopBadge">0 shop</span>
                </h5>
                <span class="text-muted small" id="histRangeLabel"></span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle" id="historyTable">
                        <thead class="table-light">
                            <tr>
                                <th>Ngày tạo</th>
                                <th>Mã phiếu HTsoft</th>
                                <th>Mã shop</th>
                                <th>Tên shop</th>
                                <th>Phiếu mua nội bộ</th>
                                <th>Phiếu nhập</th>
                                <th class="text-end">Số dòng</th>
                                <th class="text-end">Tổng SL</th>
                                <th>Mã hàng tạo mới</th>
                                <th>Người tạo</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    <i class="bx bx-loader bx-spin"></i> Đang tải...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <span class="text-muted small" id="histPageInfo"></span>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary" id="histPrevBtn" disabled>
                            <i class="bx bx-chevron-left"></i> Trước
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="histNextBtn" disabled>
                            Sau <i class="bx bx-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 3: Quản lý Shop triển khai -->
    <div class="tab-pane fade" id="shops-panel">
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bx bx-store me-2"></i>Danh sách Shop đang triển khai</h5>
                <button type="button" class="btn btn-sm btn-primary" id="addShopBtn">
                    <i class="bx bx-plus me-1"></i>Thêm Shop
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="shopsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Mã Shop</th>
                                <th>Tên Shop</th>
                                <th>Ghi chú</th>
                                <th>Trạng thái</th>
                                <th width="100">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody id="shopsTableBody">
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bx bx-loader bx-spin"></i> Đang tải...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Xác nhận tạo phiếu -->
<div class="modal fade" id="voucherModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-file-blank me-2"></i>Xác nhận tạo phiếu
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bx bx-info-circle me-2"></i>
                    Phiếu: <strong id="modalVoucherCode"></strong> - Shop: <strong id="modalShopName"></strong>
                    <br>Tổng: <strong><span id="modalItemCount">0</span> sản phẩm</strong>
                </div>

                <div class="mb-3">
                    <label for="voucherNote" class="form-label">Ghi chú phiếu (tùy chọn)</label>
                    <textarea class="form-control" id="voucherNote" rows="2" placeholder="Nhập ghi chú..."></textarea>
                </div>

                <div class="table-responsive" style="max-height: 400px;">
                    <table class="table table-sm table-hover">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>SKU</th>
                                <th>Tên sản phẩm</th>
                                <th class="text-end">Số lượng</th>
                                <th>Diễn giải</th>
                            </tr>
                        </thead>
                        <tbody id="voucherItemsPreview"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bx bx-x me-1"></i>Hủy
                </button>
                <button type="button" class="btn btn-success" id="confirmVoucherBtn">
                    <i class="bx bx-check me-1"></i>Tạo phiếu
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Thêm/Sửa Shop -->
<div class="modal fade" id="shopModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="shopModalTitle">Thêm Shop triển khai</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="shopId">
                <div class="mb-3">
                    <label for="shopCode" class="form-label">Mã Shop (tgs_site_code) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="shopCode" placeholder="Ví dụ: 8004" required>
                    <div class="form-text">Mã shop trong cột tgs_site_code của bảng wp_blogs</div>
                </div>
                <div class="mb-3">
                    <label for="shopNote" class="form-label">Ghi chú</label>
                    <textarea class="form-control" id="shopNote" rows="2" placeholder="Ghi chú về tiến độ triển khai..."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Trạng thái</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="shopIsActive" checked>
                        <label class="form-check-label" for="shopIsActive">
                            Đang triển khai
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="button" class="btn btn-primary" id="saveShopBtn">Lưu</button>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let currentVoucherData = null;
let parsedData = null;
</script>
