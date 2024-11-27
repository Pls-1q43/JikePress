<?php
/**
 * Plugin Name: JikePress
 * Plugin URI: https://1q43.blog/post/10650/
 * Description: 从即刻导入历史动态、同步动态到 Buddypress，你的自部署社交网络备份。
 * Version: 1.2.0
 * Author: 评论尸
 * Author URI: https://1q43.blog
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: social-history-importer
 * Domain Path: /languages
 *
 * @package Social_History_Importer
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('SHI_VERSION', '1.2.0');
define('SHI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SHI_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('SHI_GITHUB_REPO', 'github.com/Pls-1q43/JikePress'); // 替换为实际的 Github 仓库
define('SHI_GITHUB_ACCESS_TOKEN', ''); // 如果是私有仓库，需要设置访问令牌

/**
 * 插件主类
 */
class Social_History_Importer {
    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 获取单例实例
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        // 首先加载 Logger 类
        require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
        
        // 然后加载其他类
        require_once plugin_dir_path(__FILE__) . 'includes/class-sitemap.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-media-handler.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-importer.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-rss-sync.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-frontend.php';

        // 初始化组件
        $this->sitemap = SHI_Sitemap::get_instance();
        
        // 添加这些设置
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '256M');
        set_time_limit(0);
        
        // 添加激活钩子
        register_activation_hook(__FILE__, array($this, 'activate'));
        
        // 添加停用钩子
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // 初始化插件
        add_action('plugins_loaded', array($this, 'init'));
        
    }

    /**
     * 插件激活时的操作
     */
    public function activate() {
        // 检查依赖
        if (!$this->check_dependencies()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__('本插件需要安装 Buddypress 插件，这是一个 Wordpress 官方推出的微博/群组插件，你需要首先安装它。', 'social-history-importer'),
                'Plugin dependency check',
                array('back_link' => true)
            );
        }
        
        // 刷新重写规则
        SHI_Sitemap::flush_rewrite_rules();
    }

    /**
     * 插件停用时的操作
     */
    public function deactivate() {
        // 清理临时文件等操作
    }

    /**
     * 检查插件依赖
     */
    private function check_dependencies() {
        // 只检查 BuddyPress
        if (!class_exists('BuddyPress')) {
            return false;
        }

        return true;
    }

    /**
     * 初始化插件
     */
    public function init() {
        // 确保 BuddyPress 已加载
        if (!did_action('bp_init')) {
            add_action('bp_init', array($this, 'late_init'));
            return;
        }
        
        $this->late_init();
    }

    public function late_init() {
        // 加载文本域
        load_plugin_textdomain(
            'social-history-importer',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );

        // 加载必要的类文件
        $this->load_dependencies();

        // 确保 BuddyPress 活动组件已加载
        if (!function_exists('bp_is_active') || !bp_is_active('activity')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . 
                     esc_html__('Social History Importer 需要启用 BuddyPress 活动组件。', 'social-history-importer') . 
                     '</p></div>';
            });
            return;
        }

        // 如果在管理后台，初始化管理界面
        if (is_admin()) {
            $admin = new SHI_Admin();
            $admin->init();
        }

        // 初始化其他组件
        new SHI_Frontend();
        new SHI_RSS_Sync();
    }

    /**
     * 加载依赖的类文件
     */
    private function load_dependencies() {
        // 加载核心类文件
        require_once SHI_PLUGIN_DIR . 'includes/class-logger.php';
        require_once SHI_PLUGIN_DIR . 'includes/class-media-handler.php';
        require_once SHI_PLUGIN_DIR . 'includes/class-importer.php';
        require_once SHI_PLUGIN_DIR . 'includes/class-admin.php';
        require_once SHI_PLUGIN_DIR . 'includes/class-frontend.php';
        require_once SHI_PLUGIN_DIR . 'includes/class-rss-sync.php';
    }

    /**
     * 检查必要的依赖和条件
     */
    private function check_requirements() {
        // 检查 BuddyPress
        if (!function_exists('bp_is_active')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . 
                     esc_html__('Social History Importer 需要 BuddyPress 插件。', 'social-history-importer') . 
                     '</p></div>';
            });
            return false;
        }

        // 检查活动组件
        if (!bp_is_active('activity')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . 
                     esc_html__('Social History Importer 需要启用 BuddyPress 活动组件。', 'social-history-importer') . 
                     '</p></div>';
            });
            return false;
        }

        return true;
    }
}

