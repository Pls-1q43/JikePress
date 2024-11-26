<?php
/**
 * Sitemap 生成类
 *
 * @package Social_History_Importer
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

class SHI_Sitemap {
    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 每页项目数
     */
    const ITEMS_PER_PAGE = 1000;

    /**
     * 缓存相关常量
     */
    const CACHE_KEY_INDEX = 'shi_sitemap_index_cache';
    const CACHE_KEY_PAGE = 'shi_sitemap_page_cache_';
    const CACHE_EXPIRATION = DAY_IN_SECONDS; // 24小时

    /**
     * 日志实例
     */
    private $logger;

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
        // 初始化 logger
        $this->logger = SHI_Logger::get_instance();
        
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_sitemap_request'));
        
        // 添加活动创建时的缓存刷新钩子
        add_action('bp_activity_add', array($this, 'flush_sitemap_cache'));
        add_action('bp_activity_delete', array($this, 'flush_sitemap_cache'));
        add_action('bp_activity_update', array($this, 'flush_sitemap_cache'));
    }

    /**
     * 添加查询变量
     */
    public function add_query_vars($vars) {
        $vars[] = 'shi_sitemap';
        $vars[] = 'shi_page';
        return $vars;
    }

    /**
     * 处理 sitemap 请求
     */
    public function handle_sitemap_request() {
        $sitemap = get_query_var('shi_sitemap');
        $page = get_query_var('shi_page', 1);
        
        if ($sitemap === 'activity') {
            if ($page === 'index') {
                $this->generate_index_sitemap();
            } else {
                $this->generate_activity_sitemap((int)$page);
            }
            exit;
        }
    }

    /**
     * 生成主 sitemap 索引
     */
    private function generate_index_sitemap() {
        // 尝试获取缓存
        $cached_content = wp_cache_get(self::CACHE_KEY_INDEX);
        if ($cached_content !== false) {
            header('Content-Type: application/xml; charset=UTF-8');
            echo $cached_content;
            return;
        }

        global $wpdb, $bp;
        
        // 获取总活动数
        $total_activities = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM {$bp->activity->table_name} 
            WHERE hide_sitewide = 0 
            AND type != 'activity_comment'"
        ));
        
        // 计算需要的页数
        $total_pages = ceil($total_activities / self::ITEMS_PER_PAGE);
        
        // 如果只需要一页，直接重定向到第一页
        if ($total_pages <= 1) {
            wp_redirect(add_query_arg('shi_sitemap', 'activity', home_url()));
            exit;
        }
        
        // 开始输出缓冲
        ob_start();
        
        // 输出 XML
        echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
        
        // 获取最后更新时间
        $last_modified = $wpdb->get_var($wpdb->prepare(
            "SELECT date_recorded 
            FROM {$bp->activity->table_name} 
            WHERE hide_sitewide = 0 
            AND type != 'activity_comment' 
            ORDER BY date_recorded DESC 
            LIMIT 1"
        ));
        
        // 输出每个分页的 sitemap
        for ($i = 1; $i <= $total_pages; $i++) {
            $sitemap_url = add_query_arg(array(
                'shi_sitemap' => 'activity',
                'shi_page' => $i
            ), home_url());
            
            echo '<sitemap>' . PHP_EOL;
            echo '  <loc>' . esc_url($sitemap_url) . '</loc>' . PHP_EOL;
            echo '  <lastmod>' . mysql2date('Y-m-d\TH:i:s\Z', $last_modified, false) . '</lastmod>' . PHP_EOL;
            echo '</sitemap>' . PHP_EOL;
        }
        
        echo '</sitemapindex>';
        
        // 获取输出缓冲内容
        $content = ob_get_clean();
        
        // 设置缓存
        wp_cache_set(self::CACHE_KEY_INDEX, $content, '', self::CACHE_EXPIRATION);
        
        // 输出内容
        header('Content-Type: application/xml; charset=UTF-8');
        echo $content;
    }

    /**
     * 生成活动 sitemap
     */
    private function generate_activity_sitemap($page = 1) {
        // 尝试获取缓存
        $cache_key = self::CACHE_KEY_PAGE . $page;
        $cached_content = wp_cache_get($cache_key);
        if ($cached_content !== false) {
            header('Content-Type: application/xml; charset=UTF-8');
            echo $cached_content;
            return;
        }

        global $wpdb, $bp;
        
        // 获取总活动数
        $total_activities = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM {$bp->activity->table_name} 
            WHERE hide_sitewide = 0 
            AND type != 'activity_comment'"
        ));
        
        // 如果活动数大于1000且请求的是第一页，重定向到索引页
        if ($total_activities > self::ITEMS_PER_PAGE && $page === 1 && !isset($_GET['shi_page'])) {
            wp_redirect(add_query_arg(array(
                'shi_sitemap' => 'activity',
                'shi_page' => 'index'
            ), home_url()));
            exit;
        }
        
        // 计算偏移量
        $offset = ($page - 1) * self::ITEMS_PER_PAGE;
        
        // 获取活动数据
        $activities = $this->get_activities($offset);
        
        // 开始输出缓冲
        ob_start();
        
        // 输出 XML
        echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
        
        foreach ($activities as $activity) {
            $url = bp_activity_get_permalink($activity->id);
            $modified = $activity->date_recorded;
            
            echo '<url>' . PHP_EOL;
            echo '  <loc>' . esc_url($url) . '</loc>' . PHP_EOL;
            echo '  <lastmod>' . mysql2date('Y-m-d\TH:i:s\Z', $modified, false) . '</lastmod>' . PHP_EOL;
            echo '  <changefreq>monthly</changefreq>' . PHP_EOL;
            echo '  <priority>0.8</priority>' . PHP_EOL;
            echo '</url>' . PHP_EOL;
        }
        
        echo '</urlset>';
        
        // 获取输出缓冲内容
        $content = ob_get_clean();
        
        // 设置缓存
        wp_cache_set($cache_key, $content, '', self::CACHE_EXPIRATION);
        
        // 输出内容
        header('Content-Type: application/xml; charset=UTF-8');
        echo $content;
    }

    /**
     * 获取活动数据
     */
    private function get_activities($offset = 0) {
        global $wpdb, $bp;
        
        $sql = $wpdb->prepare(
            "SELECT id, date_recorded 
            FROM {$bp->activity->table_name} 
            WHERE hide_sitewide = 0 
            AND type != 'activity_comment' 
            ORDER BY date_recorded DESC 
            LIMIT %d OFFSET %d",
            self::ITEMS_PER_PAGE,
            $offset
        );
        
        return $wpdb->get_results($sql);
    }

    /**
     * 刷新 sitemap 缓存
     */
    public function flush_sitemap_cache() {
        $this->logger->info('刷新 Sitemap 缓存');
        
        // 删除索引缓存
        wp_cache_delete(self::CACHE_KEY_INDEX);
        
        // 删除所有页面缓存
        global $wpdb, $bp;
        $total_activities = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM {$bp->activity->table_name} 
            WHERE hide_sitewide = 0 
            AND type != 'activity_comment'"
        ));
        
        $total_pages = ceil($total_activities / self::ITEMS_PER_PAGE);
        
        for ($i = 1; $i <= $total_pages; $i++) {
            wp_cache_delete(self::CACHE_KEY_PAGE . $i);
        }
    }

    /**
     * 获取最后修改时间
     */
    private function get_last_modified() {
        global $wpdb, $bp;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT date_recorded 
            FROM {$bp->activity->table_name} 
            WHERE hide_sitewide = 0 
            AND type != 'activity_comment' 
            ORDER BY date_recorded DESC 
            LIMIT 1"
        ));
    }
}