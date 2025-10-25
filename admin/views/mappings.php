<?php
if (!defined('ABSPATH')) exit;

$mapper = new \CouponImporter\Mapper();
$taxonomy_helper = new \CouponImporter\TaxonomyHelper();

$provider_slug = isset($_GET['provider']) ? sanitize_key($_GET['provider']) : 'rakuten';
$available_providers = array(
    'rakuten' => __('Rakuten Advertising', '7k-coupons-importer'),
    'awin' => __('Awin', '7k-coupons-importer'),
    'lomadee' => __('Lomadee', '7k-coupons-importer'),
    'admitad' => __('Admitad', '7k-coupons-importer')
);

if (!isset($available_providers[$provider_slug])) {
    $provider_slug = 'rakuten';
}

if (isset($_POST['ci7k_save_store_mapping'])) {
    check_admin_referer('ci7k_mappings_nonce');

    // Remover barras invertidas de escape
    $original = stripslashes(sanitize_text_field($_POST['original_store']));
    $mapped = stripslashes(sanitize_text_field($_POST['mapped_store']));

    if (!empty($original) && !empty($mapped)) {
        $mapper->save_store_mapping($provider_slug, $original, $mapped);
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Mapeamento de loja salvo com sucesso!', '7k-coupons-importer') . '</p></div>';
    }
}

if (isset($_POST['ci7k_save_category_mapping'])) {
    check_admin_referer('ci7k_mappings_nonce');

    // Remover barras invertidas de escape
    $original = stripslashes(sanitize_text_field($_POST['original_category']));
    $mapped = stripslashes(sanitize_text_field($_POST['mapped_category']));

    if (!empty($original) && !empty($mapped)) {
        $mapper->save_category_mapping($provider_slug, $original, $mapped);
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Mapeamento de categoria salvo com sucesso!', '7k-coupons-importer') . '</p></div>';
    }
}

if (isset($_POST['ci7k_delete_store_mapping'])) {
    check_admin_referer('ci7k_mappings_nonce');

    // Remover barras invertidas de escape
    $original = stripslashes(sanitize_text_field($_POST['delete_store']));

    if (!empty($original)) {
        $mapper->delete_store_mapping($provider_slug, $original);
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Mapeamento de loja removido com sucesso!', '7k-coupons-importer') . '</p></div>';
    }
}

if (isset($_POST['ci7k_delete_category_mapping'])) {
    check_admin_referer('ci7k_mappings_nonce');

    // Remover barras invertidas de escape
    $original = stripslashes(sanitize_text_field($_POST['delete_category']));

    if (!empty($original)) {
        $mapper->delete_category_mapping($provider_slug, $original);
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Mapeamento de categoria removido com sucesso!', '7k-coupons-importer') . '</p></div>';
    }
}

$store_mappings = $mapper->get_all_store_mappings($provider_slug);
$category_mappings = $mapper->get_all_category_mappings($provider_slug);
$imported_stores = $mapper->get_imported_stores($provider_slug);
$imported_categories = $mapper->get_imported_categories($provider_slug);
$wp_stores = $taxonomy_helper->get_stores();
$wp_categories = $taxonomy_helper->get_categories();
?>

