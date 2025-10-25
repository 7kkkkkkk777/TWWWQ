<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap couponis7k-wrap">
    <h1 class="couponis7k-title"><?php _e('Curadoria de Cupons', '7k-coupons-importer'); ?></h1>

    <div class="couponis7k-stats-row">
        <div class="couponis7k-stat-box">
            <div class="couponis7k-stat-number"><?php echo esc_html($stats['total']); ?></div>
            <div class="couponis7k-stat-label"><?php _e('Total', '7k-coupons-importer'); ?></div>
        </div>
        <div class="couponis7k-stat-box">
            <div class="couponis7k-stat-number"><?php echo esc_html($stats['pending']); ?></div>
            <div class="couponis7k-stat-label"><?php _e('Pendentes', '7k-coupons-importer'); ?></div>
        </div>
        <div class="couponis7k-stat-box couponis7k-stat-success">
            <div class="couponis7k-stat-number"><?php echo esc_html($stats['approved']); ?></div>
            <div class="couponis7k-stat-label"><?php _e('Aprovados', '7k-coupons-importer'); ?></div>
        </div>
        <div class="couponis7k-stat-box couponis7k-stat-primary">
            <div class="couponis7k-stat-number"><?php echo esc_html($stats['published']); ?></div>
            <div class="couponis7k-stat-label">
                <?php _e('Publicados', '7k-coupons-importer'); ?>
                <small style="display: block; font-size: 11px; opacity: 0.8; margin-top: 2px;">
                    (<?php echo esc_html($stats['published_last_7_days']); ?> nos últimos 7 dias)
                </small>
            </div>
        </div>
        <div class="couponis7k-stat-box couponis7k-stat-danger">
            <div class="couponis7k-stat-number"><?php echo esc_html($stats['rejected']); ?></div>
            <div class="couponis7k-stat-label"><?php _e('Rejeitados', '7k-coupons-importer'); ?></div>
        </div>
    </div>

    <div class="couponis7k-filters">
        <div class="couponis7k-filters-left">
            <div class="couponis7k-select-all-wrapper">
                <label>
                    <input type="checkbox" id="select-all-coupons">
                    <strong><?php _e('Selecionar todos desta página', '7k-coupons-importer'); ?></strong>
                </label>
            </div>
        </div>
        <form method="get" action="">
            <input type="hidden" name="page" value="ci7k-curation">

            <select name="status" id="status-filter">
                <option value=""><?php _e('Todos Status', '7k-coupons-importer'); ?></option>
                <option value="pending" <?php selected($filter_status, 'pending'); ?>><?php _e('Pendentes', '7k-coupons-importer'); ?></option>
                <option value="approved" <?php selected($filter_status, 'approved'); ?>><?php _e('Aprovados', '7k-coupons-importer'); ?></option>
                <option value="published" <?php selected($filter_status, 'published'); ?>><?php _e('Publicados', '7k-coupons-importer'); ?></option>
                <option value="rejected" <?php selected($filter_status, 'rejected'); ?>><?php _e('Rejeitados', '7k-coupons-importer'); ?></option>
            </select>

            <select name="provider" id="provider-filter">
                <option value=""><?php _e('Todos Provedores', '7k-coupons-importer'); ?></option>
                <option value="rakuten" <?php selected($filter_provider, 'rakuten'); ?>>Rakuten</option>
                <option value="awin" <?php selected($filter_provider, 'awin'); ?>>Awin</option>
                <option value="lomadee" <?php selected($filter_provider, 'lomadee'); ?>>Lomadee</option>
                <option value="admitad" <?php selected($filter_provider, 'admitad'); ?>>Admitad</option>
            </select>

            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Buscar...', '7k-coupons-importer'); ?>">

            <button type="submit" class="button"><?php _e('Filtrar', '7k-coupons-importer'); ?></button>
        </form>

        <div class="couponis7k-bulk-actions">
            <select id="bulk-action-selector">
                <option value=""><?php _e('Ações em massa', '7k-coupons-importer'); ?></option>
                <option value="approve"><?php _e('Aprovar', '7k-coupons-importer'); ?></option>
                <option value="reject"><?php _e('Rejeitar', '7k-coupons-importer'); ?></option>
                <option value="publish"><?php _e('Publicar', '7k-coupons-importer'); ?></option>
                <option value="rewrite_titles"><?php _e('Reescrever títulos com IA', '7k-coupons-importer'); ?></option>
                <option value="rewrite_descriptions"><?php _e('Reescrever descrições com IA', '7k-coupons-importer'); ?></option>
                <option value="delete"><?php _e('Remover', '7k-coupons-importer'); ?></option>
            </select>
            <button type="button" id="apply-bulk-action" class="button"><?php _e('Aplicar', '7k-coupons-importer'); ?></button>
            <span id="selected-count" style="margin-left: 10px; color: #666;"></span>
        </div>
    </div>

    <?php if ($query->have_posts()): ?>
        <div class="couponis7k-coupons-list">
            <?php while ($query->have_posts()): $query->the_post();
                $coupon_id = get_the_ID();
                $status = get_post_meta($coupon_id, '_ci7k_status', true);
                $provider = get_post_meta($coupon_id, '_ci7k_provider', true);
                $code = get_post_meta($coupon_id, '_ci7k_code', true);
                $advertiser = get_post_meta($coupon_id, '_ci7k_advertiser', true);
                $coupon_type = get_post_meta($coupon_id, '_ci7k_coupon_type', true);
                $is_exclusive = get_post_meta($coupon_id, '_ci7k_is_exclusive', true);
                $expiration = get_post_meta($coupon_id, '_ci7k_expiration', true);
            ?>
                <div class="couponis7k-coupon-card" data-coupon-id="<?php echo esc_attr($coupon_id); ?>">
                    <div class="couponis7k-coupon-checkbox">
                        <input type="checkbox" class="coupon-select" value="<?php echo esc_attr($coupon_id); ?>">
                    </div>

                    <div class="couponis7k-coupon-content">
                        <div class="couponis7k-coupon-header">
                            <h3 class="couponis7k-coupon-title"><?php echo esc_html(get_the_title()); ?></h3>
                            <div class="couponis7k-coupon-badges">
                                <span class="couponis7k-badge couponis7k-badge-<?php echo esc_attr($status); ?>">
                                    <?php echo esc_html(ci7k_get_coupon_status_label($status)); ?>
                                </span>
                                <span class="couponis7k-badge couponis7k-badge-provider">
                                    <?php echo esc_html(strtoupper($provider)); ?>
                                </span>
                                <span class="couponis7k-badge">
                                    <?php echo esc_html(ci7k_get_coupon_type_label($coupon_type)); ?>
                                </span>
                                <?php if ($is_exclusive): ?>
                                    <span class="couponis7k-badge couponis7k-badge-exclusive"><?php _e('Exclusivo', '7k-coupons-importer'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="couponis7k-coupon-description">
                            <?php echo wp_kses_post(get_the_content()); ?>
                        </div>

                        <div class="couponis7k-coupon-meta">
                            <?php if ($advertiser): ?>
                                <span><strong><?php _e('Loja:', '7k-coupons-importer'); ?></strong> <?php echo esc_html(stripslashes($advertiser)); ?></span>
                            <?php endif; ?>
                            <?php if ($code): ?>
                                <span><strong><?php _e('Código:', '7k-coupons-importer'); ?></strong> <code><?php echo esc_html(stripslashes($code)); ?></code></span>
                            <?php endif; ?>
                            <?php if ($expiration): ?>
                                <span><strong><?php _e('Validade:', '7k-coupons-importer'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($expiration))); ?></span>
                            <?php endif; ?>
                            <?php 
                            $categories = get_post_meta($coupon_id, '_ci7k_categories', true);
                            if (!empty($categories) && is_array($categories)): ?>
                                <span><strong><?php _e('Categoria:', '7k-coupons-importer'); ?></strong> <?php echo esc_html(implode(', ', $categories)); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="couponis7k-coupon-actions">
                        <?php if ($status === 'pending' || $status === 'rejected'): ?>
                            <button type="button" class="couponis7k-btn-icon couponis7k-btn-success ci7k-approve-coupon" data-id="<?php echo esc_attr($coupon_id); ?>" title="<?php _e('Aprovar', '7k-coupons-importer'); ?>">
                                <span class="dashicons dashicons-yes-alt"></span>
                            </button>
                        <?php endif; ?>

                        <?php if ($status === 'pending' || $status === 'approved'): ?>
                            <button type="button" class="couponis7k-btn-icon couponis7k-btn-danger ci7k-reject-coupon" data-id="<?php echo esc_attr($coupon_id); ?>" title="<?php _e('Rejeitar', '7k-coupons-importer'); ?>">
                                <span class="dashicons dashicons-dismiss"></span>
                            </button>
                        <?php endif; ?>

                        <?php if ($status === 'approved'): ?>
                            <button type="button" class="couponis7k-btn-icon couponis7k-btn-primary ci7k-publish-coupon" data-id="<?php echo esc_attr($coupon_id); ?>" title="<?php _e('Publicar', '7k-coupons-importer'); ?>">
                                <span class="dashicons dashicons-upload"></span>
                            </button>
                        <?php endif; ?>

                        <?php if ($status !== 'published'): ?>
                            <button type="button" class="couponis7k-btn-icon ci7k-rewrite-title" data-id="<?php echo esc_attr($coupon_id); ?>" title="<?php _e('Reescrever Título (IA)', '7k-coupons-importer'); ?>">
                                <span class="dashicons dashicons-edit-page"></span>
                            </button>
                            <button type="button" class="couponis7k-btn-icon ci7k-rewrite-description" data-id="<?php echo esc_attr($coupon_id); ?>" title="<?php _e('Reescrever Descrição (IA)', '7k-coupons-importer'); ?>">
                                <span class="dashicons dashicons-edit-large"></span>
                            </button>
                        <?php endif; ?>

                        <button type="button" class="couponis7k-btn-icon couponis7k-btn-danger ci7k-delete-coupon" data-id="<?php echo esc_attr($coupon_id); ?>" title="<?php _e('Remover', '7k-coupons-importer'); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <?php
        $total_pages = $query->max_num_pages;
        if ($total_pages > 1):
        ?>
            <div class="couponis7k-pagination">
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $paged,
                    'total' => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;'
                ));
                ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="couponis7k-empty-state">
            <p><?php _e('Nenhum cupom encontrado.', '7k-coupons-importer'); ?></p>
        </div>
    <?php endif; ?>

    <?php wp_reset_postdata(); ?>
</div>