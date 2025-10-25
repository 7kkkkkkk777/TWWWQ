<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap couponis7k-wrap">
    <h1><?php _e('Configurações', '7k-coupons-importer'); ?></h1>

    <?php
    $settings = get_option('ci7k_settings', array());
    $ai_provider = $settings['ai_provider'] ?? 'openai';
    ?>

    <div class="couponis7k-tabs">
        <nav class="nav-tab-wrapper">
            <a href="#general" class="nav-tab nav-tab-active"><?php _e('Geral', '7k-coupons-importer'); ?></a>
            <a href="#ai-prompts" class="nav-tab"><?php _e('Prompts de IA', '7k-coupons-importer'); ?></a>
            <a href="#automation" class="nav-tab"><?php _e('Automação', '7k-coupons-importer'); ?></a>
            <a href="#fixes" class="nav-tab"><?php _e('Correções', '7k-coupons-importer'); ?></a>
        </nav>

        <form method="post" action="">
            <?php wp_nonce_field('ci7k_settings_nonce'); ?>

            <!-- Aba Geral -->
            <div id="general" class="tab-content active">
                <h2><?php _e('Configurações Gerais', '7k-coupons-importer'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th><?php _e('Provedor de IA', '7k-coupons-importer'); ?></th>
                        <td>
                            <select name="ai_provider" id="ai_provider">
                                <option value="none" <?php selected($ai_provider, 'none'); ?>><?php _e('Nenhum', '7k-coupons-importer'); ?></option>
                                <option value="openai" <?php selected($ai_provider, 'openai'); ?>>OpenAI</option>
                                <option value="gemini" <?php selected($ai_provider, 'gemini'); ?>>Google Gemini</option>
                            </select>
                        </td>
                    </tr>

                    <tr id="openai_settings" style="<?php echo $ai_provider === 'openai' ? '' : 'display:none;'; ?>">
                        <th><?php _e('OpenAI API Keys', '7k-coupons-importer'); ?></th>
                        <td>
                            <div id="openai_keys_container">
                                <?php 
                                $openai_keys = isset($settings['openai_api_keys']) ? $settings['openai_api_keys'] : array();
                                if (empty($openai_keys)) {
                                    $openai_keys = array('');
                                }
                                foreach ($openai_keys as $key): 
                                ?>
                                <div class="api-key-row" style="margin-bottom: 10px;">
                                    <input type="password" name="openai_api_keys[]" value="<?php echo esc_attr($key); ?>" class="regular-text" placeholder="sk-...">
                                    <button type="button" class="button remove-api-key"><?php _e('Remover', '7k-coupons-importer'); ?></button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="add_openai_key" class="button"><?php _e('+ Adicionar API Key', '7k-coupons-importer'); ?></button>
                            <p class="description"><?php _e('Adicione múltiplas API keys para revezamento automático. Obtenha em https://platform.openai.com/api-keys', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>

                    <tr id="openai_model_row" style="<?php echo $ai_provider === 'openai' ? '' : 'display:none;'; ?>">
                        <th><?php _e('OpenAI Model', '7k-coupons-importer'); ?></th>
                        <td>
                            <input type="text" name="openai_model" value="<?php echo esc_attr($settings['openai_model'] ?? 'gpt-3.5-turbo'); ?>" class="regular-text">
                        </td>
                    </tr>

                    <tr id="gemini_settings" style="<?php echo $ai_provider === 'gemini' ? '' : 'display:none;'; ?>">
                        <th><?php _e('Gemini API Keys', '7k-coupons-importer'); ?></th>
                        <td>
                            <div id="gemini_keys_container">
                                <?php 
                                $gemini_keys = isset($settings['gemini_api_keys']) ? $settings['gemini_api_keys'] : array();
                                if (empty($gemini_keys)) {
                                    $gemini_keys = array('');
                                }
                                foreach ($gemini_keys as $key): 
                                ?>
                                <div class="api-key-row" style="margin-bottom: 10px;">
                                    <input type="password" name="gemini_api_keys[]" value="<?php echo esc_attr($key); ?>" class="regular-text" placeholder="AIza...">
                                    <button type="button" class="button remove-api-key"><?php _e('Remover', '7k-coupons-importer'); ?></button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="add_gemini_key" class="button"><?php _e('+ Adicionar API Key', '7k-coupons-importer'); ?></button>
                            <p class="description"><?php _e('Adicione múltiplas API keys para revezamento automático. Obtenha em https://makersuite.google.com/app/apikey', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>

                    <tr id="gemini_model_row" style="<?php echo $ai_provider === 'gemini' ? '' : 'display:none;'; ?>">
                        <th><?php _e('Gemini Model', '7k-coupons-importer'); ?></th>
                        <td>
                            <input type="text" name="gemini_model" value="<?php echo esc_attr($settings['gemini_model'] ?? 'gemini-pro'); ?>" class="regular-text">
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('Intervalo de Rotação de APIs', '7k-coupons-importer'); ?></th>
                        <td>
                            <input type="number" name="api_rotation_interval" value="<?php echo esc_attr($settings['api_rotation_interval'] ?? 10); ?>" min="1" max="100" class="small-text">
                            <p class="description"><?php _e('Número de gerações antes de trocar para a próxima API key (padrão: 10)', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('Sistema', '7k-coupons-importer'); ?></th>
                        <td>
                            <label><input type="checkbox" name="logs_enabled" value="1" <?php checked($settings['logs_enabled'] ?? 1, 1); ?>> <?php _e('Habilitar logs do sistema', '7k-coupons-importer'); ?></label><br>
                            <p class="description"><?php _e('Quando desativado, o menu Logs será ocultado e nenhum log será gravado.', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('Opções', '7k-coupons-importer'); ?></th>
                        <td>
                            <label><input type="checkbox" name="ai_rewrite_enabled" value="1" <?php checked($settings['ai_rewrite_enabled'] ?? 0, 1); ?>> <?php _e('Habilitar reescrita com IA', '7k-coupons-importer'); ?></label><br>
                            <label><input type="checkbox" name="require_approval" value="1" <?php checked($settings['require_approval'] ?? 1, 1); ?>> <?php _e('Requer aprovação manual', '7k-coupons-importer'); ?></label><br>
                            <label><input type="checkbox" name="delete_on_publish" value="1" <?php checked($settings['delete_on_publish'] ?? 0, 1); ?>> <?php _e('Remover cupom importado após publicação', '7k-coupons-importer'); ?></label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Aba Prompts de IA -->
            <div id="ai-prompts" class="tab-content">
                <h2><?php _e('Prompts para Reescrita com IA', '7k-coupons-importer'); ?></h2>
                <p class="description"><?php _e('Configure os prompts que serão enviados para a IA. Use %s onde a lista de cupons deve ser inserida. IMPORTANTE: O marcador %s é obrigatório para reescrita em massa.', '7k-coupons-importer'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th><?php _e('Prompt OpenAI para Títulos', '7k-coupons-importer'); ?></th>
                        <td>
                            <textarea name="openai_title_prompt" rows="4" class="large-text"><?php echo esc_textarea($settings['openai_title_prompt'] ?? 'Reescreva de forma natural e profissional a seguinte lista de títulos de cupons (formato: Loja, Título). Retorne apenas a lista reescrita, uma por linha, sem numeração ou comentários:\n\n%s'); ?></textarea>
                            <p class="description"><?php _e('Prompt usado para reescrever títulos em massa. Use %s para inserir a lista de cupons.', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('Prompt OpenAI para Descrições', '7k-coupons-importer'); ?></th>
                        <td>
                            <textarea name="openai_description_prompt" rows="4" class="large-text"><?php echo esc_textarea($settings['openai_description_prompt'] ?? 'Reescreva de forma natural e profissional a seguinte lista de descrições de cupons (formato: Loja, Descrição). Retorne apenas a lista reescrita, uma por linha, sem numeração ou comentários:\n\n%s'); ?></textarea>
                            <p class="description"><?php _e('Prompt usado para reescrever descrições em massa. Use %s para inserir a lista de cupons.', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('Prompt Gemini para Títulos', '7k-coupons-importer'); ?></th>
                        <td>
                            <textarea name="gemini_title_prompt" rows="4" class="large-text"><?php echo esc_textarea($settings['gemini_title_prompt'] ?? 'Reescreva de forma natural e profissional a seguinte lista de títulos de cupons (formato: Loja, Título). Retorne apenas a lista reescrita, uma por linha, sem numeração ou comentários:\n\n%s'); ?></textarea>
                            <p class="description"><?php _e('Prompt usado para reescrever títulos em massa. Use %s para inserir a lista de cupons.', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('Prompt Gemini para Descrições', '7k-coupons-importer'); ?></th>
                        <td>
                            <textarea name="gemini_description_prompt" rows="4" class="large-text"><?php echo esc_textarea($settings['gemini_description_prompt'] ?? 'Reescreva de forma natural e profissional a seguinte lista de descrições de cupons (formato: Loja, Descrição). Retorne apenas a lista reescrita, uma por linha, sem numeração ou comentários:\n\n%s'); ?></textarea>
                            <p class="description"><?php _e('Prompt usado para reescrever descrições em massa. Use %s para inserir a lista de cupons.', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Aba Automação -->
            <div id="automation" class="tab-content">
                <h2><?php _e('Publicação Automática', '7k-coupons-importer'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th><?php _e('Publicação Automática', '7k-coupons-importer'); ?></th>
                        <td>
                            <label><input type="checkbox" name="auto_publish" value="1" <?php checked($settings['auto_publish'] ?? 0, 1); ?>> <?php _e('Publicar automaticamente cupons aprovados', '7k-coupons-importer'); ?></label>
                            <p class="description"><?php _e('Quando ativado, cupons aprovados serão publicados automaticamente no intervalo configurado.', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('Intervalo do Cron', '7k-coupons-importer'); ?></th>
                        <td>
                            <select name="auto_publish_cron_interval">
                                <option value="every_15_minutes" <?php selected($settings['auto_publish_cron_interval'] ?? 'hourly', 'every_15_minutes'); ?>><?php _e('A cada 15 minutos', '7k-coupons-importer'); ?></option>
                                <option value="every_30_minutes" <?php selected($settings['auto_publish_cron_interval'] ?? 'hourly', 'every_30_minutes'); ?>><?php _e('A cada 30 minutos', '7k-coupons-importer'); ?></option>
                                <option value="hourly" <?php selected($settings['auto_publish_cron_interval'] ?? 'hourly', 'hourly'); ?>><?php _e('A cada hora', '7k-coupons-importer'); ?></option>
                                <option value="twicedaily" <?php selected($settings['auto_publish_cron_interval'] ?? 'hourly', 'twicedaily'); ?>><?php _e('Duas vezes por dia', '7k-coupons-importer'); ?></option>
                                <option value="daily" <?php selected($settings['auto_publish_cron_interval'] ?? 'hourly', 'daily'); ?>><?php _e('Diariamente', '7k-coupons-importer'); ?></option>
                            </select>
                            <p class="description"><?php _e('Frequência com que o sistema verificará cupons aprovados para publicar.', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('Limite por Execução', '7k-coupons-importer'); ?></th>
                        <td>
                            <input type="number" name="auto_publish_limit" value="<?php echo esc_attr($settings['auto_publish_limit'] ?? 10); ?>" min="1" max="100" class="small-text">
                            <p class="description"><?php _e('Número máximo de cupons a serem publicados em cada execução do cron.', '7k-coupons-importer'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Aba Correções -->
            <div id="fixes" class="tab-content">
                <h2><?php _e('Ferramentas de Correção', '7k-coupons-importer'); ?></h2>
                <p class="description"><?php _e('Use essas ferramentas para corrigir problemas comuns no banco de dados.', '7k-coupons-importer'); ?></p>
            </div>

            <p class="submit">
                <input type="submit" name="ci7k_settings_submit" class="button-primary" value="<?php _e('Salvar Configurações', '7k-coupons-importer'); ?>">
            </p>
        </form>

        <!-- Formulário separado para correções -->
        <form method="post" action="" id="fixes-form" style="display: none;">
            <?php wp_nonce_field('ci7k_fixes_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th><?php _e('Resetar Status', '7k-coupons-importer'); ?></th>
                    <td>
                        <button type="submit" name="ci7k_fix_submit" value="reset_pending" onclick="return confirm('<?php _e('Tem certeza? Isso resetará todos os cupons em processamento para pendente.', '7k-coupons-importer'); ?>')" class="button">
                            <?php _e('Resetar cupons "processando" para "pendente"', '7k-coupons-importer'); ?>
                        </button>
                        <input type="hidden" name="fix_action" value="reset_pending">
                        <p class="description"><?php _e('Útil quando cupons ficam travados no status "processando".', '7k-coupons-importer'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><?php _e('Limpar Duplicados', '7k-coupons-importer'); ?></th>
                    <td>
                        <button type="submit" name="ci7k_fix_submit" value="clean_duplicates" onclick="return confirm('<?php _e('Tem certeza? Isso removerá cupons duplicados permanentemente.', '7k-coupons-importer'); ?>')" class="button">
                            <?php _e('Remover cupons duplicados', '7k-coupons-importer'); ?>
                        </button>
                        <input type="hidden" name="fix_action" value="clean_duplicates">
                        <p class="description"><?php _e('Remove cupons com mesmo external_id, mantendo apenas o mais antigo.', '7k-coupons-importer'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><?php _e('Limpar Expirados', '7k-coupons-importer'); ?></th>
                    <td>
                        <button type="submit" name="ci7k_fix_submit" value="clean_expired" onclick="return confirm('<?php _e('Tem certeza? Isso removerá todos os cupons expirados permanentemente.', '7k-coupons-importer'); ?>')" class="button">
                            <?php _e('Remover cupons expirados', '7k-coupons-importer'); ?>
                        </button>
                        <input type="hidden" name="fix_action" value="clean_expired">
                        <p class="description"><?php _e('Remove cupons com data de expiração anterior à data atual.', '7k-coupons-importer'); ?></p>
                    </td>
                </tr>
            </table>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Toggle AI provider settings
    $('#ai_provider').on('change', function() {
        var provider = $(this).val();
        $('#openai_settings, #openai_model_row, #gemini_settings, #gemini_model_row').hide();
        if (provider === 'openai') {
            $('#openai_settings, #openai_model_row').show();
        } else if (provider === 'gemini') {
            $('#gemini_settings, #gemini_model_row').show();
        }
    }).trigger('change');

    // Add OpenAI key
    $('#add_openai_key').on('click', function() {
        var newRow = '<div class="api-key-row" style="margin-bottom: 10px;">' +
            '<input type="password" name="openai_api_keys[]" value="" class="regular-text" placeholder="sk-...">' +
            '<button type="button" class="button remove-api-key"><?php _e('Remover', '7k-coupons-importer'); ?></button>' +
            '</div>';
        $('#openai_keys_container').append(newRow);
    });

    // Add Gemini key
    $('#add_gemini_key').on('click', function() {
        var newRow = '<div class="api-key-row" style="margin-bottom: 10px;">' +
            '<input type="password" name="gemini_api_keys[]" value="" class="regular-text" placeholder="AIza...">' +
            '<button type="button" class="button remove-api-key"><?php _e('Remover', '7k-coupons-importer'); ?></button>' +
            '</div>';
        $('#gemini_keys_container').append(newRow);
    });

    // Remove API key
    $(document).on('click', '.remove-api-key', function() {
        var container = $(this).closest('.api-key-row').parent();
        $(this).closest('.api-key-row').remove();
        
        // Garantir que sempre tenha pelo menos um campo
        if (container.find('.api-key-row').length === 0) {
            var placeholder = container.attr('id') === 'openai_keys_container' ? 'sk-...' : 'AIza...';
            var newRow = '<div class="api-key-row" style="margin-bottom: 10px;">' +
                '<input type="password" name="' + (container.attr('id') === 'openai_keys_container' ? 'openai_api_keys[]' : 'gemini_api_keys[]') + '" value="" class="regular-text" placeholder="' + placeholder + '">' +
                '<button type="button" class="button remove-api-key"><?php _e('Remover', '7k-coupons-importer'); ?></button>' +
                '</div>';
            container.append(newRow);
        }
    });

    // Tab navigation
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).attr('href');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.tab-content').removeClass('active').hide();
        $(target).addClass('active').show();
        
        // Show fixes form if on fixes tab
        if (target === '#fixes') {
            $('#fixes-form').show();
        } else {
            $('#fixes-form').hide();
        }
    });
});
</script>

<style>
.couponis7k-tabs .nav-tab-wrapper {
    margin-bottom: 20px;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.form-table th {
    width: 200px;
}

.form-table textarea {
    width: 100%;
}

.form-table .description {
    margin-top: 5px;
    font-style: italic;
}

#fixes-form {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
}

#fixes-form .button {
    margin-right: 10px;
}
</style>