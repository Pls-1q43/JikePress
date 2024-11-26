<?php
/**
 * 前端功能类
 *
 * @package Social_History_Importer
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 前端功能类
 */
class SHI_Frontend {
    /**
     * 构造函数
     */
    public function __construct() {
        // 添加上传按钮到动态发布窗
        add_action('bp_activity_post_form_options', array($this, 'add_upload_button'));
        
        // 添加必要的 JS 和 CSS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // 处理 AJAX 上传
        add_action('wp_ajax_shi_upload_image', array($this, 'handle_upload'));
        
        // 添加调试信息
        add_action('wp_footer', array($this, 'debug_info'));

        // 添加对 gallery shortcode 的支持
        add_filter('bp_activity_allowed_tags', array($this, 'allow_gallery_shortcode'), 10, 1);
        add_filter('bp_get_activity_content_body', array($this, 'render_gallery_shortcode'), 10, 1);
    }

    /**
     * 添加调试信息
     */
    public function debug_info() {
        if (bp_is_activity_component() || bp_is_user_activity()) {
            ?>
            <script type="text/javascript">
                console.log('SHI Debug: Frontend class loaded');
                console.log('SHI Debug: Upload button exists:', jQuery('#shi-upload-button').length);
                console.log('SHI Debug: File input exists:', jQuery('#shi-image-upload').length);
                console.log('SHI Debug: Scripts loaded:', typeof shiSettings !== 'undefined');
            </script>
            <?php
        }
    }

    /**
     * 添加上传按钮
     */
    public function add_upload_button() {
        ?>
        <div id="shi-upload-container" class="post-elements-buttons-item">
            <input type="file" name="shi_images[]" id="shi-image-upload" multiple accept="image/*" style="display:none">
            <button type="button" id="shi-select-button" class="button bp-secondary-action">
                <span class="dashicons dashicons-format-image"></span> 选择图片
            </button>
            <button type="button" id="shi-upload-button" class="button bp-secondary-action" style="display:none;">
                <span class="dashicons dashicons-upload"></span> 开始上传
            </button>
            <div id="shi-image-preview"></div>
        </div>
        <?php
        error_log('SHI Debug: Upload button added to form');
    }

    /**
     * 加载必要的脚本和样式
     */
    public function enqueue_scripts() {
        if (bp_is_activity_component() || bp_is_user_activity()) {
            // 加载 Dashicons
            wp_enqueue_style('dashicons');
            
            // 加载自定义样式
            wp_enqueue_style(
                'shi-frontend',
                SHI_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                SHI_VERSION
            );

            // 加载 jQuery
            wp_enqueue_script('jquery');

            // 加载自定义脚本
            wp_enqueue_script(
                'shi-frontend',
                SHI_PLUGIN_URL . 'assets/js/frontend.js',
                array('jquery'),
                SHI_VERSION,
                true
            );

            // 添加调试信息
            wp_localize_script('shi-frontend', 'shiSettings', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('shi-upload-nonce'),
                'maxFiles' => 10,
                'maxSize' => wp_max_upload_size(),
                'allowedTypes' => array('image/jpeg', 'image/png', 'image/gif'),
                'debug' => true
            ));
        }
    }

    /**
     * 处理图片上传
     */
    public function handle_upload() {
        // 验证 nonce
        check_ajax_referer('shi-upload-nonce', 'nonce');

        // 检查是否有文件上传
        if (!isset($_FILES['file'])) {
            wp_send_json_error('没有文件上传');
        }

        // 确保加载必要的文件
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // 处理文件上传
        $attachment_id = media_handle_upload('file', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error($attachment_id->get_error_message());
        }

        // 获取预览图 URL
        $preview_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        
        wp_send_json_success(array(
            'id' => $attachment_id,
            'url' => $preview_url
        ));
    }

    /**
     * 允许 gallery shortcode 在活动内容中使用
     */
    public function allow_gallery_shortcode($allowedtags) {
        if (!isset($allowedtags['p'])) {
            $allowedtags['p'] = array();
        }
        
        $allowedtags['gallery'] = array(
            'type' => true,
            'columns' => true,
            'link' => true,
            'ids' => true,
            'size' => true
        );
        
        return $allowedtags;
    }

    /**
     * 渲染 gallery shortcode
     */
    public function render_gallery_shortcode($content) {
        global $shortcode_tags;
        
        // 保存原始的 shortcode 处理器
        $orig_shortcode_tags = $shortcode_tags;
        
        // 只保留 gallery shortcode
        $shortcode_tags = array('gallery' => $orig_shortcode_tags['gallery']);
        
        // 处理 shortcode
        $content = do_shortcode($content);
        
        // 恢复原始的 shortcode 处理器
        $shortcode_tags = $orig_shortcode_tags;
        
        return $content;
    }
} 