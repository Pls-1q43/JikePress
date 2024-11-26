<?php
/**
 * 管理界面类
 *
 * @package Social_History_Importer
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 管理界面类
 */
class SHI_Admin {
    /**
     * 导入器实例
     */
    private $importer;

    /**
     * 日志实例
     */
    private $logger;

    /**
     * 构造函数
     */
    public function __construct() {
        $this->importer = new SHI_Importer();
        $this->logger = SHI_Logger::get_instance();
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * 初始化管理界面
     */
    public function init() {
        // 添加菜单
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // 注册设置
        add_action('admin_init', array($this, 'register_settings'));
        
        // 注册AJAX处理
        add_action('wp_ajax_shi_start_import', array($this, 'ajax_start_import'));
        add_action('wp_ajax_shi_process_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_shi_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_shi_get_logs', array($this, 'ajax_get_logs'));
        
        // 加载资源
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // 添加取消导入的 AJAX 处理
        add_action('wp_ajax_shi_cancel_import', array($this, 'ajax_cancel_import'));

        // 添加继续导入的 AJAX 处理
        add_action('wp_ajax_shi_resume_import', array($this, 'ajax_resume_import'));

        // 添加手动同步的AJAX处理
        add_action('wp_ajax_shi_manual_sync', array($this, 'ajax_manual_sync'));
    }

    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        add_menu_page(
            __('社交历史导入', 'social-history-importer'),
            __('社交导入', 'social-history-importer'),
            'manage_options',
            'social-history-importer',
            array($this, 'render_admin_page'),
            'dashicons-upload',
            30
        );
        
        add_submenu_page(
            'social-history-importer',
            __('RSS同步设置', 'social-history-importer'),
            __('RSS设置', 'social-history-importer'),
            'manage_options',
            'shi-rss-settings',
            array($this, 'render_rss_settings_page')
        );
    }

    /**
     * 注册设置
     */
    public function register_settings() {
        // 注册设置组
        register_setting(
            'shi_options',           // 选项组
            'shi_enable_logging',    // 选项名称
            array(
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => array($this, 'sanitize_logging_option')
            )
        );

        // 添加设置区域
        add_settings_section(
            'shi_general_settings',
            __('常规设置', 'social-history-importer'),
            null,
            'shi_options'
        );

        // 添加设置字段
        add_settings_field(
            'shi_enable_logging',
            __('日志记录', 'social-history-importer'),
            array($this, 'render_logging_field'),
            'shi_options',
            'shi_general_settings'
        );
    }

    /**
     * 渲染日志开关字段
     */
    public function render_logging_field() {
        try {
            $logging_enabled = get_option('shi_enable_logging', true);
            ?>
            <label>
                <input type="checkbox" 
                       name="shi_enable_logging" 
                       value="1" 
                       <?php checked($logging_enabled, true); ?>>
                <?php echo esc_html__('启用日志记录', 'social-history-importer'); ?>
            </label>
            <p class="description">
                <?php echo esc_html__('启用后将记录导入和同步过程的详细日志。', 'social-history-importer'); ?>
            </p>
            <?php if ($logging_enabled): 
                $logger = SHI_Logger::get_instance();
                $size = $logger->get_log_size();
                if ($size !== '未知'): ?>
                    <p class="description">
                        <?php echo sprintf(
                            esc_html__('当前日志大小：%s', 'social-history-importer'),
                            $size
                        ); ?>
                    </p>
                <?php endif;
            endif;
        } catch (Exception $e) {
            error_log('SHI Admin - 渲染日志字段失败: ' . $e->getMessage());
            echo '<p class="error">' . esc_html__('无法显示日志设置', 'social-history-importer') . '</p>';
        }
    }

    /**
     * 清理日志开关选项
     */
    public function sanitize_logging_option($value) {
        try {
            // 防止重复处理
            static $is_processing = false;
            if ($is_processing) {
                return $value;
            }
            $is_processing = true;

            $value = (bool)$value;
            $logger = SHI_Logger::get_instance();
            
            // 使用独立的错误处理
            if (!$logger->set_enabled($value)) {
                add_settings_error(
                    'shi_options',
                    'shi_logging_error',
                    __('更新日志设置失败，请重试。', 'social-history-importer'),
                    'error'
                );
                $is_processing = false;
                return get_option('shi_enable_logging', true);
            }

            $is_processing = false;
            return $value;
        } catch (Exception $e) {
            error_log('SHI Admin - 保存日志设置失败: ' . $e->getMessage());
            add_settings_error(
                'shi_options',
                'shi_logging_error',
                __('保存日志设置时发生错误，请重试。', 'social-history-importer'),
                'error'
            );
            return get_option('shi_enable_logging', true);
        }
    }

