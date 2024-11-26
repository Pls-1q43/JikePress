<?php
/**
 * 媒体处理类
 *
 * @package Social_History_Importer
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 媒体处理类
 */
class SHI_Media_Handler {
    /**
     * 日志实例
     */
    private $logger;

    /**
     * 允许的图片类型
     */
    private $allowed_types = array(
        'image/jpeg',
        'image/png',
        'image/gif'
    );

    /**
     * 构造函数
     */
    public function __construct() {
        $this->logger = SHI_Logger::get_instance();
    }

    /**
     * 清理图片URL
     * 
     * @throws Exception 当URL不符合要求时抛出异常
     */
    protected function clean_image_url($url) {
        // 移除查询参数
        $base_url = strtok($url, '?');
        
        // 确保URL以常见图片扩展名结尾
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'];
        $path_info = pathinfo($base_url);
        
        // 检查是否有扩展名
        if (!isset($path_info['extension'])) {
            throw new Exception('图片URL缺少有效的扩展名');
        }
        
        $extension = strtolower($path_info['extension']);
        if (!in_array($extension, $valid_extensions)) {
            throw new Exception('不支持的图片格式：' . $extension);
        }
        
        $this->logger->info('清理后的图片URL', [
            'original' => $url,
            'cleaned' => $base_url
        ]);
        
        return $base_url;
    }

    /**
     * 处理远程图片
     */
    public function process_remote_image($url) {
        try {
            $this->logger->info('开始处理远程图片', array('url' => $url));
            
            // 确保加载必要的文件
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            // 清理URL
            try {
                $cleaned_url = $this->clean_image_url($url);
            } catch (Exception $e) {
                $this->logger->warning('跳过无效图片', array(
                    'url' => $url,
                    'reason' => $e->getMessage()
                ));
                return false;
            }
            
            // 下载图片到临时文件
            $temp_file = download_url($cleaned_url);
            if (is_wp_error($temp_file)) {
                throw new Exception('下载图片失败：' . $temp_file->get_error_message());
            }

            // 准备文件数组
            $file_array = array(
                'name' => basename($cleaned_url),
                'tmp_name' => $temp_file,
                'type' => mime_content_type($temp_file)
            );

            // 将文件添加到媒体库
            $attachment_id = media_handle_sideload($file_array, 0);

            // 清理临时文件
            @unlink($temp_file);

            if (is_wp_error($attachment_id)) {
                throw new Exception('添加到媒体库失败：' . $attachment_id->get_error_message());
            }

            $this->logger->info('图片处理成功', array(
                'attachment_id' => $attachment_id,
                'url' => wp_get_attachment_url($attachment_id)
            ));

            return $attachment_id;

        } catch (Exception $e) {
            if (!empty($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            $this->logger->error('处理图片失败', array('error' => $e->getMessage()));
            throw $e;
        }
    }

    /**
     * 清理临时文件
     */
    public function cleanup() {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/social-history-importer/temp';
        
        if (file_exists($temp_dir)) {
            $files = glob($temp_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * 生成图片画廊短代码
     */
    protected function generate_gallery_shortcode($attachment_ids) {
        if (empty($attachment_ids)) {
            return '';
        }
        
        // 使用标准 WordPress Gallery shortcode
        return '[gallery type="rectangular" columns="3" link="file" ids="' . 
            implode(',', $attachment_ids) . '" size="large"]';
    }

    /**
     * 处理活动内容
     */
    public function process_activity_content($content, $attachment_ids) {
        if (empty($attachment_ids)) {
            return $content;
        }

        try {
            // 生成并添加画廊短代码
            $gallery_shortcode = $this->generate_gallery_shortcode($attachment_ids);
            
            // 确保内容和画廊之间有足够的空格
            $content = trim($content) . "\n\n" . $gallery_shortcode;
            
            $this->logger->info('添加 Gallery 到内容', array(
                'shortcode' => $gallery_shortcode,
                'attachment_ids' => $attachment_ids,
                'content_length' => strlen($content)
            ));

            return $content;
            
        } catch (Exception $e) {
            $this->logger->error('处理活动内容失败', array(
                'error' => $e->getMessage(),
                'attachment_ids' => $attachment_ids
            ));
            throw $e;
        }
    }

    /**
     * 创建活动
     */
    protected function create_activity($activity_data, $attachment_ids = array()) {
        try {
            // 处理内容，添加图片画廊
            if (!empty($attachment_ids)) {
                $activity_data['content'] = $this->process_activity_content(
                    $activity_data['content'], 
                    $attachment_ids
                );
            }

            $this->logger->info('准备创建活动', array('activity_data' => $activity_data));

            // 创建活动
            $activity_id = bp_activity_add($activity_data);

            if (!$activity_id) {
                throw new Exception('创建活动失败');
            }

            // 记录媒体关联
            if (!empty($attachment_ids)) {
                bp_activity_update_meta($activity_id, 'attachment_ids', $attachment_ids);
                
                $this->logger->info('媒体已附加到活动', array(
                    'activity_id' => $activity_id,
                    'attachment_ids' => $attachment_ids
                ));
            }

            return array(
                'success' => true,
                'activity_id' => $activity_id,
                'media_count' => count($attachment_ids)
            );

        } catch (Exception $e) {
            $this->logger->error('创建活动失败', array('error' => $e->getMessage()));
            throw $e;
        }
    }

    /**
     * 处理远程图片
     */
    public function handle_remote_image($image_url) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // 下载远程图片
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            return false;
        }
        
        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );
        
        // 将文件移动到媒体库
        $attachment_id = media_handle_sideload($file_array, 0);
        
        // 清理临时文件
        @unlink($tmp);
        
        if (is_wp_error($attachment_id)) {
            return false;
        }
        
        return $attachment_id;
    }
} 