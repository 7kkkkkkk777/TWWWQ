<?php

namespace CouponImporter;

if (!defined('ABSPATH')) {
    exit;
}

class Queue {

    public function add($coupon_id, $action, $priority = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_queue';

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE coupon_id = %d AND action = %s AND status = 'pending'",
                $coupon_id,
                $action
            )
        );

        if ($existing) {
            return $existing->id;
        }

        $wpdb->insert(
            $table,
            array(
                'coupon_id' => $coupon_id,
                'action' => $action,
                'priority' => $priority,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );

        return $wpdb->insert_id;
    }

    public function process($batch_size = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_queue';

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT %d",
                $batch_size
            )
        );

        if (empty($items)) {
            return 0;
        }

        $processed = 0;
        $core = \CouponImporter\Core::get_instance();

        foreach ($items as $item) {
            $this->update_status($item->id, 'processing');

            try {
                switch ($item->action) {
                    case 'rewrite_title':
                        $this->process_rewrite_title($item->coupon_id);
                        break;

                    case 'rewrite_description':
                        $this->process_rewrite_description($item->coupon_id);
                        break;

                    case 'publish':
                        $this->process_publish($item->coupon_id);
                        break;

                    default:
                        throw new \Exception(sprintf(__('Ação desconhecida: %s', '7k-coupons-importer'), $item->action));
                }

                $this->mark_complete($item->id);
                $processed++;

            } catch (\Exception $e) {
                $this->mark_failed($item->id, $e->getMessage());
                $core->get_logger()->log('error', sprintf(__('Erro ao processar fila: %s', '7k-coupons-importer'), $e->getMessage()), array('queue_id' => $item->id));
            }
        }

        return $processed;
    }

    private function process_rewrite_title($coupon_id) {
        $core = \CouponImporter\Core::get_instance();
        $ai = $core->get_ai_rewriter();

        $post = get_post($coupon_id);
        if (!$post) {
            throw new \Exception(__('Cupom não encontrado', '7k-coupons-importer'));
        }

        $new_title = $ai->rewrite_title($post->post_title);

        if (!empty($new_title)) {
            wp_update_post(array(
                'ID' => $coupon_id,
                'post_title' => $new_title
            ));
        }
    }

    private function process_rewrite_description($coupon_id) {
        $core = \CouponImporter\Core::get_instance();
        $ai = $core->get_ai_rewriter();

        $post = get_post($coupon_id);
        if (!$post) {
            throw new \Exception(__('Cupom não encontrado', '7k-coupons-importer'));
        }

        $new_description = $ai->rewrite_description($post->post_content);

        if (!empty($new_description)) {
            wp_update_post(array(
                'ID' => $coupon_id,
                'post_content' => $new_description
            ));
        }
    }

    private function process_publish($coupon_id) {
        $core = \CouponImporter\Core::get_instance();
        $mapper = $core->get_mapper();

        $result = $mapper->publish_coupon($coupon_id);

        if (!$result) {
            throw new \Exception(__('Falha ao publicar cupom', '7k-coupons-importer'));
        }
    }

    private function update_status($queue_id, $status) {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_queue';

        $wpdb->update(
            $table,
            array('status' => $status),
            array('id' => $queue_id),
            array('%s'),
            array('%d')
        );
    }

    private function mark_complete($queue_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_queue';

        $wpdb->update(
            $table,
            array(
                'status' => 'completed',
                'processed_at' => current_time('mysql')
            ),
            array('id' => $queue_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    private function mark_failed($queue_id, $error_message) {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_queue';

        $wpdb->query('START TRANSACTION');

        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $queue_id)
        );

        if ($item) {
            $new_attempts = $item->attempts + 1;
            $new_status = ($new_attempts >= 3) ? 'failed' : 'pending';

            $wpdb->update(
                $table,
                array(
                    'status' => $new_status,
                    'attempts' => $new_attempts,
                    'error_message' => $error_message,
                    'processed_at' => current_time('mysql')
                ),
                array('id' => $queue_id),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );
        }

        $wpdb->query('COMMIT');
    }

    public function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_queue';

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
    }

    public function clear_completed($days = 7) {
        global $wpdb;
        $table = $wpdb->prefix . 'ci7k_queue';

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE status = 'completed' AND processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}