    /**
     * 加载资源文件
     */
    public function enqueue_assets($hook) {
        if ('toplevel_page_social-history-importer' !== $hook) {
            return;
        }

        // 加载CSS
        wp_enqueue_style(
            'shi-admin',
            SHI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SHI_VERSION
        );

        // 加载JavaScript
        wp_enqueue_script(
            'shi-admin',
            SHI_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            SHI_VERSION,
            true
        );

        // 添加本化数据
        wp_localize_script('shi-admin', 'shiSettings', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('shi_ajax_nonce'),
            'strings' => array(
                'confirm_import' => __('确定要开始导入吗？这可能需要一些时间。', 'social-history-importer'),
                'import_complete' => __('导入完成！', 'social-history-importer'),
                'import_error' => __('导入过程中发生错误。', 'social-history-importer')
            )
        ));
    }

    /**
     * 渲染管理页面
     */
    public function render_admin_page() {
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有足够的权限访问此页面。', 'social-history-importer'));
        }

        // 加载模板
        require_once SHI_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * AJAX处理：开始导入
     */
    public function ajax_start_import() {
        try {
            check_ajax_referer('shi_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
            }

            // 检查是否有文件上传
            if (empty($_FILES['csv_file'])) {
                // 检查是否有未完成的导入任务
                $current_import = get_option('shi_current_import');
                if (!empty($current_import)) {
                    $progress = get_option('shi_import_progress');
                    if ($progress['processed'] < $progress['total']) {
                        wp_send_json_success(array(
                            'status' => 'resumed',
                            'total' => $current_import['total'],
                            'processed' => $progress['processed'],
                            'file' => $current_import['file'],
                            'message' => '继续未完成的导入任务'
                        ));
                        return;
                    }
                }
                wp_send_json_error('请选择CSV文件');
                return;
            }

            $file = $_FILES['csv_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('文件上传错误：' . $this->get_upload_error_message($file['error']));
            }

            // 准备目录
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/social-history-importer/temp/';
            
            // 确保目录存在
            if (!file_exists($target_dir)) {
                $mkdir_result = wp_mkdir_p($target_dir);
                if (!$mkdir_result) {
                    throw new Exception('创建目录失败：' . $target_dir);
                }
            }

            // 移动文件
            $target_file = $target_dir . wp_unique_filename($target_dir, $file['name']);
            
            $this->logger->info('准备移动文件', array(
                'source' => $file['tmp_name'],
                'destination' => $target_file,
                'tmp_exists' => file_exists($file['tmp_name']),
                'tmp_readable' => is_readable($file['tmp_name']),
                'target_dir_writable' => is_writable($target_dir)
            ));

            if (!move_uploaded_file($file['tmp_name'], $target_file)) {
                throw new Exception('移动文件失败');
            }

            $this->logger->info('文件移动成功', array(
                'file' => $target_file,
                'size' => filesize($target_file)
            ));

            // 开始导入
            $result = $this->importer->start_import($target_file);
            $this->logger->info('导入初始化成功', array('result' => $result));
            
            wp_send_json_success($result);

        } catch (Exception $e) {
            $this->logger->error('导入失败', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX处理：处理批次
     */
    public function ajax_process_batch() {
        try {
            // 验证nonce
            check_ajax_referer('shi_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception('权限不足');
            }

            $file = isset($_POST['file']) ? sanitize_text_field($_POST['file']) : '';
            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

            if (empty($file)) {
                throw new Exception('未指定文件');
            }

            // 获取当前进度
            $current_progress = $this->importer->get_progress();
            
            // 处理批次
            $result = $this->importer->process_batch($file, $offset);
            
            // 获取更新后的进度
            $updated_progress = $this->importer->get_progress();
            
            // 合并进度信息到结果中
            $response = array_merge($result, array(
                'processed' => $updated_progress['processed'],
                'total' => $updated_progress['total'],
                'success' => $updated_progress['success'],
                'failed' => $updated_progress['failed']
            ));

            $this->logger->info('批次处理响应', array(
                'response' => $response,
                'current_progress' => $current_progress,
                'updated_progress' => $updated_progress
            ));

            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->logger->error('批次处理失败', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX处理：获取进度
     */
    public function ajax_get_progress() {
        check_ajax_referer('shi_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }

        $progress = $this->importer->get_progress();
        wp_send_json_success($progress);
    }

    /**
     * AJAX处理：获取日志
     */
    public function ajax_get_logs() {
        try {
            check_ajax_referer('shi_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限���足');
            }

            $logs = $this->logger->get_logs(100); // 获取最新的100行日志
            
            // 确保日志是数组格式
            if (!is_array($logs)) {
                $logs = array();
            }
            
            // 添加HTML转义和格式化
            $formatted_logs = array_map(function($log) {
                return esc_html($log);
            }, $logs);

            wp_send_json_success($formatted_logs);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * 处理取消导入的AJAX请求
     */
    public function ajax_cancel_import() {
        try {
            check_ajax_referer('shi_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
            }

            // 获取当前导入信息
            $current_import = get_option('shi_current_import');
            
            if (!empty($current_import)) {
                $this->logger->info('取消导入任务', array(
                    'import_info' => $current_import
                ));

                // 清理所有导入相关的数据
                $this->importer->cleanup();
                
                // 删除临时文件
                if (isset($current_import['file']) && file_exists($current_import['file'])) {
                    @unlink($current_import['file']);
                }

                wp_send_json_success(array(
                    'message' => '导入任务已取消',
                    'cleaned' => true
                ));
            } else {
                wp_send_json_success(array(
                    'message' => '没有正在进行的导入任务',
                    'cleaned' => false
                ));
            }

        } catch (Exception $e) {
            $this->logger->error('取消导入失败', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * 处理继续导入的AJAX请求
     */
    public function ajax_resume_import() {
        try {
            check_ajax_referer('shi_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
            }

            // 获取当前导入信息
            $current_import = get_option('shi_current_import');
            $progress = get_option('shi_import_progress');
            
            if (empty($current_import) || empty($progress)) {
                wp_send_json_error('没有找到未完成的导入任务');
                return;
            }

            // 验证文件是否在
            if (!isset($current_import['file']) || !file_exists($current_import['file'])) {
                $this->logger->error('导入文件不存在', array(
                    'file' => $current_import['file'] ?? 'undefined'
                ));
                wp_send_json_error('导入文件不存在，请重新上传');
                return;
            }

            $this->logger->info('继续导入任务', array(
                'import_info' => $current_import,
                'progress' => $progress
            ));

            // 更新最后活动时间
            $current_import['last_activity'] = current_time('mysql');
            update_option('shi_current_import', $current_import);

            // 确保返回正确的文件路径
            wp_send_json_success(array(
                'file' => $current_import['file'],  // 这里应该是完整的文件路径
                'total' => (int)$current_import['total'],
                'processed' => (int)$progress['processed'],
                'success' => (int)$progress['success'],
                'failed' => (int)$progress['failed'],
                'message' => '继续导入任务'
            ));

        } catch (Exception $e) {
            $this->logger->error('继续导入失败', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * 处理手动同步的AJAX请求
     */
    public function ajax_manual_sync() {
        check_ajax_referer('shi_manual_sync', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('权限不足', 'social-history-importer'));
        }
        
        try {
            $rss_sync = new SHI_RSS_Sync();
            $result = $rss_sync->sync_rss_feed();
            
            if ($result['success']) {
                wp_send_json_success(sprintf(
                    __('同步完成。导入 %d 条新动态。', 'social-history-importer'),
                    $result['imported']
                ));
            } else {
                wp_send_json_error($result['message']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    private function check_directory_permissions() {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $plugin_dir = $base_dir . '/social-history-importer';
        $temp_dir = $plugin_dir . '/temp';
        
        $permissions = array(
            'base_dir' => array(
                'path' => $base_dir,
                'exists' => file_exists($base_dir),
                'writable' => is_writable($base_dir),
                'permissions' => substr(sprintf('%o', fileperms($base_dir)), -4),
                'owner' => posix_getpwuid(fileowner($base_dir))['name']
            )
        );
        
        // 检查或创建插件目录
        if (!file_exists($plugin_dir)) {
            $mkdir_result = wp_mkdir_p($plugin_dir);
            $permissions['plugin_dir'] = array(
                'path' => $plugin_dir,
                'created' => $mkdir_result,
                'error' => $mkdir_result ? null : error_get_last()
            );
        } else {
            $permissions['plugin_dir'] = array(
                'path' => $plugin_dir,
                'exists' => true,
                'writable' => is_writable($plugin_dir),
                'permissions' => substr(sprintf('%o', fileperms($plugin_dir)), -4),
                'owner' => posix_getpwuid(fileowner($plugin_dir))['name']
            );
        }
        
        // 检查或创建临时目录
        if (!file_exists($temp_dir)) {
            $mkdir_result = wp_mkdir_p($temp_dir);
            $permissions['temp_dir'] = array(
                'path' => $temp_dir,
                'created' => $mkdir_result,
                'error' => $mkdir_result ? null : error_get_last()
            );
        } else {
            $permissions['temp_dir'] = array(
                'path' => $temp_dir,
                'exists' => true,
                'writable' => is_writable($temp_dir),
                'permissions' => substr(sprintf('%o', fileperms($temp_dir)), -4),
                'owner' => posix_getpwuid(fileowner($temp_dir))['name']
            );
        }
        
        return $permissions;
    }

    private function get_upload_error_message($error_code) {
        $upload_errors = array(
            UPLOAD_ERR_INI_SIZE => '文件大小超过php.ini中upload_max_filesize的限制',
            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单中MAX_FILE_SIZE的限制',
            UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
            UPLOAD_ERR_CANT_WRITE => '文件写入失',
            UPLOAD_ERR_EXTENSION => '文件上传被扩展程序停止'
        );
        return isset($upload_errors[$error_code]) ? $upload_errors[$error_code] : '未知上传错误';
    }

    /**
     * 渲染RSS设置页面
     */
    public function render_rss_settings_page() {
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有足够的权限访问此页面。', 'social-history-importer'));
        }

        // 处理表单提交
        if (isset($_POST['submit'])) {
            check_admin_referer('shi_rss_options');
            
            // 更新RSS feed URL
            $feed_url = sanitize_url($_POST['shi_rss_feed_url']);
            update_option('shi_rss_feed_url', $feed_url);
            
            // 更新同步开关
            $sync_enabled = isset($_POST['shi_rss_sync_enabled']) ? true : false;
            update_option('shi_rss_sync_enabled', $sync_enabled);
            
            // 显示更新消息
            add_settings_error(
                'shi_rss_messages',
                'shi_rss_updated',
                __('设置已保存。', 'social-history-importer'),
                'updated'
            );
        }

        // 获取当前设置
        $feed_url = get_option('shi_rss_feed_url', '');
        $sync_enabled = get_option('shi_rss_sync_enabled', false);
        
        // 获取上次同步时间
        $last_sync = get_option('shi_last_rss_sync');
        $last_sync_text = $last_sync ? sprintf(
            __('上次同步时间：%s', 'social-history-importer'),
            human_time_diff(strtotime($last_sync), current_time('timestamp')) . '前'
        ) : __('尚未同步', 'social-history-importer');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('RSS同步设置', 'social-history-importer'); ?></h1>
            
            <?php settings_errors('shi_rss_messages'); ?>
            
            <div class="shi-sitemap-tips">
                <h4><?php echo esc_html__('使用说明：', 'social-history-importer'); ?></h4>
                <p><?php echo sprintf(
                    esc_html__('本插件支持通过 RSS 自动同步来自第三方社交平台的动态。在这里填入的 RSS 地址，必须是由 %s 生成的 RSS。通过该功能将即刻动态同步到本地经过测试，理论上 Twitter 亦可正常运行。', 'social-history-importer'),
                    '<a href="https://docs.rsshub.app/zh/" target="_blank">RSSHub</a>'
                ); ?></p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('shi_rss_options'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="shi_rss_feed_url"><?php echo esc_html__('RSS Feed URL', 'social-history-importer'); ?></label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="shi_rss_feed_url" 
                                   name="shi_rss_feed_url" 
                                   value="<?php echo esc_attr($feed_url); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php echo esc_html__('输入即刻用户的RSS feed地址', 'social-history-importer'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('自动同步', 'social-history-importer'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="shi_rss_sync_enabled" 
                                       value="1" 
                                       <?php checked($sync_enabled); ?>>
                                <?php echo esc_html__('每30分钟自动同步一次', 'social-history-importer'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html($last_sync_text); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
                
                <?php if ($feed_url): ?>
                <div class="shi-card">
                    <h2><?php echo esc_html__('手动同步', 'social-history-importer'); ?></h2>
                    <p>
                        <button type="button" 
                                class="button" 
                                id="shi-manual-sync">
                            <?php echo esc_html__('立即同步', 'social-history-importer'); ?>
                        </button>
                        <span class="spinner"></span>
                    </p>
                    <div id="shi-sync-status"></div>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#shi-manual-sync').on('click', function() {
                const $button = $(this);
                const $spinner = $button.next('.spinner');
                const $status = $('#shi-sync-status');
                
                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'shi_manual_sync',
                        nonce: '<?php echo wp_create_nonce('shi_manual_sync'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                        } else {
                            $status.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $status.html('<div class="notice notice-error"><p><?php echo esc_js(__('同步请求失败，请重试。', 'social-history-importer')); ?></p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });
        });
        </script>
        <?php
    }
} 