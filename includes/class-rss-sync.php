<?php
class SHI_RSS_Sync {
    private $logger;
    private $media_handler;
    
    /**
     * 已导入记录的选项名
     */
    const IMPORTED_OPTION_KEY = 'shi_imported_guids';
    
    /**
     * 缓存的已导入GUID列表
     */
    private $imported_guids = null;
    
    public function __construct() {
        $this->logger = SHI_Logger::get_instance();
        $this->media_handler = new SHI_Media_Handler();
        
        // 注册自定义时间间隔
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // 注册定时任务钩子
        add_action('shi_rss_sync_cron', array($this, 'sync_rss_feed'));
        
        // 监听设置变更
        add_action('update_option_shi_rss_sync_enabled', array($this, 'handle_sync_toggle'), 10, 2);
        
        // 在插件激活时检查并设置定时任务
        if (get_option('shi_rss_sync_enabled') && !wp_next_scheduled('shi_rss_sync_cron')) {
            wp_schedule_event(time(), 'shi_fifteen_minutes', 'shi_rss_sync_cron');
        }
        
        // 添加每周清理任务
        if (!wp_next_scheduled('shi_cleanup_imported_records')) {
            wp_schedule_event(time(), 'weekly', 'shi_cleanup_imported_records');
        }
        add_action('shi_cleanup_imported_records', array($this, 'cleanup_imported_records'));
    }
    
    public function handle_sync_toggle($old_value, $new_value) {
        if ($new_value && !wp_next_scheduled('shi_rss_sync_cron')) {
            wp_schedule_event(time(), 'shi_fifteen_minutes', 'shi_rss_sync_cron');
        } elseif (!$new_value) {
            wp_clear_scheduled_hook('shi_rss_sync_cron');
        }
    }
    