<div class="wrap couponis7k-wrap">
    <h1><?php _e('Mapeamentos de Lojas e Categorias', '7k-coupons-importer'); ?></h1>
    <p><?php _e('Configure como os nomes de lojas e categorias do provedor devem ser mapeados para os nomes usados no seu WordPress.', '7k-coupons-importer'); ?></p>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($available_providers as $slug => $label): ?>
            <?php $active_class = ($slug === $provider_slug) ? ' nav-tab-active' : ''; ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=ci7k-mappings&provider=' . $slug)); ?>" class="nav-tab<?php echo esc_attr($active_class); ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <div class="ci7k-mappings-container">
        <div class="ci7k-mappings-section">
            <h2><?php _e('Mapeamento de Lojas', '7k-coupons-importer'); ?></h2>
            <p class="description"><?php _e('Mapeie nomes de lojas importadas para os nomes que você usa no WordPress.', '7k-coupons-importer'); ?></p>

            <form method="post" class="ci7k-mapping-form">
                <?php wp_nonce_field('ci7k_mappings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><?php _e('Nome Original (do provedor)', '7k-coupons-importer'); ?></th>
                        <td>
                            <select name="original_store" required class="regular-text">
                                <option value=""><?php _e('Selecione uma loja...', '7k-coupons-importer'); ?></option>
                                <?php foreach ($imported_stores as $store): ?>
                                    <?php if (!isset($store_mappings[$store])): ?>
                                        <option value="<?php echo esc_attr($store); ?>"><?php echo esc_html(stripslashes($store)); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Selecione o nome da loja como vem do provedor', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Mapear Para', '7k-coupons-importer'); ?></th>
                        <td>
                            <select name="mapped_store" required class="regular-text">
                                <option value=""><?php _e('Selecione uma loja...', '7k-coupons-importer'); ?></option>
                                <?php foreach ($wp_stores as $store): ?>
                                    <option value="<?php echo esc_attr($store->name); ?>"><?php echo esc_html(stripslashes($store->name)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Selecione a loja do WordPress para mapear', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ci7k_save_store_mapping" class="button-primary"><?php _e('Adicionar Mapeamento', '7k-coupons-importer'); ?></button>
                </p>
            </form>

            <h3><?php _e('Mapeamentos Existentes', '7k-coupons-importer'); ?></h3>
            <?php if (empty($store_mappings)): ?>
                <p class="description"><?php _e('Nenhum mapeamento de loja configurado ainda.', '7k-coupons-importer'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Nome Original', '7k-coupons-importer'); ?></th>
                            <th><?php _e('Mapeado Para', '7k-coupons-importer'); ?></th>
                            <th><?php _e('Ações', '7k-coupons-importer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($store_mappings as $original => $mapped): ?>
                            <tr>
                                <td><strong><?php echo esc_html(stripslashes($original)); ?></strong></td>
                                <td><?php echo esc_html(stripslashes($mapped)); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('ci7k_mappings_nonce'); ?>
                                        <input type="hidden" name="delete_store" value="<?php echo esc_attr($original); ?>">
                                        <button type="submit" name="ci7k_delete_store_mapping" class="button button-small"
                                                onclick="return confirm('<?php _e('Tem certeza que deseja remover este mapeamento?', '7k-coupons-importer'); ?>')">
                                            <?php _e('Remover', '7k-coupons-importer'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="ci7k-mappings-section">
            <h2><?php _e('Mapeamento de Categorias', '7k-coupons-importer'); ?></h2>
            <p class="description"><?php _e('Mapeie nomes de categorias importadas para os nomes que você usa no WordPress.', '7k-coupons-importer'); ?></p>

            <form method="post" class="ci7k-mapping-form">
                <?php wp_nonce_field('ci7k_mappings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><?php _e('Nome Original (do provedor)', '7k-coupons-importer'); ?></th>
                        <td>
                            <select name="original_category" required class="regular-text">
                                <option value=""><?php _e('Selecione uma categoria...', '7k-coupons-importer'); ?></option>
                                <?php foreach ($imported_categories as $category): ?>
                                    <?php if (!isset($category_mappings[$category])): ?>
                                        <option value="<?php echo esc_attr($category); ?>"><?php echo esc_html(stripslashes($category)); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Selecione o nome da categoria como vem do provedor', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Mapear Para', '7k-coupons-importer'); ?></th>
                        <td>
                            <select name="mapped_category" required class="regular-text">
                                <option value=""><?php _e('Selecione uma categoria...', '7k-coupons-importer'); ?></option>
                                <?php foreach ($wp_categories as $category): ?>
                                    <?php
                                    $indent = str_repeat('&nbsp;&nbsp;', count(get_ancestors($category->term_id, 'coupon-category')));
                                    ?>
                                    <option value="<?php echo esc_attr($category->name); ?>"><?php echo $indent . esc_html(stripslashes($category->name)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Selecione a categoria do WordPress para mapear', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ci7k_save_category_mapping" class="button-primary"><?php _e('Adicionar Mapeamento', '7k-coupons-importer'); ?></button>
                </p>
            </form>

            <h3><?php _e('Mapeamentos Existentes', '7k-coupons-importer'); ?></h3>
            <?php if (empty($category_mappings)): ?>
                <p class="description"><?php _e('Nenhum mapeamento de categoria configurado ainda.', '7k-coupons-importer'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Nome Original', '7k-coupons-importer'); ?></th>
                            <th><?php _e('Mapeado Para', '7k-coupons-importer'); ?></th>
                            <th><?php _e('Ações', '7k-coupons-importer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($category_mappings as $original => $mapped): ?>
                            <tr>
                                <td><strong><?php echo esc_html(stripslashes($original)); ?></strong></td>
                                <td><?php echo esc_html(stripslashes($mapped)); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('ci7k_mappings_nonce'); ?>
                                        <input type="hidden" name="delete_category" value="<?php echo esc_attr($original); ?>">
                                        <button type="submit" name="ci7k_delete_category_mapping" class="button button-small"
                                                onclick="return confirm('<?php _e('Tem certeza que deseja remover este mapeamento?', '7k-coupons-importer'); ?>')">
                                            <?php _e('Remover', '7k-coupons-importer'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.ci7k-mappings-container {
    margin-top: 20px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.ci7k-mappings-section {
    background: #fff;
    border: 1px solid #ddd;
    padding: 20px;
    border-radius: 4px;
}

.ci7k-mappings-section h2 {
    margin-top: 0;
    border-bottom: 1px solid #ddd;
    padding-bottom: 10px;
}

.ci7k-mapping-form {
    background: #f9f9f9;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 20px;
}

.ci7k-mappings-section h3 {
    margin-top: 30px;
    margin-bottom: 15px;
}

.wp-list-table th, .wp-list-table td {
    vertical-align: middle;
}
</style>