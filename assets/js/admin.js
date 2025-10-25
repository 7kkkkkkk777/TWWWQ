jQuery(document).ready(function($) {
    'use strict';

    function showNotice(message, type) {
        var noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
        var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible ci7k-notice"><p>' + message + '</p></div>');
        $('.couponis7k-wrap').prepend($notice);

        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    $('.ci7k-approve-coupon').on('click', function() {
        // Aviso para lembrar de criar mapeamentos
        if (!confirm('⚠️ LEMBRETE: Você já criou os mapeamentos de loja e categoria para este cupom?\n\nSe não criou, vá em "Mapeamentos" antes de aprovar.\n\nDeseja continuar e aprovar mesmo assim?')) {
            return;
        }

        var $btn = $(this);
        var couponId = $btn.data('id');

        $btn.prop('disabled', true).text(ci7k_ajax.strings.processing);

        $.ajax({
            url: ci7k_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ci7k_approve_coupon',
                nonce: ci7k_ajax.nonce,
                coupon_id: couponId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    location.reload();
                } else {
                    showNotice(response.data.message, 'error');
                    $btn.prop('disabled', false).text('Aprovar');
                }
            },
            error: function() {
                showNotice(ci7k_ajax.strings.error, 'error');
                $btn.prop('disabled', false).text('Aprovar');
            }
        });
    });

    $('.ci7k-reject-coupon').on('click', function() {
        var $btn = $(this);
        var couponId = $btn.data('id');

        $btn.prop('disabled', true).text(ci7k_ajax.strings.processing);

        $.ajax({
            url: ci7k_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ci7k_reject_coupon',
                nonce: ci7k_ajax.nonce,
                coupon_id: couponId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    location.reload();
                } else {
                    showNotice(response.data.message, 'error');
                    $btn.prop('disabled', false).text('Rejeitar');
                }
            },
            error: function() {
                showNotice(ci7k_ajax.strings.error, 'error');
                $btn.prop('disabled', false).text('Rejeitar');
            }
        });
    });

    $('.ci7k-publish-coupon').on('click', function() {
        var $btn = $(this);
        var couponId = $btn.data('id');

        $btn.prop('disabled', true).text(ci7k_ajax.strings.processing);

        $.ajax({
            url: ci7k_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ci7k_publish_coupon',
                nonce: ci7k_ajax.nonce,
                coupon_id: couponId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    location.reload();
                } else {
                    showNotice(response.data.message, 'error');
                    $btn.prop('disabled', false).text('Publicar');
                }
            },
            error: function() {
                showNotice(ci7k_ajax.strings.error, 'error');
                $btn.prop('disabled', false).text('Publicar');
            }
        });
    });

    $('.ci7k-rewrite-title').on('click', function() {
        if (!confirm('Deseja reescrever o título deste cupom usando IA?')) {
            return;
        }

        var $btn = $(this);
        var couponId = $btn.data('id');
        var originalText = $btn.text();

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

        $.ajax({
            url: ci7k_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ci7k_rewrite_title',
                nonce: ci7k_ajax.nonce,
                coupon_id: couponId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    var $card = $btn.closest('.couponis7k-coupon-card');
                    $card.find('.couponis7k-coupon-title').text(response.data.new_title);
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-page"></span>');
                } else {
                    showNotice(response.data.message, 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-page"></span>');
                }
            },
            error: function() {
                showNotice(ci7k_ajax.strings.error, 'error');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-page"></span>');
            }
        });
    });

    $('.ci7k-rewrite-description').on('click', function() {
        if (!confirm('Deseja reescrever a descrição deste cupom usando IA?')) {
            return;
        }

        var $btn = $(this);
        var couponId = $btn.data('id');
        var originalText = $btn.text();

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

        $.ajax({
            url: ci7k_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ci7k_rewrite_description',
                nonce: ci7k_ajax.nonce,
                coupon_id: couponId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    var $card = $btn.closest('.couponis7k-coupon-card');
                    $card.find('.couponis7k-coupon-description').text(response.data.new_description);
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-large"></span>');
                } else {
                    showNotice(response.data.message, 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-large"></span>');
                }
            },
            error: function() {
                showNotice(ci7k_ajax.strings.error, 'error');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-large"></span>');
            }
        });
    });

    $('.ci7k-delete-coupon').on('click', function() {
        if (!confirm(ci7k_ajax.strings.confirm_delete)) {
            return;
        }

        var $btn = $(this);
        var couponId = $btn.data('id');

        $btn.prop('disabled', true).text(ci7k_ajax.strings.processing);

        $.ajax({
            url: ci7k_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ci7k_delete_coupon',
                nonce: ci7k_ajax.nonce,
                coupon_id: couponId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    $btn.closest('.couponis7k-coupon-card').fadeOut(function() {
                        $(this).remove();
                    });
                } else {
                    showNotice(response.data.message, 'error');
                    $btn.prop('disabled', false).text('Remover');
                }
            },
            error: function() {
                showNotice(ci7k_ajax.strings.error, 'error');
                $btn.prop('disabled', false).text('Remover');
            }
        });
    });

    $('#apply-bulk-action').on('click', function() {
        var action = $('#bulk-action-selector').val();

        if (!action) {
            showNotice('Selecione uma ação', 'error');
            return;
        }

        var selectedCoupons = [];
        $('.coupon-select:checked').each(function() {
            selectedCoupons.push($(this).val());
        });

        if (selectedCoupons.length === 0) {
            showNotice('Selecione pelo menos um cupom', 'error');
            return;
        }

        // Aviso especial para ação de aprovar em massa
        if (action === 'approve') {
            if (!confirm('⚠️ LEMBRETE: Você já criou os mapeamentos de loja e categoria para estes cupons?\n\nSe não criou, vá em "Mapeamentos" antes de aprovar.\n\nDeseja continuar e aprovar ' + selectedCoupons.length + ' cupons mesmo assim?')) {
                return;
            }
        } else if (!confirm(ci7k_ajax.strings.confirm_bulk)) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(ci7k_ajax.strings.processing);

        $.ajax({
            url: ci7k_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ci7k_bulk_action',
                nonce: ci7k_ajax.nonce,
                action_type: action,
                coupon_ids: selectedCoupons
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showNotice(response.data.message, 'error');
                    $btn.prop('disabled', false).text('Aplicar');
                }
            },
            error: function() {
                showNotice(ci7k_ajax.strings.error, 'error');
                $btn.prop('disabled', false).text('Aplicar');
            }
        });
    });

    $('#select-all-coupons').on('change', function() {
        $('.coupon-select').prop('checked', $(this).prop('checked'));
        updateSelectedCount();
    });

    $('.coupon-select').on('change', function() {
        updateSelectedCount();
        updateSelectAllCheckbox();
    });

    function updateSelectedCount() {
        var count = $('.coupon-select:checked').length;
        if (count > 0) {
            $('#selected-count').text(count + ' ' + (count === 1 ? 'cupom selecionado' : 'cupons selecionados'));
        } else {
            $('#selected-count').text('');
        }
    }

    function updateSelectAllCheckbox() {
        var total = $('.coupon-select').length;
        var checked = $('.coupon-select:checked').length;
        $('#select-all-coupons').prop('checked', total === checked && total > 0);
    }

    updateSelectedCount();
});