    public function sync_rss_feed() {
        // 确保有管理员权限的用户上下文
        if (!defined('DOING_CRON') || !DOING_CRON) {
            if (!current_user_can('manage_options')) {
                return array(
                    'success' => false,
                    'message' => '权限不足'
                );
            }
        } else {
            // 在 Cron 执行时，使用管理员账号
            $admins = get_users(array('role' => 'administrator', 'number' => 1));
            if (!empty($admins)) {
                wp_set_current_user($admins[0]->ID);
            }
        }

        if (!get_option('shi_rss_sync_enabled') && !defined('DOING_AJAX')) {
            return array(
                'success' => false,
                'message' => 'RSS同步未启用'
            );
        }
        
        $feed_url = get_option('shi_rss_feed_url');
        if (empty($feed_url)) {
            return array(
                'success' => false,
                'message' => 'RSS feed URL未设置'
            );
        }
        
        try {
            // 加载必要的文件
            require_once(ABSPATH . 'wp-includes/feed.php');
            require_once(ABSPATH . 'wp-includes/class-simplepie.php');
            
            // 添加调试日志
            $this->logger->info('开始RSS同步', array('feed_url' => $feed_url));
            
            // 创建新的 SimplePie 对象
            $feed = new SimplePie();
            $feed->set_feed_url($feed_url);
            
            // 禁用缓存
            $feed->enable_cache(false);
            
            // 强制获取新内容
            $feed->force_feed(true);
            
            // 设置超时时间
            $feed->set_timeout(30);
            
            // 初始化 feed
            if (!$feed->init()) {
                throw new Exception($feed->error());
            }
            
            // 处理编码
            $feed->handle_content_type();
            
            $imported_count = 0;
            $items = $feed->get_items();
            
            $this->logger->info('获取到RSS条目', array(
                'count' => count($items),
                'first_item_date' => !empty($items) ? $items[0]->get_date('Y-m-d H:i:s') : 'none'
            ));
            
            foreach ($items as $item) {
                if ($this->process_feed_item($item)) {
                    $imported_count++;
                }
            }
            
            // 更新最后同步时间
            update_option('shi_last_rss_sync', current_time('mysql'));
            
            $this->logger->info('RSS同步完成', array(
                'imported_count' => $imported_count,
                'total_items' => count($items)
            ));
            
            return array(
                'success' => true,
                'imported' => $imported_count
            );
            
        } catch (Exception $e) {
            $this->logger->error('RSS同步失败: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    private function process_feed_item($item) {
        try {
            // 检查是否已导入
            $guid = $this->get_item_unique_id($item);
            if ($this->is_item_imported($guid)) {
                return false;
            }

            // 获取原始内容
            $content = $item->get_content();
            
            // 提取内容中的图片 URL
            $image_urls = array();
            preg_match_all('/<img[^>]+src=([\'"])?((http[s]?:\/\/[^"\'>]+))[^>]*>/i', $content, $matches);
            if (!empty($matches[2])) {
                $image_urls = array_unique($matches[2]);
            }
            
            // 处理图片
            $attachment_ids = array();
            foreach ($image_urls as $image_url) {
                try {
                    $attachment_id = $this->media_handler->process_remote_image($image_url);
                    if ($attachment_id) {
                        $attachment_ids[] = $attachment_id;
                    }
                } catch (Exception $e) {
                    $this->logger->error('RSS同步图片处理失败', array(
                        'url' => $image_url,
                        'error' => $e->getMessage()
                    ));
                    continue;
                }
            }

            // 处理内容
            if (!empty($attachment_ids)) {
                // 只移除图片标签，保留其他HTML
                $content = preg_replace('/<img[^>]+>/i', '', $content);
            }
            
            // 将所有类型的换行标签转换为双换行符
            $content = preg_replace('/<br\s*\/?>/i', "\n\n", $content);
            
            // 保留必要的HTML标签
            $content = wp_kses($content, array(
                'a' => array(
                    'href' => array(),
                    'title' => array(),
                    'target' => array(),
                    'rel' => array()
                ),
                'p' => array(),
                'span' => array(
                    'class' => array()
                ),
                'em' => array(),
                'strong' => array()
            ));
            
            // 如果有图片，添加 gallery shortcode
            if (!empty($attachment_ids)) {
                $content = $this->media_handler->process_activity_content($content, $attachment_ids);
            }

            // 创建活动
            $activity_data = array(
                'user_id' => get_current_user_id(),
                'content' => $content,
                'type' => 'jike_rss_import',
                'component' => 'activity',
                'action' => sprintf('%s 从即刻 RSS 同步了一条动态', bp_core_get_userlink(get_current_user_id())),
                'hide_sitewide' => false,
                'recorded_time' => $item->get_date('Y-m-d H:i:s')
            );

            $activity_id = bp_activity_add($activity_data);

            if (!$activity_id) {
                throw new Exception('创建活动失败');
            }

            if (!empty($attachment_ids)) {
                // 保存图片 ID 到活动 meta
                bp_activity_update_meta($activity_id, 'attachment_ids', $attachment_ids);
            }

            // 标记为已导入
            $this->mark_item_imported($guid);

            $this->logger->info('RSS条目导入成功', array(
                'guid' => $guid,
                'activity_id' => $activity_id,
                'image_count' => count($attachment_ids)
            ));

            return true;

        } catch (Exception $e) {
            $this->logger->error('处理RSS条目失败', array(
                'guid' => $guid ?? 'unknown',
                'error' => $e->getMessage()
            ));
            return false;
        }
    }
    
    /**
     * 检查目是否已导入
     */
    private function is_item_imported($guid) {
        if ($this->imported_guids === null) {
            $this->imported_guids = get_option(self::IMPORTED_OPTION_KEY, array());
        }
        
        // 使用 info 替代 debug
        $this->logger->info('检查条目是否已导入', array(
            'guid' => $guid,
            'is_imported' => in_array(md5($guid), $this->imported_guids)
        ));
        
        return in_array(md5($guid), $this->imported_guids);
    }
    
    /**
     * 标记条目为已导入
     */
    private function mark_item_imported($guid) {
        if ($this->imported_guids === null) {
            $this->imported_guids = get_option(self::IMPORTED_OPTION_KEY, array());
        }
        
        $this->imported_guids[] = md5($guid);
        
        // 保持数组唯一性
        $this->imported_guids = array_unique($this->imported_guids);
        
        // 限制数组大小（保留最近的100条记录）
        if (count($this->imported_guids) > 100) {
            $this->imported_guids = array_slice($this->imported_guids, -100);
        }
        
        // 更新选项
        update_option(self::IMPORTED_OPTION_KEY, $this->imported_guids);
        
        // 使用 info 替代 debug
        $this->logger->info('标记条目为已入', array(
            'guid' => $guid,
            'total_imported' => count($this->imported_guids)
        ));
    }
    
    /**
     * 理过期的导入记录
     */
    public function cleanup_imported_records() {
        if ($this->imported_guids === null) {
            $this->imported_guids = get_option(self::IMPORTED_OPTION_KEY, array());
        }
        
        // 只保留最近的100条记录
        if (count($this->imported_guids) > 100) {
            $this->imported_guids = array_slice($this->imported_guids, -100);
            update_option(self::IMPORTED_OPTION_KEY, $this->imported_guids);
        }
    }
    
    /**
     * 获取条目的唯一标识
     */
    private function get_item_unique_id($item) {
        // 优先使用GUID
        $guid = $item->get_id();
        if (!empty($guid)) {
            return $guid;
        }
        
        // 如果没有GUID，使用链接和发布时间的组合
        $link = $item->get_link();
        $date = $item->get_date('Y-m-d H:i:s');
        
        $unique_id = $link . '#' . $date;
        
        // 使用 info 替代 debug
        $this->logger->info('生成条目唯一标识', array(
            'guid' => $guid,
            'link' => $link,
            'date' => $date,
            'unique_id' => $unique_id
        ));
        
        return $unique_id;
    }
    
    /**
     * 添加自定义时间间隔
     */
    public function add_cron_interval($schedules) {
        $schedules['shi_fifteen_minutes'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('每30分钟')
        );
        return $schedules;
    }
} 