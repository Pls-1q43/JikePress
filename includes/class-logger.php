<?php
/**
 * 日志处理类
 *
 * @package Social_History_Importer
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 日志处理类
 */
class SHI_Logger {
    /**
     * 日志级别常量
     */
    const ERROR   = 'error';
    const WARNING = 'warning';
    const INFO    = 'info';
    const SUCCESS = 'success';

    /**
     * 日志文件路径
     */
    private $log_file;

    /**
     * 是否启用日志
     */
    private $enabled = true;

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
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/social-history-importer/logs/import.log';
        
        // 从选项中获取日志开关状态
        $this->enabled = get_option('shi_enable_logging', true);
        
        // 确保日志目录存在
        $this->ensure_log_directory();
    }

    /**
     * 确保日志目录存在
     */
    private function ensure_log_directory() {
        $log_dir = dirname($this->log_file);
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // 添加 .htaccess 文件保护日志
            $htaccess_file = $log_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, 'Deny from all');
            }
        }
    }

    /**
     * 记录日志
     *
     * @param string $message 日志消息
     * @param string $level   日志级别
     * @param array  $context 上下文信息
     */
    public function log($message, $level = self::INFO, $context = array()) {
        // 如果日志被禁用，直接返回
        if (!$this->enabled) {
            return;
        }
        
        $time = current_time('mysql');
        
        $log_entry = sprintf(
            "[%s] %s: %s %s\n",
            $time,
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context) : ''
        );

        error_log($log_entry, 3, $this->log_file);

        // 如果是错误级别，同时写入 WordPress 错误日志
        if ($level === self::ERROR) {
            error_log($message);
        }
    }

    /**
     * 记录错误
     *
     * @param string $message 错误消息
     * @param array  $context 上下文信息
     */
    public function error($message, $context = array()) {
        $this->log($message, self::ERROR, $context);
    }

    /**
     * 记录警告
     *
     * @param string $message 警告消息
     * @param array  $context 上下文信息
     */
    public function warning($message, $context = array()) {
        $this->log($message, self::WARNING, $context);
    }

    /**
     * 记录信息
     *
     * @param string $message 信息消息
     * @param array  $context 上下文信息
     */
    public function info($message, $context = array()) {
        $this->log($message, self::INFO, $context);
    }

    /**
     * 记录成功
     *
     * @param string $message 成功消息
     * @param array  $context 上下文信息
     */
    public function success($message, $context = array()) {
        $this->log($message, self::SUCCESS, $context);
    }

    /**
     * 获取日志内容
     *
     * @param int $lines 要获取的行数，默认为50行
     * @return array 日志行数组
     */
    public function get_logs($lines = 50) {
        if (!file_exists($this->log_file)) {
            return array();
        }

        try {
            // 使用 shell 命令获取最后几行（如果可用）
            if (function_exists('shell_exec') && stripos(PHP_OS, 'WIN') === false) {
                $result = shell_exec("tail -n {$lines} " . escapeshellarg($this->log_file));
                if ($result !== null) {
                    return array_filter(explode("\n", $result));
                }
            }

            // 如果 shell 命令不可用，使用 PHP 逐行读取
            $logs = array();
            $handle = @fopen($this->log_file, 'r');
            if ($handle === false) {
                return array();
            }

            // 使用 fseek 快速定位到文件末尾
            fseek($handle, 0, SEEK_END);
            $pos = ftell($handle);
            $line_count = 0;
            $chunk_size = 4096; // 每次读取的块大小

            // 从文件末尾开始向前读取
            while ($pos > 0 && $line_count < $lines) {
                $read_size = min($chunk_size, $pos);
                $pos -= $read_size;
                fseek($handle, $pos);
                $chunk = fread($handle, $read_size);
                $lines_in_chunk = array_filter(explode("\n", $chunk));
                $line_count += count($lines_in_chunk);
                $logs = array_merge($lines_in_chunk, $logs);
            }

            fclose($handle);
            
            // 只返回需要的行数
            return array_slice($logs, -$lines);
            
        } catch (Exception $e) {
            error_log('SHI Logger - 读取日志失败: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * 清除日志
     */
    public function clear_logs() {
        if (file_exists($this->log_file)) {
            unlink($this->log_file);
        }
        $this->ensure_log_directory();
    }

    /**
     * 获取日志文件大小
     *
     * @return string 格式化的文件大小
     */
    public function get_log_size() {
        try {
            if (!file_exists($this->log_file)) {
                return '0 KB';
            }

            // 使用 filesize() 替代读取整个文件
            $size = @filesize($this->log_file);
            if ($size === false) {
                return '未知';
            }

            $units = array('B', 'KB', 'MB', 'GB', 'TB');
            $power = $size > 0 ? floor(log($size, 1024)) : 0;
            
            return number_format($size / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
        } catch (Exception $e) {
            error_log('SHI Logger - 获取日志大小失败: ' . $e->getMessage());
            return '未知';
        }
    }

    /**
     * 设置日志开关状态
     */
    public function set_enabled($enabled) {
        try {
            $this->enabled = (bool)$enabled;
            update_option('shi_enable_logging', $this->enabled);
            
            // 如果禁用日志，可以选择性地清理日志文件
            if (!$this->enabled && file_exists($this->log_file)) {
                // 清空文件内容而不是删除文件
                file_put_contents($this->log_file, '');
                $this->log('日志记录已禁用', self::INFO);
            }
            
            return true;
        } catch (Exception $e) {
            error_log('SHI Logger - 设置日志状态失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取日志开关状态
     */
    public function is_enabled() {
        return $this->enabled;
    }
} 