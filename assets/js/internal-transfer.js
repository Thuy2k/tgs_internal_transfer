jQuery(document).ready(function($) {
    'use strict';

    let excelData = null;
    let sheetNames = [];

    // ========== UPLOAD & PARSE EXCEL ==========
    $('#itExcelFile').on('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });

                sheetNames = workbook.SheetNames;
                excelData = {};

                workbook.SheetNames.forEach(function(sheetName) {
                    const worksheet = workbook.Sheets[sheetName];
                    excelData[sheetName] = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
                });

                // Populate sheet dropdown
                $('#itSheetSelect').html('<option value="">-- Chọn sheet --</option>');
                sheetNames.forEach(function(name) {
                    $('#itSheetSelect').append(`<option value="${name}">${name}</option>`);
                });

                $('#itSheetSelect').prop('disabled', false);
                showAlert('success', 'Đã tải file Excel thành công. Vui lòng chọn sheet.');
            } catch (error) {
                showAlert('danger', 'Lỗi đọc file Excel: ' + error.message);
            }
        };
        reader.readAsArrayBuffer(file);
    });

    $('#itSheetSelect').on('change', function() {
        if ($(this).val()) {
            $('#itAnalyzeBtn').prop('disabled', false);
        } else {
            $('#itAnalyzeBtn').prop('disabled', true);
        }
    });

    $('#itAnalyzeBtn').on('click', function() {
        const selectedSheet = $('#itSheetSelect').val();
        if (!selectedSheet || !excelData) {
            showAlert('danger', 'Vui lòng chọn sheet');
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="bx bx-loader bx-spin me-1"></i>Đang phân tích...');

        $.ajax({
            url: tgsInternalTransfer.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_it_parse_excel',
                nonce: tgsInternalTransfer.nonce,
                excel_data: JSON.stringify(excelData),
                selected_sheet: selectedSheet
            },
            success: function(response) {
                if (response.success) {
                    parsedData = response.data;
                    renderVoucherTabs(parsedData);
                    $('#uploadCard').addClass('d-none');
                    $('#analysisCard').removeClass('d-none');
                    showAlert('success', `Phân tích thành công: ${parsedData.total_vouchers} phiếu cần tạo`);
                } else {
                    showAlert('danger', response.data.message || 'Lỗi phân tích dữ liệu');
                    $btn.prop('disabled', false).html('<i class="bx bx-analyse me-1"></i>Phân tích nhanh');
                }
            },
            error: function() {
                showAlert('danger', 'Lỗi kết nối server');
                $btn.prop('disabled', false).html('<i class="bx bx-analyse me-1"></i>Phân tích nhanh');
            }
        });
    });

    // ========== RENDER VOUCHER TABS ==========
    function renderVoucherTabs(data) {
        $('#totalVouchersBadge').text(data.total_vouchers + ' phiếu');

        const $list = $('#voucherList');
        $list.empty();

        data.tabs.forEach(function(tab, index) {
            const $item = $(`
                <a href="#" class="list-group-item list-group-item-action" data-index="${index}">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">${tab.voucher_code}</h6>
                        <small class="badge bg-primary">${tab.total_items} SP</small>
                    </div>
                    <p class="mb-1 text-muted small">${tab.shop_name}</p>
                    <small class="text-muted">Mã: ${tab.site_code}</small>
                </a>
            `);

            $item.on('click', function(e) {
                e.preventDefault();
                $('.list-group-item').removeClass('active');
                $(this).addClass('active');
                renderVoucherContent(tab);
            });

            $list.append($item);
        });
    }

    function renderVoucherContent(tab) {
        currentVoucherData = tab;

        let html = `
            <div class="mb-4">
                <h4>${tab.voucher_code} - ${tab.shop_name}</h4>
                <p class="text-muted mb-3">Mã shop: ${tab.site_code} | Blog ID: ${tab.blog_id}</p>
                <div class="alert alert-info">
                    <i class="bx bx-info-circle me-2"></i>
                    <strong>${tab.total_items}</strong> sản phẩm sẽ được nhập vào shop này
                </div>
                <button type="button" class="btn btn-success" onclick="openVoucherModal()">
                    <i class="bx bx-plus-circle me-1"></i>Tạo phiếu mua nội bộ
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>STT</th>
                            <th>SKU</th>
                            <th>Tên sản phẩm</th>
                            <th class="text-end">Số lượng</th>
                            <th>Diễn giải</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        tab.items.forEach(function(item, index) {
            html += `
                <tr>
                    <td>${index + 1}</td>
                    <td><code>${item.sku}</code></td>
                    <td>${item.product_name}</td>
                    <td class="text-end"><strong>${item.quantity}</strong></td>
                    <td>${item.note}</td>
                </tr>
            `;
        });

        html += '</tbody></table></div>';
        $('#voucherTabsContent').html(html);
    }

    // ========== MODAL XÁC NHẬN TẠO PHIẾU ==========
    window.openVoucherModal = function() {
        if (!currentVoucherData) return;

        $('#modalVoucherCode').text(currentVoucherData.voucher_code);
        $('#modalShopName').text(currentVoucherData.shop_name);
        $('#modalItemCount').text(currentVoucherData.total_items);
        $('#voucherNote').val('');

        let html = '';
        currentVoucherData.items.forEach(function(item, index) {
            html += `
                <tr>
                    <td><code>${item.sku}</code></td>
                    <td>${item.product_name}</td>
                    <td class="text-end"><strong>${item.quantity}</strong></td>
                    <td>${item.note}</td>
                </tr>
            `;
        });
        $('#voucherItemsPreview').html(html);

        const modal = new bootstrap.Modal(document.getElementById('voucherModal'));
        modal.show();
    };

    $('#confirmVoucherBtn').on('click', function() {
        if (!currentVoucherData) return;

        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="bx bx-loader bx-spin me-1"></i>Đang tạo...');

        $.ajax({
            url: tgsInternalTransfer.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_it_create_vouchers',
                nonce: tgsInternalTransfer.nonce,
                voucher_code: currentVoucherData.voucher_code,
                site_code: currentVoucherData.site_code,
                blog_id: currentVoucherData.blog_id,
                items: JSON.stringify(currentVoucherData.items),
                note: $('#voucherNote').val()
            },
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.data.message);
                    bootstrap.Modal.getInstance(document.getElementById('voucherModal')).hide();

                    // Update UI: đánh dấu phiếu đã tạo
                    $('.list-group-item.active').addClass('list-group-item-success').append(
                        '<span class="badge bg-success ms-2">Đã tạo</span>'
                    ).off('click');

                    $('#voucherTabsContent').html(`
                        <div class="alert alert-success">
                            <i class="bx bx-check-circle me-2"></i>
                            <strong>Đã tạo phiếu thành công!</strong><br>
                            Phiếu mua nội bộ ID: ${response.data.purchase_ledger_id}<br>
                            Phiếu nhập tự động ID: ${response.data.import_ledger_id}
                        </div>
                    `);
                } else {
                    showAlert('danger', response.data.message || 'Lỗi tạo phiếu');
                }
                $btn.prop('disabled', false).html('<i class="bx bx-check me-1"></i>Tạo phiếu');
            },
            error: function() {
                showAlert('danger', 'Lỗi kết nối server');
                $btn.prop('disabled', false).html('<i class="bx bx-check me-1"></i>Tạo phiếu');
            }
        });
    });

    // ========== QUẢN LÝ SHOP TRIỂN KHAI ==========
    function loadDeploymentShops() {
        $.ajax({
            url: tgsInternalTransfer.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_it_get_deployment_shops',
                nonce: tgsInternalTransfer.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderShopsTable(response.data.shops);
                } else {
                    $('#shopsTableBody').html('<tr><td colspan="5" class="text-center text-danger">Lỗi tải dữ liệu</td></tr>');
                }
            }
        });
    }

    function renderShopsTable(shops) {
        const $tbody = $('#shopsTableBody');
        $tbody.empty();

        if (shops.length === 0) {
            $tbody.html('<tr><td colspan="5" class="text-center text-muted py-4">Chưa có shop nào</td></tr>');
            return;
        }

        shops.forEach(function(shop) {
            const statusBadge = shop.is_active == 1
                ? '<span class="badge bg-success">Đang triển khai</span>'
                : '<span class="badge bg-secondary">Tạm dừng</span>';

            const $row = $(`
                <tr>
                    <td><code>${shop.tgs_site_code}</code></td>
                    <td>${shop.shop_name || '-'}</td>
                    <td>${shop.note || '-'}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary edit-shop" data-id="${shop.id}">
                            <i class="bx bx-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger delete-shop" data-id="${shop.id}">
                            <i class="bx bx-trash"></i>
                        </button>
                    </td>
                </tr>
            `);
            $tbody.append($row);
        });
    }

    $('#addShopBtn').on('click', function() {
        $('#shopModalTitle').text('Thêm Shop triển khai');
        $('#shopId').val('');
        $('#shopCode').val('');
        $('#shopNote').val('');
        $('#shopIsActive').prop('checked', true);

        const modal = new bootstrap.Modal(document.getElementById('shopModal'));
        modal.show();
    });

    $(document).on('click', '.edit-shop', function() {
        const shopId = $(this).data('id');
        const $row = $(this).closest('tr');

        $('#shopModalTitle').text('Sửa Shop triển khai');
        $('#shopId').val(shopId);
        $('#shopCode').val($row.find('code').text());
        $('#shopNote').val($row.find('td:eq(2)').text().trim() === '-' ? '' : $row.find('td:eq(2)').text().trim());
        $('#shopIsActive').prop('checked', $row.find('.badge').hasClass('bg-success'));

        const modal = new bootstrap.Modal(document.getElementById('shopModal'));
        modal.show();
    });

    $('#saveShopBtn').on('click', function() {
        const code = $('#shopCode').val().trim();
        if (!code) {
            showAlert('danger', 'Vui lòng nhập mã shop');
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: tgsInternalTransfer.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_it_save_deployment_shop',
                nonce: tgsInternalTransfer.nonce,
                id: $('#shopId').val(),
                tgs_site_code: code,
                note: $('#shopNote').val(),
                is_active: $('#shopIsActive').is(':checked') ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.data.message);
                    bootstrap.Modal.getInstance(document.getElementById('shopModal')).hide();
                    loadDeploymentShops();
                } else {
                    showAlert('danger', response.data.message || 'Lỗi lưu dữ liệu');
                }
                $btn.prop('disabled', false);
            },
            error: function() {
                showAlert('danger', 'Lỗi kết nối server');
                $btn.prop('disabled', false);
            }
        });
    });

    $(document).on('click', '.delete-shop', function() {
        if (!confirm('Bạn có chắc muốn xóa shop này?')) return;

        const shopId = $(this).data('id');
        $.ajax({
            url: tgsInternalTransfer.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_it_delete_deployment_shop',
                nonce: tgsInternalTransfer.nonce,
                id: shopId
            },
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.data.message);
                    loadDeploymentShops();
                } else {
                    showAlert('danger', response.data.message || 'Lỗi xóa dữ liệu');
                }
            }
        });
    });

    // Load shops khi vào tab
    $('#shops-tab').on('shown.bs.tab', function() {
        loadDeploymentShops();
    });

    // ========== HELPER FUNCTIONS ==========
    function showAlert(type, message) {
        const $alert = $('#itAlert');
        $alert.removeClass('d-none alert-success alert-danger alert-info alert-warning')
            .addClass('alert-' + type);
        $('#itAlertText').text(message);

        setTimeout(function() {
            $alert.addClass('d-none');
        }, 5000);
    }
});
