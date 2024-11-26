<?php
/**
 * 导入核心类
 *
 * @package Social_History_Importer
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

// 防止类重复声明
if (!class_exists('SHI_Importer')) {

    /**
     * 导入核心类
     */
    class SHI_Importer {
        /**
         * 日志实例
         */
        private $logger;

        /**
         * 媒体处理实例
         */
        private $media_handler;

        /**
         * 批量处理大小
         */
        private $batch_size = 5;

        /**
         * 当前导入进度
         */
        private $progress = array(
            'total' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0
        );

        /**
         * 构造函数
         */
        public function __construct() {
            $this->logger = SHI_Logger::get_instance();
            $this->media_handler = new SHI_Media_Handler();
        }

        /**
         * 开始导入过程
         *
         * @param string $file CSV文件路径
         * @return array 导入结果
         */
        public function start_import($file) {
            try {
                $this->logger->info('开始导入过程', array(
                    'file' => $file
                ));

                // 将文件移动到永久存储位置
                $upload_dir = wp_upload_dir();
                $import_dir = $upload_dir['basedir'] . '/social-history-importer/imports';
                
                // 确保目录存在
                wp_mkdir_p($import_dir);
                
                // 生成唯一的文件名
                $file_info = pathinfo($file);
                $permanent_file = $import_dir . '/' . uniqid('import_') . '.' . $file_info['extension'];
                
                // 复制文件到永久存储位置
                if (!copy($file, $permanent_file)) {
                    throw new Exception('无法复制导入文件到永久存储位置');
                }

                // 检查是否存在未完成的导入
                $previous_import = get_option('shi_current_import', array());
                
                if (!empty($previous_import) && isset($previous_import['file'])) {
                    // 删除旧的导入文件
                    @unlink($previous_import['file']);
                }

                // 计算总行数
                $handle = fopen($permanent_file, 'r');
                if ($handle === false) {
                    throw new Exception('无法打开文件');
                }

                // 跳过标题行
                fgetcsv($handle);
                
                $total_rows = 0;
                while (fgetcsv($handle) !== false) {
                    $total_rows++;
                }
                fclose($handle);

                // 保存当前导入任务信息
                $import_info = array(
                    'file' => $permanent_file,
                    'original_file' => basename($file),
                    'file_hash' => md5_file($permanent_file),
                    'total' => $total_rows,
                    'started_at' => current_time('mysql'),
                    'last_activity' => current_time('mysql')
                );
                update_option('shi_current_import', $import_info);

                // 初始化进度
                $this->progress = array(
                    'total' => $total_rows,
                    'processed' => 0,
                    'success' => 0,
                    'failed' => 0
                );
                update_option('shi_import_progress', $this->progress);

                // 清除已导入记录列表
                delete_option('shi_imported_records');

                // 删除临时上传文件
                @unlink($file);

                return array(
                    'status' => 'started',
                    'total' => $total_rows,
                    'file' => $permanent_file,
                    'message' => '开始新的导入任务'
                );

            } catch (Exception $e) {
                $this->logger->error('导入启动失败', array(
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ));
                throw $e;
            }
        }

        /**
         * 更新导入活动时间
         */
        private function update_last_activity() {
            $import_info = get_option('shi_current_import', array());
            if (!empty($import_info)) {
                $import_info['last_activity'] = current_time('mysql');
                update_option('shi_current_import', $import_info);
            }
        }

        /**
         * 处理一个批次的导入
         *
         * @param string $file CSV文件路径
         * @param int $offset 起始位置
         * @return array 处理结果
         */
        public function process_batch($file, $offset) {
            try {
                $this->logger->info('处理批次', array(
                    'file' => $file,
                    'offset' => $offset,
                    'batch_size' => $this->batch_size
                ));

                // 获取当前进度
                $this->progress = get_option('shi_import_progress', array(
                    'total' => 0,
                    'processed' => 0,
                    'success' => 0,
                    'failed' => 0
                ));

                if (!file_exists($file)) {
                    throw new Exception('文件不存在：' . $file);
                }

                $handle = fopen($file, 'r');
                if ($handle === false) {
                    throw new Exception('无法打开文件：' . $file);
                }

                // 跳过标题行
                $header = fgetcsv($handle);
                
                // 获取已导入的记录ID列表
                $imported_records = get_option('shi_imported_records', array());

                // 如果是第一次处理（offset = 0），不需要移动文件指针
                // 如果不是第一次，则移动到正确的位置
                if ($offset > 0) {
                    $current_line = 0; // 从0开始计数，因为已经读取了标题行
                    while ($current_line < $offset && fgetcsv($handle) !== false) {
                        $current_line++;
                    }
                }

                // 处理这一批次的数据
                $processed = 0;
                $batch_success = 0;
                $batch_failed = 0;
                $start_time = microtime(true);
                $has_more = false;

                $this->logger->info('开始处理批次数据', array(
                    'starting_offset' => $offset,
                    'batch_size' => $this->batch_size
                ));

                while ($processed < $this->batch_size && ($data = fgetcsv($handle)) !== false) {
                    // 记录当前处理的行
                    $current_position = $offset + $processed;
                    $this->logger->info('处理记录', array(
                        'position' => $current_position,
                        'data' => $data
                    ));

                    // 检查执行时间
                    if ((microtime(true) - $start_time) > 20) {
                        $has_more = true;
                        break;
                    }

                    // 生成记录唯一标识
                    $record_id = $this->generate_record_id($data);

                    // 检查是否已经导入过
                    if (in_array($record_id, $imported_records)) {
                        $this->logger->info('跳过已导入的记录', array(
                            'record_id' => $record_id,
                            'position' => $current_position
                        ));
                        continue;
                    }

                    $result = $this->process_row($data);
                    
                    if ($result['success']) {
                        $batch_success++;
                        $this->progress['success']++;
                        // 记录已导入的记录ID
                        $imported_records[] = $record_id;
                    } else {
                        $batch_failed++;
                        $this->progress['failed']++;
                    }
                    
                    $processed++;
                    $this->progress['processed']++;
                    
                    // 每处理一条记录就释放内存
                    gc_collect_cycles();
                }

                // 检查是否还有更多数据
                $has_more = (fgetcsv($handle) !== false);

                // 保存进度和已导入记录
                update_option('shi_import_progress', $this->progress);
                update_option('shi_imported_records', $imported_records);

                // 更新最后活动时间
                $this->update_last_activity();

                fclose($handle);

                $next_offset = $offset + $processed;
                
                $response = array(
                    'processed' => (int)$this->progress['processed'],
                    'total' => (int)$this->progress['total'],
                    'success' => (int)$this->progress['success'],
                    'failed' => (int)$this->progress['failed'],
                    'next_offset' => (int)$next_offset,
                    'is_completed' => !$has_more,
                    'memory_usage' => memory_get_usage(true),
                    'execution_time' => microtime(true) - $start_time
                );

                $this->logger->info('批次处理完成', array_merge($response, array(
                    'current_offset' => $offset,
                    'processed_in_batch' => $processed,
                    'next_offset' => $next_offset
                )));

                return $response;

            } catch (Exception $e) {
                $this->logger->error('批次处理失败', array(
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ));
                throw $e;
            } finally {
                if (isset($handle) && is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        /**
         * 生成记录唯一标识
         */
        private function generate_record_id($data) {
            // 根据你的CSV数据结构调整这个方法
            // 例如，使用时间戳和内容的组合来生成唯一标识
            $timestamp = isset($data[0]) ? $data[0] : '';
            $content = isset($data[1]) ? $data[1] : '';
            return md5($timestamp . $content);
        }

        /**
         * 处理单行数据
         *
         * @param array $row CSV行数据
         */
        private function process_row($data) {
            try {
                // 解析数据
                $content = isset($data[1]) ? $data[1] : '';
                $images_string = isset($data[2]) ? $data[2] : '';
                $created_time = isset($data[6]) ? $data[6] : '';
                $original_url = isset($data[11]) ? $data[11] : '';

                // 清理和解析 URL
                $images = array();
                if (!empty($images_string)) {
                    // 移除方括号
                    $images_string = trim($images_string, '[]');
                    // 分割 URL
                    $image_urls = explode(',', $images_string);
                    foreach ($image_urls as $url) {
                        // 清理 URL
                        $url = trim($url);
                        if (!empty($url)) {
                            $images[] = $url;
                        }
                    }
                }

                $this->logger->info('处理图片', array(
                    'original_string' => $images_string,
                    'parsed_urls' => $images
                ));

                // 处理图片
                $attachment_ids = array();
                foreach ($images as $image_url) {
                    try {
                        $attachment_id = $this->media_handler->process_remote_image($image_url);
                        if ($attachment_id) {
                            $attachment_ids[] = $attachment_id;
                        }
                    } catch (Exception $e) {
                        $this->logger->error('图片处理失败', array(
                            'url' => $image_url,
                            'error' => $e->getMessage()
                        ));
                        continue;
                    }
                }

                // 创建活动前处理内容
                if (!empty($attachment_ids)) {
                    // 构建标准 WordPress Gallery 短代码
                    $gallery_shortcode = '[gallery type="rectangular" columns="3" link="file" ids="' . 
                        implode(',', $attachment_ids) . '" size="large"]';
                    
                    // 将画廊添加到内容末尾（而不是开头）
                    $content = $content . "\n\n" . $gallery_shortcode;
                    
                    $this->logger->info('添加 Gallery 到内容', array(
                        'shortcode' => $gallery_shortcode,
                        'attachment_ids' => $attachment_ids
                    ));
                }

                // 创建活动
                $activity_data = array(
                    'user_id' => get_current_user_id(),
                    'content' => $content,
                    'type' => 'jike_import',
                    'component' => 'activity',
                    'action' => sprintf('%s 从即刻导入了一条动态', bp_core_get_userlink(get_current_user_id())),
                    'hide_sitewide' => false
                );

                // 如果有时间戳，添加到数据中
                if (!empty($created_time)) {
                    try {
                        // 设置默认时区为 Asia/Shanghai
                        $local_timezone = new DateTimeZone('Asia/Shanghai');
                        $utc_timezone = new DateTimeZone('UTC');
                        
                        // 解析原始时间（假定为北京时间）
                        $local_date = new DateTime($created_time, $local_timezone);
                        
                        // 转换为 UTC 时间
                        $local_date->setTimezone($utc_timezone);
                        
                        // 设置活动数据
                        $activity_data['recorded_time'] = $local_date->format('Y-m-d H:i:s');
                        
                        // 记录详细日志
                        $this->logger->info('设置活动时间', array(
                            'original_time' => $created_time,
                            'timezone' => $local_timezone->getName(),
                            'utc_time' => $local_date->format('Y-m-d H:i:s')
                        ));
                    } catch (Exception $e) {
                        $this->logger->error('时间处理失败', array(
                            'error' => $e->getMessage(),
                            'created_time' => $created_time
                        ));
                    }
                }

                $this->logger->info('准备创建活动', array(
                    'activity_data' => $activity_data
                ));

                // 创建活动
                $activity_id = bp_activity_add($activity_data);

                if (!$activity_id) {
                    throw new Exception('创建活动失败：' . var_export($activity_data, true));
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
                $this->logger->error('处理行失败', array(
                    'error' => $e->getMessage(),
                    'data' => $data
                ));
                
                return array(
                    'success' => false,
                    'error' => $e->getMessage()
                );
            }
        }

        /**
         * 初始化进度
         *
         * @param string $file CSV文件路径
         */
        private function init_progress($file) {
            $handle = fopen($file, 'r');
            $total = -1; // 减去标题行
            while (fgetcsv($handle) !== false) {
                $total++;
            }
            fclose($handle);

            $this->progress = array(
                'total' => $total,
                'processed' => 0,
                'success' => 0,
                'failed' => 0
            );

            // 保存进度到选项
            update_option('shi_import_progress', $this->progress);
        }

        /**
         * 获取当前进度
         *
         * @return array 进度信息
         */
        public function get_progress() {
            // 从数据库获取最新进度
            $saved_progress = get_option('shi_import_progress', array());
            
            // 确保所有必要的键都存在
            $this->progress = wp_parse_args($saved_progress, array(
                'total' => 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0
            ));
            
            return $this->progress;
        }

        /**
         * 清理导入数据
         */
        public function cleanup() {
            $this->logger->info('清理导入数据');
            
            // 获取当前导入信息
            $current_import = get_option('shi_current_import');
            
            // 删除导入文件
            if (!empty($current_import) && isset($current_import['file']) && file_exists($current_import['file'])) {
                @unlink($current_import['file']);
            }
            
            // 删除所有导入相关的选项
            delete_option('shi_import_progress');
            delete_option('shi_current_import');
            delete_option('shi_imported_records');
            
            // 记录清理完成
            $this->logger->info('导入数据清理完成');
        }

        /**
         * 检查内容是否已导入
         */
        private function is_post_imported($post_id) {
            $imported_posts = get_option('shi_imported_posts', array());
            return in_array($post_id, $imported_posts);
        }

        /**
         * 标记内容为已导入
         */
        private function mark_post_as_imported($post_id) {
            $imported_posts = get_option('shi_imported_posts', array());
            $imported_posts[] = $post_id;
            update_option('shi_imported_posts', $imported_posts);
        }

        /**
         * 获取内存限制（以字节为单位）
         */
        private function get_memory_limit() {
            $memory_limit = ini_get('memory_limit');
            if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
                if ($matches[2] == 'M') {
                    return $matches[1] * 1024 * 1024;
                } else if ($matches[2] == 'G') {
                    return $matches[1] * 1024 * 1024 * 1024;
                }
            }
            return 128 * 1024 * 1024; // 默认 128M
        }
    }
} 