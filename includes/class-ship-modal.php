<?php

if (! defined('ABSPATH')) {
    exit;
}

final class Ship_Modal
{
    private static $instance;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'ensure_admin_capabilities'), 20);
        add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
        add_action('save_post_ship_modal', array($this, 'save_modal'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_notices', array($this, 'render_validation_notice'));
        add_filter('manage_ship_modal_posts_columns', array($this, 'admin_columns'));
        add_action('manage_ship_modal_posts_custom_column', array($this, 'render_admin_column'), 10, 2);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));
        add_action('wp_footer', array($this, 'render_sitewide_modals'), 30);
        add_shortcode('ship_modal', array($this, 'shortcode'));
        add_action('wp_ajax_ship_modal_event', array($this, 'record_event'));
        add_action('wp_ajax_nopriv_ship_modal_event', array($this, 'record_event'));
        add_action('wp_ajax_ship_modal_search_targets', array($this, 'search_targets'));
    }

    public static function activate()
    {
        self::instance()->register_post_type();
        flush_rewrite_rules();
    }

    public static function deactivate()
    {
        flush_rewrite_rules();
    }

    public function register_post_type()
    {
        register_post_type('ship_modal', array(
            'labels' => array(
                'name' => 'モーダル',
                'singular_name' => 'モーダル',
                'add_new' => '新規追加',
                'add_new_item' => 'モーダルを追加',
                'edit_item' => 'モーダルを編集',
                'new_item' => '新しいモーダル',
                'view_item' => 'モーダルを表示',
                'search_items' => 'モーダルを検索',
                'not_found' => 'モーダルが見つかりません',
                'menu_name' => 'モーダル',
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-welcome-view-site',
            'supports' => array('title'),
            'capability_type' => array('ship_modal', 'ship_modals'),
            'capabilities' => array(
                'edit_post' => 'edit_ship_modal',
                'read_post' => 'read_ship_modal',
                'delete_post' => 'delete_ship_modal',
                'edit_posts' => 'edit_ship_modals',
                'edit_others_posts' => 'edit_others_ship_modals',
                'publish_posts' => 'publish_ship_modals',
                'read_private_posts' => 'read_private_ship_modals',
                'delete_posts' => 'delete_ship_modals',
                'delete_private_posts' => 'delete_private_ship_modals',
                'delete_published_posts' => 'delete_published_ship_modals',
                'delete_others_posts' => 'delete_others_ship_modals',
                'edit_private_posts' => 'edit_private_ship_modals',
                'edit_published_posts' => 'edit_published_ship_modals',
                'create_posts' => 'create_ship_modals',
            ),
            'map_meta_cap' => true,
            'has_archive' => false,
            'rewrite' => false,
        ));
    }

    public function ensure_admin_capabilities()
    {
        if ('1' === get_option('ship_modal_capabilities_version')) {
            return;
        }
        $capabilities = array(
            'edit_ship_modal', 'read_ship_modal', 'delete_ship_modal',
            'edit_ship_modals', 'edit_others_ship_modals', 'publish_ship_modals',
            'read_private_ship_modals', 'delete_ship_modals', 'delete_private_ship_modals',
            'delete_published_ship_modals', 'delete_others_ship_modals',
            'edit_private_ship_modals', 'edit_published_ship_modals', 'create_ship_modals',
        );
        foreach (wp_roles()->roles as $role_name => $role_data) {
            $role = get_role($role_name);
            if (! $role) {
                continue;
            }
            foreach ($capabilities as $capability) {
                if ('administrator' === $role_name) {
                    $role->add_cap($capability);
                } else {
                    $role->remove_cap($capability);
                }
            }
        }
        update_option('ship_modal_capabilities_version', '1', false);
    }

    public function register_meta_boxes()
    {
        add_meta_box('ship_modal_content', 'モーダルの内容', array($this, 'render_content_box'), 'ship_modal', 'normal', 'high');
        add_meta_box('ship_modal_display', '表示設定', array($this, 'render_display_box'), 'ship_modal', 'normal', 'high');
        add_meta_box('ship_modal_stats', '計測', array($this, 'render_stats_box'), 'ship_modal', 'side', 'default');
    }

    private function meta($post_id, $key, $default = '')
    {
        $value = get_post_meta($post_id, '_ship_modal_' . $key, true);
        return $value === '' ? $default : $value;
    }

    private function select($name, $value, $options)
    {
        echo '<select name="ship_modal_' . esc_attr($name) . '" id="ship-modal-' . esc_attr($name) . '" class="widefat">';
        foreach ($options as $option_value => $label) {
            echo '<option value="' . esc_attr($option_value) . '" ' . selected($value, $option_value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private function targetable_post_types()
    {
        $types = get_post_types(array('public' => true), 'objects');
        foreach (array('attachment', 'ship_modal') as $excluded_type) {
            unset($types[$excluded_type]);
        }
        return $types;
    }

    public function search_targets()
    {
        check_ajax_referer('ship_modal_target_search', 'nonce');
        $modal_post_id = isset($_POST['modal_post_id']) ? absint($_POST['modal_post_id']) : 0;
        if (! current_user_can('manage_options') || ($modal_post_id && ! current_user_can('edit_post', $modal_post_id))) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }
        $search = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        if ($search !== '' && mb_strlen($search) < 2) {
            wp_send_json_success(array());
        }
        $types = $this->targetable_post_types();
        $requested_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';
        $post_types = $requested_type && isset($types[$requested_type]) ? array($requested_type) : array_keys($types);
        $posts = get_posts(array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => 30,
            's' => $search,
            'orderby' => $search !== '' ? 'relevance' : 'date',
            'order' => 'DESC',
        ));
        $results = array();
        foreach ($posts as $target_post) {
            $type = get_post_type($target_post);
            $results[] = array(
                'id' => (int) $target_post->ID,
                'title' => get_the_title($target_post),
                'type' => isset($types[$type]) ? $types[$type]->labels->singular_name : $type,
            );
        }
        wp_send_json_success($results);
    }

    private function render_page_row($index, $page = array())
    {
        $image_id = isset($page['image_id']) ? absint($page['image_id']) : 0;
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
        $heading = isset($page['heading']) ? $page['heading'] : '';
        $body = isset($page['body']) ? $page['body'] : (isset($page['html']) ? wp_strip_all_tags($page['html']) : '');
        $link_url = isset($page['link_url']) ? $page['link_url'] : '';
        $link_new_tab = ! empty($page['link_new_tab']);
        $buttons = isset($page['buttons']) && is_array($page['buttons']) ? $page['buttons'] : array();
        ?>
        <div class="ship-modal-page-row" data-page-index="<?php echo esc_attr($index); ?>">
            <div class="ship-modal-page-row__header"><strong>ページ <?php echo esc_html(is_numeric($index) ? ((int) $index + 1) : '__NUMBER__'); ?></strong><button type="button" class="button-link-delete ship-modal-remove-page">このページを削除</button></div>
            <div class="ship-modal-page-row__grid">
                <div>
                    <input type="hidden" name="ship_modal_pages[<?php echo esc_attr($index); ?>][image_id]" id="ship-modal-page-image-<?php echo esc_attr($index); ?>" value="<?php echo esc_attr($image_id); ?>">
                    <div id="ship-modal-page-preview-<?php echo esc_attr($index); ?>" class="ship-modal-page-preview"><?php if ($image_url) : ?><img src="<?php echo esc_url($image_url); ?>" alt=""><?php endif; ?></div>
                    <button type="button" class="button ship-modal-page-select-image" data-target-id="ship-modal-page-image-<?php echo esc_attr($index); ?>" data-target-preview="ship-modal-page-preview-<?php echo esc_attr($index); ?>">画像を選択</button>
                </div>
                <div>
                    <label>ページ見出し</label>
                    <input type="text" class="widefat" name="ship_modal_pages[<?php echo esc_attr($index); ?>][heading]" value="<?php echo esc_attr($heading); ?>">
                    <label>ページ本文</label>
                    <textarea class="large-text" rows="4" name="ship_modal_pages[<?php echo esc_attr($index); ?>][body]"><?php echo esc_textarea($body); ?></textarea>
                    <label>画像クリック先URL</label>
                    <input type="url" class="widefat" name="ship_modal_pages[<?php echo esc_attr($index); ?>][link_url]" value="<?php echo esc_attr($link_url); ?>" placeholder="https://example.com/">
                    <label><input type="checkbox" name="ship_modal_pages[<?php echo esc_attr($index); ?>][link_new_tab]" value="1" <?php checked($link_new_tab, true); ?>> 別タブで開く</label>
                    <strong class="ship-modal-admin-subheading">ボタン（最大2個）</strong>
                    <?php $this->render_button_fields($buttons, 2, 'ship_modal_pages[' . $index . '][buttons]'); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_button_fields($buttons, $max, $prefix)
    {
        $buttons = is_array($buttons) ? array_values($buttons) : array();
        echo '<p class="description ship-modal-button-help">文言の改行は <code>&lt;br&gt;</code> を入力してください。「閉じる」を選んだボタンはURL不要です。長い文言は画面幅に合わせて折り返します。</p>';
        for ($index = 0; $index < $max; $index++) {
            $button = isset($buttons[$index]) && is_array($buttons[$index]) ? $buttons[$index] : array();
            $label = isset($button['label']) ? $button['label'] : '';
            $url = isset($button['url']) ? $button['url'] : '';
            $action = isset($button['action']) && 'close' === $button['action'] ? 'close' : 'link';
            $style = isset($button['style']) && in_array($button['style'], array('primary', 'secondary'), true) ? $button['style'] : 'primary';
            $new_tab = ! empty($button['new_tab']);
            $base = $prefix . '[' . $index . ']';
            ?>
            <div class="ship-modal-button-field">
                <span class="ship-modal-button-field__number"><?php echo esc_html((string) ($index + 1)); ?></span>
                <input type="text" name="<?php echo esc_attr($base . '[label]'); ?>" value="<?php echo esc_attr($label); ?>" placeholder="ボタン文言（改行は&lt;br&gt;）">
                <input type="url" class="ship-modal-button-url" name="<?php echo esc_attr($base . '[url]'); ?>" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com/">
                <select class="ship-modal-button-action" name="<?php echo esc_attr($base . '[action]'); ?>"><option value="link" <?php selected($action, 'link'); ?>>リンク</option><option value="close" <?php selected($action, 'close'); ?>>閉じる</option></select>
                <select name="<?php echo esc_attr($base . '[style]'); ?>"><option value="primary" <?php selected($style, 'primary'); ?>>メイン</option><option value="secondary" <?php selected($style, 'secondary'); ?>>サブ</option></select>
                <label><input type="checkbox" name="<?php echo esc_attr($base . '[new_tab]'); ?>" value="1" <?php checked($new_tab, true); ?>> 別タブ</label>
            </div>
            <?php
        }
    }

    public function render_content_box($post)
    {
        wp_nonce_field('ship_modal_save', 'ship_modal_nonce');
        $type = $this->meta($post->ID, 'content_type', 'image');
        $design = $this->meta($post->ID, 'design', 'center');
        $html = $this->meta($post->ID, 'html');
        $image_id = absint($this->meta($post->ID, 'image_id'));
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';
        $link_url = $this->meta($post->ID, 'link_url');
        $link_new_tab = '1' === $this->meta($post->ID, 'link_new_tab', '0');
        $image_position = $this->meta($post->ID, 'image_position', 'top');
        $heading = $this->meta($post->ID, 'heading');
        $body = $this->meta($post->ID, 'body', '');
        if ($body === '') {
            $body = wp_strip_all_tags($html);
        }
        $buttons = $this->meta($post->ID, 'buttons', array());
        $pages = $this->meta($post->ID, 'pages', array());
        $form_state_key = 'ship_modal_form_' . get_current_user_id() . '_' . $post->ID;
        $form_state = get_transient($form_state_key);
        if (! is_array($form_state)) {
            $form_state = get_post_meta($post->ID, '_ship_modal_form_state_' . get_current_user_id(), true);
            if (is_array($form_state)) {
                delete_post_meta($post->ID, '_ship_modal_form_state_' . get_current_user_id());
            }
        }
        if (is_array($form_state)) {
            delete_transient($form_state_key);
            $type = isset($form_state['type']) ? $form_state['type'] : $type;
            $html = isset($form_state['html']) ? $form_state['html'] : $html;
            $heading = isset($form_state['heading']) ? $form_state['heading'] : $heading;
            $body = isset($form_state['body']) ? $form_state['body'] : $body;
            $image_id = isset($form_state['image_id']) ? absint($form_state['image_id']) : $image_id;
            $link_url = isset($form_state['link_url']) ? $form_state['link_url'] : $link_url;
            $buttons = isset($form_state['buttons']) && is_array($form_state['buttons']) ? $form_state['buttons'] : $buttons;
            $pages = isset($form_state['pages']) && is_array($form_state['pages']) ? $form_state['pages'] : $pages;
        }
        if (! is_array($pages) || ! $pages) {
            $pages = array(array());
        }
        $border_radius = min(48, max(0, (int) $this->meta($post->ID, 'border_radius', 0)));
        $padding = min(64, max(0, (int) $this->meta($post->ID, 'padding', 20)));
        $max_width = min(1200, max(280, (int) $this->meta($post->ID, 'max_width', 620)));
        ?>
        <p class="description">HTML、画像バナー、画像＋HTML、複数ページのページャーから選べます。ページャーは各ページに画像とHTMLを設定できます。</p>
        <table class="form-table ship-modal-form-table">
            <tr><th><label for="ship-modal-content_type">フレーム</label></th><td><?php $this->select('content_type', $type, array('html' => '旧：自由HTML', 'image' => '画像のみ', 'hybrid' => '画像＋テキスト（ボタン任意）', 'text' => 'テキスト（ボタン任意）', 'pager' => 'ページャー（複数ページ）')); ?></td></tr>
            <tr class="ship-modal-legacy-html-row"><th><label for="ship-modal-html">HTML</label></th><td><?php wp_editor($html, 'ship_modal_html', array('textarea_name' => 'ship_modal_html', 'textarea_rows' => 10, 'media_buttons' => false, 'teeny' => true)); ?></td></tr>
            <tr class="ship-modal-copy-row"><th><label for="ship-modal-heading">見出し</label></th><td><input type="text" class="widefat" name="ship_modal_heading" id="ship-modal-heading" value="<?php echo esc_attr($heading); ?>" placeholder="見出し（任意）"><p class="description">必須ではありません。長い文言は画面幅に合わせて折り返します。</p></td></tr>
            <tr class="ship-modal-copy-row"><th><label for="ship-modal-body">本文</label></th><td><textarea class="large-text" rows="5" name="ship_modal_body" id="ship-modal-body" placeholder="本文（任意）"><?php echo esc_textarea($body); ?></textarea><p class="description">本文はレイアウト用HTML不可。長さによる保存制限はありません。</p></td></tr>
            <tr class="ship-modal-single-image-row">
                <th>画像</th>
                <td>
                    <input type="hidden" name="ship_modal_image_id" id="ship-modal-image-id" value="<?php echo esc_attr($image_id); ?>">
                    <div id="ship-modal-image-preview"><?php if ($image_url) : ?><img src="<?php echo esc_url($image_url); ?>" alt="" style="max-width:100%;height:auto;"><?php endif; ?></div>
                    <p><button type="button" class="button" id="ship-modal-select-image">画像を選択</button> <button type="button" class="button" id="ship-modal-remove-image">削除</button></p>
                    <p class="description">メディアライブラリの画像を使用します。</p>
                </td>
            </tr>
            <tr class="ship-modal-single-image-row"><th><label for="ship-modal-link_url">クリック先URL</label></th><td><input type="url" class="widefat" name="ship_modal_link_url" id="ship-modal-link_url" value="<?php echo esc_attr($link_url); ?>" placeholder="https://example.com/"><br><label><input type="checkbox" name="ship_modal_link_new_tab" value="1" <?php checked($link_new_tab, true); ?>> 別タブで開く</label><p class="description">空欄なら画像はリンクになりません。</p></td></tr>
            <tr class="ship-modal-hybrid-image-row"><th><label for="ship-modal-image_position">画像の位置</label></th><td><?php $this->select('image_position', $image_position, array('top' => '上', 'left' => '左', 'right' => '右')); ?></td></tr>
            <tr class="ship-modal-buttons-row"><th>ボタン</th><td><p class="description">任意・最大3個。文言の改行は&lt;br&gt;で指定できます。長い文言は画面幅に合わせて折り返します。</p><?php $this->render_button_fields($buttons, 3, 'ship_modal_buttons'); ?></td></tr>
            <tr class="ship-modal-pages-row"><th>ページ</th><td><div id="ship-modal-pages"><?php foreach ($pages as $index => $page) { $this->render_page_row($index, is_array($page) ? $page : array()); } ?></div><p><button type="button" class="button" id="ship-modal-add-page">＋ ページを追加</button></p><p class="description">各ページは画像とHTMLを個別に設定できます。</p></td></tr>
            <tr><th><label for="ship-modal-design">デザイン</label></th><td><?php $this->select('design', $design, array('center' => '中央カード', 'bottom' => '画面下部バナー', 'side' => '右下ポップアップ', 'fullscreen' => 'フルスクリーン')); ?></td></tr>
            <tr><th><label for="ship-modal-border_radius">角丸（border-radius）</label></th><td><input type="number" min="0" max="48" step="1" class="small-text" name="ship_modal_border_radius" id="ship-modal-border_radius" value="<?php echo esc_attr($border_radius); ?>"> px <p class="description">0〜48px。0なら角丸なし。</p></td></tr>
            <tr><th><label for="ship-modal-padding">内側の余白（padding）</label></th><td><input type="number" min="0" max="64" step="1" class="small-text" name="ship_modal_padding" id="ship-modal-padding" value="<?php echo esc_attr($padding); ?>"> px <p class="description">0〜64px。画像のみフレームは画像をコンテナいっぱいに表示します。</p></td></tr>
            <tr><th><label for="ship-modal-max_width">最大幅（max-width）</label></th><td><input type="number" min="280" max="1200" step="10" class="small-text" name="ship_modal_max_width" id="ship-modal-max_width" value="<?php echo esc_attr($max_width); ?>"> px <p class="description">280〜1200px。スマホでは画面幅に合わせて縮小します。</p></td></tr>
        </table>
        <script type="text/html" id="ship-modal-page-template"><?php $this->render_page_row('__INDEX__', array()); ?></script>
        <?php
    }

    public function render_display_box($post)
    {
        $scope = $this->meta($post->ID, 'scope', 'all');
        $trigger = $this->meta($post->ID, 'trigger', 'auto');
        $delay = max(0, (int) $this->meta($post->ID, 'delay', 2));
        $scroll_threshold = min(95, max(10, (int) $this->meta($post->ID, 'scroll_threshold', 50)));
        $frequency = $this->meta($post->ID, 'frequency', 'session');
        $start = $this->meta($post->ID, 'start_at');
        $end = $this->meta($post->ID, 'end_at');
        $show_close = $this->meta($post->ID, 'show_close', '1');
        $close_overlay = $this->meta($post->ID, 'close_overlay', '1');
        $trigger_text = $this->meta($post->ID, 'trigger_text', 'キャンペーン詳細を見る');
        $trigger_bg_color = sanitize_hex_color($this->meta($post->ID, 'trigger_bg_color', '#0f766e')) ?: '#0f766e';
        $trigger_text_color = sanitize_hex_color($this->meta($post->ID, 'trigger_text_color', '#ffffff')) ?: '#ffffff';
        $trigger_position = $this->meta($post->ID, 'trigger_position', 'right');
        if (! in_array($trigger_position, array('left', 'center', 'right'), true)) {
            $trigger_position = 'right';
        }
        $target_ids = array_values(array_filter(array_map('absint', (array) $this->meta($post->ID, 'target_ids', array()))));
        $targetable_types = $this->targetable_post_types();
        $selected_posts = $target_ids ? get_posts(array(
            'post__in' => $target_ids,
            'post_type' => array_keys($targetable_types),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'post__in',
        )) : array();
        ?>
        <table class="form-table ship-modal-form-table">
            <tr><th>表示対象</th><td>
                <div class="ship-modal-scope-options" role="radiogroup" aria-label="表示対象">
                    <?php foreach (array('all' => '全ページ', 'front' => 'トップページのみ', 'singular' => '投稿・固定ページ（全て）', 'selected' => '指定ページのみ', 'shortcode' => 'ショートコードのみ') as $scope_value => $scope_label) : ?>
                        <label><input type="radio" name="ship_modal_scope" value="<?php echo esc_attr($scope_value); ?>" <?php checked($scope, $scope_value); ?>> <?php echo esc_html($scope_label); ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="ship-modal-target-picker">
                    <div class="ship-modal-target-picker__heading"><strong>指定ページを追加</strong><span class="ship-modal-target-count" aria-live="polite"></span></div>
                    <div class="ship-modal-target-search-row"><input type="search" id="ship-modal-target-search" class="widefat" placeholder="ページ名・記事タイトルで検索（2文字以上）"><select id="ship-modal-target-post-type"><option value="">すべての種類</option><?php foreach ($targetable_types as $target_type => $target_type_object) : ?><option value="<?php echo esc_attr($target_type); ?>"><?php echo esc_html($target_type_object->labels->name); ?></option><?php endforeach; ?></select></div>
                    <div id="ship-modal-target-results" class="ship-modal-target-results"><p class="description">検索結果から表示対象を追加できます。</p></div>
                    <div class="ship-modal-target-picker__selected-heading"><strong>選択中</strong><button type="button" class="button-link" id="ship-modal-target-clear">すべて解除</button></div>
                    <div id="ship-modal-target-selected" class="ship-modal-target-selected">
                        <?php foreach ($selected_posts as $selected_post) : $selected_type = get_post_type($selected_post); $selected_type_label = isset($targetable_types[$selected_type]) ? $targetable_types[$selected_type]->labels->singular_name : $selected_type; ?>
                            <span class="ship-modal-target-chip" data-target-id="<?php echo esc_attr($selected_post->ID); ?>"><input type="hidden" name="ship_modal_target_ids[]" value="<?php echo esc_attr($selected_post->ID); ?>"><span><?php echo esc_html('[' . $selected_type_label . '] ' . get_the_title($selected_post)); ?></span><button type="button" class="ship-modal-target-remove" aria-label="選択を解除">×</button></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">公開中のページ・記事だけが検索対象です。指定ページのみを選んだ場合、ここで選択したページにだけ表示されます。</p>
                </div>
            </td></tr>
            <tr><th><label for="ship-modal-trigger">起動方法</label></th><td><?php $this->select('trigger', $trigger, array('auto' => '遅延して自動表示', 'scroll' => 'スクロール到達で表示', 'exit_intent' => '離脱意図で表示（PCのみ）', 'manual' => 'ボタンから表示')); ?><p class="description">離脱意図は、PCでマウスをブラウザ上端へ移動したタイミングで表示します。戻るボタンの履歴フックは、誤操作・アクセシビリティ・検索評価への影響があるため対応していません。</p></td></tr>
            <tr class="ship-modal-delay-row"><th><label for="ship-modal-delay">表示までの秒数</label></th><td><input type="number" min="0" max="120" step="1" class="small-text" name="ship_modal_delay" id="ship-modal-delay" value="<?php echo esc_attr($delay); ?>"> 秒</td></tr>
            <tr class="ship-modal-scroll-row"><th><label for="ship-modal-scroll_threshold">スクロール到達率</label></th><td><input type="number" min="10" max="95" step="5" class="small-text" name="ship_modal_scroll_threshold" id="ship-modal-scroll_threshold" value="<?php echo esc_attr($scroll_threshold); ?>"> ％<p class="description">ページ全体の指定割合までスクロールすると表示します。</p></td></tr>
            <tr class="ship-modal-trigger-text-row"><th><label for="ship-modal-trigger_text">ボタン文言</label></th><td><input type="text" class="widefat" name="ship_modal_trigger_text" id="ship-modal-trigger_text" value="<?php echo esc_attr($trigger_text); ?>"></td></tr>
            <tr class="ship-modal-trigger-style-row"><th>ボタンデザイン</th><td><label>背景色 <input type="color" name="ship_modal_trigger_bg_color" value="<?php echo esc_attr($trigger_bg_color); ?>"></label> <label>文字色 <input type="color" name="ship_modal_trigger_text_color" value="<?php echo esc_attr($trigger_text_color); ?>"></label><br><label for="ship-modal-trigger_position">配置 </label><?php $this->select('trigger_position', $trigger_position, array('left' => '左下', 'center' => '中央下', 'right' => '右下')); ?><p class="description">手動表示ボタンの背景色・文字色・画面下部の配置を設定します。</p></td></tr>
            <tr><th><label for="ship-modal-frequency">表示頻度</label></th><td><?php $this->select('frequency', $frequency, array('always' => '毎回', 'session' => 'セッションごとに1回', 'day' => '1日1回', 'once' => 'ユーザーごとに1回')); ?></td></tr>
            <tr><th><label for="ship-modal-start_at">開始日時</label></th><td><input type="datetime-local" class="widefat" name="ship_modal_start_at" id="ship-modal-start_at" value="<?php echo esc_attr($start); ?>"><p class="description">空欄ならすぐ表示</p></td></tr>
            <tr><th><label for="ship-modal-end_at">終了日時</label></th><td><input type="datetime-local" class="widefat" name="ship_modal_end_at" id="ship-modal-end_at" value="<?php echo esc_attr($end); ?>"><p class="description">空欄なら期限なし</p></td></tr>
            <tr><th>閉じる操作</th><td><input type="hidden" name="ship_modal_show_close" value="0"><label><input type="checkbox" name="ship_modal_show_close" value="1" <?php checked($show_close, '1'); ?>> 閉じるボタンを表示</label><br><input type="hidden" name="ship_modal_close_overlay" value="0"><label><input type="checkbox" name="ship_modal_close_overlay" value="1" <?php checked($close_overlay, '1'); ?>> 背景クリックで閉じる</label><p class="description">チェックを外した場合も確実にOFFとして保存されます。</p></td></tr>
        </table>
        <p class="description">ショートコード例：<code>[ship_modal id="<?php echo esc_attr($post->ID); ?>"]</code></p>
        <?php
    }

    public function render_stats_box($post)
    {
        $impressions = (int) get_post_meta($post->ID, '_ship_modal_impressions', true);
        $clicks = (int) get_post_meta($post->ID, '_ship_modal_clicks', true);
        $closes = (int) get_post_meta($post->ID, '_ship_modal_closes', true);
        $page_views = (int) get_post_meta($post->ID, '_ship_modal_page_views', true);
        $rate = $impressions > 0 ? round(($clicks / $impressions) * 100, 1) : 0;
        echo '<p><strong>表示回数：</strong> ' . number_format_i18n($impressions) . '</p>';
        echo '<p><strong>クリック数：</strong> ' . number_format_i18n($clicks) . '</p>';
        echo '<p><strong>クリック率：</strong> ' . esc_html($rate) . '%</p>';
        echo '<p><strong>閉じる回数：</strong> ' . number_format_i18n($closes) . '</p>';
        echo '<p><strong>ページ閲覧数：</strong> ' . number_format_i18n($page_views) . '</p>';
        echo '<p class="description">GTM向けにdataLayerへ表示・クリック・閉じる・ページ切り替えイベントも送信します。</p>';
    }

    private function normalize_buttons($raw_buttons, $max, $context, &$errors)
    {
        $normalized = array();
        if (! is_array($raw_buttons)) {
            return $normalized;
        }
        foreach (array_slice(wp_unslash($raw_buttons), 0, $max) as $button) {
            if (! is_array($button)) {
                continue;
            }
            $label = isset($button['label']) ? wp_kses($button['label'], array('br' => array())) : '';
            $label = preg_replace('/\s*<br\s*\/?>\s*/i', '<br>', trim($label));
            $label_text = trim(wp_strip_all_tags($label));
            $url = isset($button['url']) ? esc_url_raw($button['url']) : '';
            $action = isset($button['action']) && 'close' === $button['action'] ? 'close' : 'link';
            $style = isset($button['style']) && in_array($button['style'], array('primary', 'secondary'), true) ? $button['style'] : 'primary';
            $new_tab = ! empty($button['new_tab']) ? '1' : '0';
            if ($label_text === '') {
                continue;
            }
            if ('link' === $action) {
                if ($url === '' || ! preg_match('/^(https?:\\/\\/|tel:|mailto:)/i', $url)) {
                    continue;
                }
            }
            $normalized[] = array('label' => $label, 'url' => 'close' === $action ? '' : $url, 'action' => $action, 'style' => $style, 'new_tab' => 'close' === $action ? '0' : $new_tab);
        }
        return $normalized;
    }

    public function save_modal($post_id, $post)
    {
        if (! isset($_POST['ship_modal_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ship_modal_nonce'])), 'ship_modal_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (! current_user_can('manage_options') || ! current_user_can('edit_post', $post_id)) {
            return;
        }

        // チェックボックスは未チェック時にPOST自体が送信されないため、値を先に確定する。
        // 内容側でエラーが発生しても、表示設定が意図せず戻らないようにする。
        $show_close = ! empty($_POST['ship_modal_show_close']) ? '1' : '0';
        $close_overlay = ! empty($_POST['ship_modal_close_overlay']) ? '1' : '0';
        update_post_meta($post_id, '_ship_modal_show_close', $show_close);
        update_post_meta($post_id, '_ship_modal_close_overlay', $close_overlay);

        $errors = array();
        $type = isset($_POST['ship_modal_content_type']) ? sanitize_key(wp_unslash($_POST['ship_modal_content_type'])) : $this->meta($post_id, 'content_type', 'image');
        $allowed_types = array('html', 'image', 'hybrid', 'text', 'pager');
        if (! in_array($type, $allowed_types, true)) {
            $type = 'html';
        }
        $heading = isset($_POST['ship_modal_heading']) ? sanitize_text_field(wp_unslash($_POST['ship_modal_heading'])) : $this->meta($post_id, 'heading', '');
        $body_raw = isset($_POST['ship_modal_body']) ? wp_unslash($_POST['ship_modal_body']) : $this->meta($post_id, 'body', '');
        $body = wp_kses($body_raw, array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true)));
        $html = isset($_POST['ship_modal_html']) ? wp_unslash($_POST['ship_modal_html']) : $this->meta($post_id, 'html', '');
        $image_id = isset($_POST['ship_modal_image_id']) ? absint($_POST['ship_modal_image_id']) : absint($this->meta($post_id, 'image_id', 0));
        $buttons = $this->normalize_buttons(isset($_POST['ship_modal_buttons']) ? $_POST['ship_modal_buttons'] : $this->meta($post_id, 'buttons', array()), 3, 'ボタン', $errors);

        $border_radius = isset($_POST['ship_modal_border_radius']) ? min(48, max(0, absint($_POST['ship_modal_border_radius']))) : 0;
        $padding = isset($_POST['ship_modal_padding']) ? min(64, max(0, absint($_POST['ship_modal_padding']))) : 20;
        $max_width = isset($_POST['ship_modal_max_width']) ? min(1200, max(280, absint($_POST['ship_modal_max_width']))) : 620;
        $scope = isset($_POST['ship_modal_scope']) ? sanitize_key(wp_unslash($_POST['ship_modal_scope'])) : 'all';
        $raw_target_ids = isset($_POST['ship_modal_target_ids']) && is_array($_POST['ship_modal_target_ids']) ? $_POST['ship_modal_target_ids'] : array();
        if ('hybrid' === $type) {
            // 必須・文字数による保存ブロックは行わない。
        }
        if ('text' === $type) {
            // 必須・文字数による保存ブロックは行わない。
        }

        $pages = array();
        if (isset($_POST['ship_modal_pages']) && is_array($_POST['ship_modal_pages'])) {
            foreach (array_values(wp_unslash($_POST['ship_modal_pages'])) as $index => $page) {
                if (! is_array($page)) {
                    continue;
                }
                $page_heading = isset($page['heading']) ? sanitize_text_field($page['heading']) : '';
                $page_body = isset($page['body']) ? wp_kses($page['body'], array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true))) : '';
                $page_buttons = $this->normalize_buttons(isset($page['buttons']) ? $page['buttons'] : array(), 2, 'ページ' . ((int) $index + 1) . 'のボタン', $errors);
                $has_page_content = $page_heading !== '' || trim(wp_strip_all_tags($page_body)) !== '' || ! empty($page['image_id']);
                if (! $has_page_content) {
                    continue;
                }
                $pages[] = array(
                    'image_id' => isset($page['image_id']) ? absint($page['image_id']) : 0,
                    'heading' => $page_heading,
                    'body' => $page_body,
                    'link_url' => isset($page['link_url']) ? esc_url_raw($page['link_url']) : '',
                    'link_new_tab' => ! empty($page['link_new_tab']) ? '1' : '0',
                    'buttons' => $page_buttons,
                );
            }
        }
        // ページ数による保存ブロックは行わない。

        if ($errors) {
            $form_key = 'ship_modal_form_' . get_current_user_id() . '_' . $post_id;
            $form_state = array(
                'type' => $type,
                'html' => $html,
                'heading' => $heading,
                'body' => $body,
                'image_id' => $image_id,
                'link_url' => isset($_POST['ship_modal_link_url']) ? esc_url_raw(wp_unslash($_POST['ship_modal_link_url'])) : $this->meta($post_id, 'link_url', ''),
                'buttons' => isset($_POST['ship_modal_buttons']) && is_array($_POST['ship_modal_buttons']) ? wp_unslash($_POST['ship_modal_buttons']) : $buttons,
                'pages' => isset($_POST['ship_modal_pages']) && is_array($_POST['ship_modal_pages']) ? wp_unslash($_POST['ship_modal_pages']) : $pages,
            );
            if (! set_transient($form_key, $form_state, 60)) {
                update_post_meta($post_id, '_ship_modal_form_state_' . get_current_user_id(), $form_state);
            }
            $error_key = 'ship_modal_errors_' . get_current_user_id() . '_' . $post_id;
            if (! set_transient($error_key, $errors, 60)) {
                update_post_meta($post_id, '_ship_modal_errors_' . get_current_user_id(), $errors);
            }
            return;
        }

        delete_transient('ship_modal_form_' . get_current_user_id() . '_' . $post_id);
        delete_post_meta($post_id, '_ship_modal_form_state_' . get_current_user_id());
        delete_post_meta($post_id, '_ship_modal_errors_' . get_current_user_id());

        foreach (array('link_url', 'trigger_text', 'start_at', 'end_at') as $field) {
            $value = isset($_POST['ship_modal_' . $field]) ? wp_unslash($_POST['ship_modal_' . $field]) : '';
            update_post_meta($post_id, '_ship_modal_' . $field, sanitize_text_field($value));
        }
        update_post_meta($post_id, '_ship_modal_html', wp_kses_post($html));
        update_post_meta($post_id, '_ship_modal_heading', $heading);
        update_post_meta($post_id, '_ship_modal_body', $body);
        update_post_meta($post_id, '_ship_modal_buttons', $buttons);
        update_post_meta($post_id, '_ship_modal_image_id', $image_id);
        update_post_meta($post_id, '_ship_modal_link_new_tab', isset($_POST['ship_modal_link_new_tab']) ? '1' : '0');
        $trigger_bg_color = isset($_POST['ship_modal_trigger_bg_color']) ? sanitize_hex_color(wp_unslash($_POST['ship_modal_trigger_bg_color'])) : '';
        $trigger_text_color = isset($_POST['ship_modal_trigger_text_color']) ? sanitize_hex_color(wp_unslash($_POST['ship_modal_trigger_text_color'])) : '';
        update_post_meta($post_id, '_ship_modal_trigger_bg_color', $trigger_bg_color ?: '#0f766e');
        update_post_meta($post_id, '_ship_modal_trigger_text_color', $trigger_text_color ?: '#ffffff');
        update_post_meta($post_id, '_ship_modal_delay', isset($_POST['ship_modal_delay']) ? min(120, max(0, absint($_POST['ship_modal_delay']))) : 2);
        update_post_meta($post_id, '_ship_modal_scroll_threshold', isset($_POST['ship_modal_scroll_threshold']) ? min(95, max(10, absint($_POST['ship_modal_scroll_threshold']))) : 50);
        update_post_meta($post_id, '_ship_modal_show_close', $show_close);
        update_post_meta($post_id, '_ship_modal_close_overlay', $close_overlay);
        update_post_meta($post_id, '_ship_modal_pages', $pages);
        update_post_meta($post_id, '_ship_modal_border_radius', $border_radius);
        update_post_meta($post_id, '_ship_modal_padding', $padding);
        update_post_meta($post_id, '_ship_modal_max_width', $max_width);
        $target_ids = array();
        $targetable_types = $this->targetable_post_types();
        foreach (array_unique(array_map('absint', wp_unslash($raw_target_ids))) as $target_id) {
            $target_post = $target_id ? get_post($target_id) : null;
            if ($target_post && 'publish' === $target_post->post_status && isset($targetable_types[$target_post->post_type])) {
                $target_ids[] = $target_id;
            }
        }
        update_post_meta($post_id, '_ship_modal_target_ids', $target_ids);
        $allowed = array(
            'content_type' => $allowed_types,
            'image_position' => array('top', 'left', 'right'),
            'design' => array('center', 'bottom', 'side', 'fullscreen'),
            'scope' => array('all', 'front', 'singular', 'selected', 'shortcode'),
            'trigger' => array('auto', 'scroll', 'exit_intent', 'manual'),
            'trigger_position' => array('left', 'center', 'right'),
            'frequency' => array('always', 'session', 'day', 'once'),
        );
        foreach ($allowed as $field => $values) {
            $value = isset($_POST['ship_modal_' . $field]) ? sanitize_key(wp_unslash($_POST['ship_modal_' . $field])) : '';
            update_post_meta($post_id, '_ship_modal_' . $field, in_array($value, $values, true) ? $value : reset($values));
        }
    }

    public function render_validation_notice()
    {
        if (empty($_GET['post'])) {
            return;
        }
        $key = 'ship_modal_errors_' . get_current_user_id() . '_' . absint($_GET['post']);
        $errors = get_transient($key);
        if (! is_array($errors)) {
            $errors = get_post_meta(absint($_GET['post']), '_ship_modal_errors_' . get_current_user_id(), true);
            if (is_array($errors)) {
                delete_post_meta(absint($_GET['post']), '_ship_modal_errors_' . get_current_user_id());
            }
        }
        if (! is_array($errors) || ! $errors) {
            return;
        }
        delete_transient($key);
        echo '<div class="notice notice-error"><p><strong>モーダルを保存できませんでした。</strong></p><ul>';
        foreach ($errors as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul></div>';
    }

    public function enqueue_admin_assets($hook)
    {
        $screen = get_current_screen();
        if (! $screen || 'ship_modal' !== $screen->post_type || ! in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script('ship-modal-admin', SHIP_MODAL_URL . 'assets/js/admin.js', array('jquery', 'media-editor', 'media-views', 'media-upload'), SHIP_MODAL_VERSION, true);
        wp_localize_script('ship-modal-admin', 'ShipModalAdminConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'targetSearchNonce' => wp_create_nonce('ship_modal_target_search'),
            'postId' => isset($_GET['post']) ? absint($_GET['post']) : 0,
        ));
        wp_enqueue_style('ship-modal-admin', SHIP_MODAL_URL . 'assets/css/admin.css', array(), SHIP_MODAL_VERSION);
    }

    public function admin_columns($columns)
    {
        $columns['ship_modal_scope'] = '表示範囲';
        $columns['ship_modal_schedule'] = '期間';
        $columns['ship_modal_stats'] = '計測';
        return $columns;
    }

    public function render_admin_column($column, $post_id)
    {
        if ('ship_modal_scope' === $column) {
            $labels = array('all' => '全ページ', 'front' => 'トップ', 'singular' => '投稿・固定ページ（全て）', 'selected' => '指定ページ', 'shortcode' => 'ショートコード');
            $scope = $this->meta($post_id, 'scope', 'all');
            echo esc_html(isset($labels[$scope]) ? $labels[$scope] : $scope);
        } elseif ('ship_modal_schedule' === $column) {
            $start = $this->meta($post_id, 'start_at');
            $end = $this->meta($post_id, 'end_at');
            echo esc_html(($start ?: '即時') . ' 〜 ' . ($end ?: '無期限'));
        } elseif ('ship_modal_stats' === $column) {
            echo esc_html(number_format_i18n((int) get_post_meta($post_id, '_ship_modal_impressions', true)) . ' views / ' . number_format_i18n((int) get_post_meta($post_id, '_ship_modal_clicks', true)) . ' clicks');
        }
    }

    private function is_in_schedule($post_id)
    {
        $now = current_time('timestamp');
        $start = $this->meta($post_id, 'start_at');
        $end = $this->meta($post_id, 'end_at');
        if ($start && strtotime($start) > $now) {
            return false;
        }
        if ($end && strtotime($end) < $now) {
            return false;
        }
        return true;
    }

    private function is_scope_visible($post_id)
    {
        $scope = $this->meta($post_id, 'scope', 'all');
        if ('shortcode' === $scope) {
            return false;
        }
        if ('front' === $scope && ! is_front_page()) {
            return false;
        }
        if ('singular' === $scope && ! is_singular()) {
            return false;
        }
        if ('selected' === $scope) {
            $target_ids = array_values(array_filter(array_map('absint', (array) $this->meta($post_id, 'target_ids', array()))));
            return is_singular() && in_array((int) get_queried_object_id(), $target_ids, true);
        }
        return true;
    }

    private function active_modal_ids()
    {
        $query = new WP_Query(array(
            'post_type' => 'ship_modal',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'menu_order date',
            'order' => 'DESC',
        ));
        $ids = array();
        foreach ($query->posts as $post_id) {
            if ($this->is_in_schedule($post_id)) {
                $ids[] = $post_id;
            }
        }
        return $ids;
    }

    public function enqueue_front_assets()
    {
        $has_sitewide = false;
        foreach ($this->active_modal_ids() as $post_id) {
            if ($this->is_scope_visible($post_id)) {
                $has_sitewide = true;
                break;
            }
        }
        if (! $has_sitewide) {
            return;
        }
        wp_enqueue_style('ship-modal', SHIP_MODAL_URL . 'assets/css/modal.css', array(), SHIP_MODAL_VERSION);
        wp_enqueue_script('ship-modal', SHIP_MODAL_URL . 'assets/js/modal.js', array(), SHIP_MODAL_VERSION, true);
        wp_localize_script('ship-modal', 'ShipModalConfig', array('ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ship_modal_event')));
    }

    private function render_image_content($image_id, $link_url, $alt, $new_tab = false)
    {
        $image_id = absint($image_id);
        if (! $image_id) {
            return '';
        }
        $image = wp_get_attachment_image($image_id, 'full', false, array('class' => 'ship-modal__image', 'alt' => $alt));
        if (! $image) {
            return '';
        }
        $link_url = esc_url($link_url);
        $target = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
        return $link_url ? '<a class="ship-modal__link" data-ship-modal-action="image" href="' . $link_url . '"' . $target . '>' . $image . '</a>' : $image;
    }

    private function render_button_markup($buttons)
    {
        if (! is_array($buttons) || ! $buttons) {
            return '';
        }
        $markup = '<div class="ship-modal__buttons">';
        foreach ($buttons as $button) {
            if (! is_array($button) || empty($button['label'])) {
                continue;
            }
            $action = isset($button['action']) && 'close' === $button['action'] ? 'close' : 'link';
            if ('link' === $action && empty($button['url'])) {
                continue;
            }
            $style = isset($button['style']) && 'secondary' === $button['style'] ? 'secondary' : 'primary';
            $target = ! empty($button['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '';
            $label = wp_kses($button['label'], array('br' => array()));
            $label_attr = esc_attr(wp_strip_all_tags($label));
            if ('close' === $action) {
                $markup .= '<button type="button" class="ship-modal__button ship-modal__button--' . esc_attr($style) . '" data-ship-modal-action="close" data-ship-modal-label="' . $label_attr . '" data-ship-modal-close>' . $label . '</button>';
            } else {
                $markup .= '<a class="ship-modal__button ship-modal__button--' . esc_attr($style) . '" data-ship-modal-action="button" data-ship-modal-label="' . $label_attr . '" href="' . esc_url($button['url']) . '"' . $target . '>' . $label . '</a>';
            }
        }
        return $markup . '</div>';
    }

    private function render_page_content($page, $title, $index)
    {
        $page = is_array($page) ? $page : array();
        $image = $this->render_image_content(isset($page['image_id']) ? $page['image_id'] : 0, isset($page['link_url']) ? $page['link_url'] : '', $title . ' ' . ((int) $index + 1) . 'ページ目', ! empty($page['link_new_tab']));
        $heading = isset($page['heading']) ? $page['heading'] : '';
        $body = isset($page['body']) ? $page['body'] : (isset($page['html']) ? wp_strip_all_tags($page['html']) : '');
        $buttons = isset($page['buttons']) ? $page['buttons'] : array();
        $copy = ($heading ? '<h3>' . esc_html($heading) . '</h3>' : '') . ($body ? '<p>' . wp_kses($body, array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true))) . '</p>' : '') . $this->render_button_markup($buttons);
        return '<div class="ship-modal__page-media">' . $image . '</div><div class="ship-modal__page-html">' . $copy . '</div>';
    }

    private function render_modal($post_id, $shortcode = false)
    {
        if (! $this->is_in_schedule($post_id)) {
            return '';
        }
        $type = $this->meta($post_id, 'content_type', 'html');
        $design = $this->meta($post_id, 'design', 'center');
        $trigger = $this->meta($post_id, 'trigger', 'auto');
        $frequency = $this->meta($post_id, 'frequency', 'session');
        $delay = max(0, (int) $this->meta($post_id, 'delay', 2));
        $scroll_threshold = min(95, max(10, (int) $this->meta($post_id, 'scroll_threshold', 50)));
        $show_close = '1' === $this->meta($post_id, 'show_close', '1');
        $close_overlay = '1' === $this->meta($post_id, 'close_overlay', '1');
        $title = get_the_title($post_id);
        $image_position = $this->meta($post_id, 'image_position', 'top');
        $heading = $this->meta($post_id, 'heading');
        $body = $this->meta($post_id, 'body');
        $buttons = $this->meta($post_id, 'buttons', array());
        $border_radius = min(48, max(0, (int) $this->meta($post_id, 'border_radius', 0)));
        $padding = min(64, max(0, (int) $this->meta($post_id, 'padding', 20)));
        $max_width = min(1200, max(280, (int) $this->meta($post_id, 'max_width', 620)));
        $modal_style = '--ship-modal-radius:' . $border_radius . 'px;--ship-modal-padding:' . $padding . 'px;--ship-modal-max-width:' . $max_width . 'px;';
        $modal_id = 'ship-modal-' . absint($post_id) . '-' . wp_rand(100, 999);
        $content = '';
        $content_class = '';
        if ('image' === $type) {
            $content = $this->render_image_content($this->meta($post_id, 'image_id'), $this->meta($post_id, 'link_url'), $title, '1' === $this->meta($post_id, 'link_new_tab', '0'));
            $content_class = ' ship-modal__content--flush';
        } elseif ('hybrid' === $type) {
            $image = $this->render_image_content($this->meta($post_id, 'image_id'), $this->meta($post_id, 'link_url'), $title, '1' === $this->meta($post_id, 'link_new_tab', '0'));
            $body = $body !== '' ? $body : wp_strip_all_tags($this->meta($post_id, 'html'));
            $copy = ($heading ? '<h2>' . esc_html($heading) . '</h2>' : '') . ($body ? '<p>' . wp_kses($body, array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true))) . '</p>' : '') . $this->render_button_markup($buttons);
            $content = '<div class="ship-modal__hybrid ship-modal__hybrid--' . esc_attr($image_position) . '"><div class="ship-modal__hybrid-media">' . $image . '</div><div class="ship-modal__hybrid-html">' . $copy . '</div></div>';
        } elseif ('text' === $type) {
            $copy = ($heading ? '<h2>' . esc_html($heading) . '</h2>' : '') . ($body ? '<p>' . wp_kses($body, array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true))) . '</p>' : '') . $this->render_button_markup($buttons);
            $content = '<div class="ship-modal__text">' . $copy . '</div>';
        } elseif ('pager' === $type) {
            $pages = $this->meta($post_id, 'pages', array());
            if (is_array($pages)) {
                $pages = array_values($pages);
            } else {
                $pages = array();
            }
            $pages = array_filter($pages, function ($page) {
                return is_array($page) && (! empty($page['image_id']) || ! empty($page['html']));
            });
            $pages = array_values($pages);
            if ($pages) {
                $page_markup = '';
                foreach ($pages as $index => $page) {
                    $page_markup .= '<section class="ship-modal__page' . (0 === $index ? ' is-active' : '') . '" data-ship-modal-page-panel="' . esc_attr($index) . '"' . (0 === $index ? '' : ' hidden') . ' aria-hidden="' . (0 === $index ? 'false' : 'true') . '">' . $this->render_page_content($page, $title, $index) . '</section>';
                }
                $controls = '<nav class="ship-modal__pager" aria-label="モーダルページ切り替え"><button type="button" class="ship-modal__pager-arrow" data-ship-modal-page-prev disabled>前へ</button><div class="ship-modal__pager-dots">';
                foreach ($pages as $index => $page) {
                    $controls .= '<button type="button" class="ship-modal__pager-dot' . (0 === $index ? ' is-active' : '') . '" data-ship-modal-page="' . esc_attr($index) . '" aria-label="' . esc_attr(((int) $index + 1) . 'ページ目') . '"' . (0 === $index ? ' aria-current="true"' : '') . '>' . esc_html((string) ((int) $index + 1)) . '</button>';
                }
                $controls .= '</div><button type="button" class="ship-modal__pager-arrow" data-ship-modal-page-next' . (count($pages) < 2 ? ' disabled' : '') . '>次へ</button></nav>';
                $content = '<div class="ship-modal__pages" data-ship-modal-page-count="' . esc_attr(count($pages)) . '">' . $page_markup . $controls . '</div>';
                $content_class = ' ship-modal__content--pager';
            }
        } else {
            $content = wp_kses_post($this->meta($post_id, 'html'));
        }
        if (! $content) {
            return '';
        }
        ob_start();
        if ('manual' === $trigger) {
            $button_text = $this->meta($post_id, 'trigger_text', 'キャンペーン詳細を見る');
            $trigger_bg_color = sanitize_hex_color($this->meta($post_id, 'trigger_bg_color', '#0f766e')) ?: '#0f766e';
            $trigger_text_color = sanitize_hex_color($this->meta($post_id, 'trigger_text_color', '#ffffff')) ?: '#ffffff';
            $trigger_position = $this->meta($post_id, 'trigger_position', 'right');
            if (! in_array($trigger_position, array('left', 'center', 'right'), true)) {
                $trigger_position = 'right';
            }
            $trigger_class = $shortcode ? 'ship-modal-trigger ship-modal-trigger--inline-' . $trigger_position : 'ship-modal-trigger ship-modal-trigger--floating ship-modal-trigger--floating-' . $trigger_position;
            $trigger_style = '--ship-modal-trigger-bg:' . $trigger_bg_color . ';--ship-modal-trigger-color:' . $trigger_text_color . ';';
            echo '<button type="button" class="' . esc_attr($trigger_class) . '" style="' . esc_attr($trigger_style) . '" data-ship-modal-target="' . esc_attr($modal_id) . '">' . esc_html($button_text) . '</button>';
        }
        ?>
        <div id="<?php echo esc_attr($modal_id); ?>" class="ship-modal ship-modal--<?php echo esc_attr($design); ?>" style="<?php echo esc_attr($modal_style); ?>" data-post-id="<?php echo absint($post_id); ?>" data-modal-title="<?php echo esc_attr($title); ?>" data-content-type="<?php echo esc_attr($type); ?>" data-design="<?php echo esc_attr($design); ?>" data-trigger="<?php echo esc_attr($trigger); ?>" data-frequency="<?php echo esc_attr($frequency); ?>" data-delay="<?php echo esc_attr($delay); ?>" data-scroll-threshold="<?php echo esc_attr($scroll_threshold); ?>" data-auto-open="<?php echo 'auto' === $trigger ? '1' : '0'; ?>" data-close-overlay="<?php echo $close_overlay ? '1' : '0'; ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($modal_id); ?>-title" hidden>
            <div class="ship-modal__backdrop" data-ship-modal-close></div>
            <div class="ship-modal__dialog" role="document">
                <h2 id="<?php echo esc_attr($modal_id); ?>-title" class="screen-reader-text"><?php echo esc_html($title); ?></h2>
                <?php if ($show_close) : ?><button type="button" class="ship-modal__close" aria-label="閉じる" data-ship-modal-close><span aria-hidden="true">×</span></button><?php endif; ?>
                <div class="ship-modal__content<?php echo esc_attr($content_class); ?>"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_sitewide_modals()
    {
        foreach ($this->active_modal_ids() as $post_id) {
            if ($this->is_scope_visible($post_id)) {
                echo $this->render_modal($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }
    }

    public function shortcode($atts)
    {
        $atts = shortcode_atts(array('id' => 0), $atts, 'ship_modal');
        $post_id = absint($atts['id']);
        if (! $post_id || 'ship_modal' !== get_post_type($post_id) || 'publish' !== get_post_status($post_id)) {
            return '';
        }
        wp_enqueue_style('ship-modal', SHIP_MODAL_URL . 'assets/css/modal.css', array(), SHIP_MODAL_VERSION);
        wp_enqueue_script('ship-modal', SHIP_MODAL_URL . 'assets/js/modal.js', array(), SHIP_MODAL_VERSION, true);
        wp_localize_script('ship-modal', 'ShipModalConfig', array('ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ship_modal_event')));
        return $this->render_modal($post_id, true);
    }

    public function record_event()
    {
        check_ajax_referer('ship_modal_event', 'nonce');
        $post_id = isset($_POST['modal_id']) ? absint($_POST['modal_id']) : 0;
        $event = isset($_POST['event']) ? sanitize_key(wp_unslash($_POST['event'])) : '';
        if (! $post_id || 'ship_modal' !== get_post_type($post_id) || ! in_array($event, array('impression', 'click', 'close', 'page_view'), true)) {
            wp_send_json_error(array('message' => 'invalid request'), 400);
        }
        $keys = array(
            'impression' => '_ship_modal_impressions',
            'click' => '_ship_modal_clicks',
            'close' => '_ship_modal_closes',
            'page_view' => '_ship_modal_page_views',
        );
        $key = $keys[$event];
        $count = (int) get_post_meta($post_id, $key, true);
        update_post_meta($post_id, $key, $count + 1);
        wp_send_json_success();
    }
}

register_activation_hook(SHIP_MODAL_FILE, array('Ship_Modal', 'activate'));
register_deactivation_hook(SHIP_MODAL_FILE, array('Ship_Modal', 'deactivate'));