// 初始化插件
function social_history_importer() {
    return Social_History_Importer::get_instance();
}

// 启动插件
social_history_importer(); 

/**
 * 在插件初始化时添加过滤器和动作
 */
function shi_init() {
    // 让 BuddyPress 活动内容支持 shortcode
    add_filter('bp_get_activity_content_body', function($content) {
        // 检查内容中是否包含 gallery shortcode
        if (strpos($content, '[gallery') !== false) {
            // 先移除所有现有的 gallery shortcode 处理器
            remove_shortcode('gallery');
            
            // 添加我们自己的 gallery shortcode 处理器
            add_shortcode('gallery', function($attr) {
                // 获取原始的 gallery 输出
                $gallery_output = gallery_shortcode($attr);
                
                // 如果在活动流中，添加额外的包装器
                if (!bp_is_single_activity()) {
                    $gallery_output = sprintf(
                        '<div class="shi-gallery-wrapper">%s</div>',
                        $gallery_output
                    );
                }
                
                return $gallery_output;
            });
            
            // 执行 shortcode
            $content = do_shortcode($content);
            
            // 恢复原始的 gallery shortcode 处理器
            remove_shortcode('gallery');
            add_shortcode('gallery', 'gallery_shortcode');
        }
        return $content;
    }, 20);
    
    // 确保 Jetpack Tiled Gallery 功能开启
    add_filter('jetpack_tiled_galleries_enabled', '__return_true');
    
    // 添加样式加载钩子
    add_action('wp_enqueue_scripts', 'shi_enqueue_gallery_styles');
}

/**
 * 加载相册样式
 */
function shi_enqueue_gallery_styles() {
    // 检查是否在 BuddyPress 活动页面
    if (function_exists('bp_is_activity_component') && 
        (bp_is_activity_component() || bp_is_single_activity())) {
        
        // 加载 Jetpack Gallery 样式
        wp_enqueue_style('jetpack-gallery', 
            plugins_url('jetpack/modules/tiled-gallery/tiled-gallery/tiled-gallery.css'),
            array(),
            SHI_VERSION
        );
        
        // 添加自定义样式
        wp_add_inline_style('jetpack-gallery', '
            .activity-content .gallery,
            .shi-gallery-wrapper .gallery {
                margin-bottom: 20px !important;
                margin-top: 10px !important;
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 10px !important;
            }
            .activity-content .gallery img,
            .shi-gallery-wrapper .gallery img {
                border: none !important;
                width: 100% !important;
                height: auto !important;
                object-fit: cover !important;
            }
            .activity-content .gallery-item,
            .shi-gallery-wrapper .gallery-item {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
        ');
    }
}

add_action('init', 'shi_init'); 

// 在插件主文件中添加
function shi_add_cron_interval($schedules) {
    $schedules['shi_fifteen_minutes'] = array(
        'interval' => 1800,
        'display'  => __('每30分钟', 'social-history-importer')
    );
    return $schedules;
}
add_filter('cron_schedules', 'shi_add_cron_interval'); 

register_activation_hook(__FILE__, 'shi_activate_plugin');

function shi_activate_plugin() {
    // 确保加载必要的类
    require_once plugin_dir_path(__FILE__) . 'includes/class-rss-sync.php';
    
    // 如果 RSS 同步已启用，确保设置定时任务
    if (get_option('shi_rss_sync_enabled')) {
        // 清除可能存在的旧定时任务
        wp_clear_scheduled_hook('shi_rss_sync_cron');
        
        // 设置新的定时任务
        wp_schedule_event(time(), 'shi_fifteen_minutes', 'shi_rss_sync_cron');
    }
}