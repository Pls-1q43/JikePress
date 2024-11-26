<?php
/**
 * 管理页面模板
 *
 * @package Social_History_Importer
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html__('社交历史导入', 'social-history-importer'); ?></h1>

    <?php settings_errors(); ?>

    <div class="shi-container">
        <!-- Sitemap 信息卡片 -->
        <div class="shi-card" id="shi-sitemap-section">
            <h2><?php echo esc_html__('Sitemap 设置', 'social-history-importer'); ?></h2>
            <div class="shi-card-content">
                <p>
                    <?php echo esc_html__('活动 Sitemap 地址：', 'social-history-importer'); ?>
                    <code class="shi-sitemap-url">
                        <?php echo esc_url(add_query_arg('shi_sitemap', 'activity', home_url())); ?>
                    </code>
                    <button type="button" class="button button-small shi-copy-url" data-clipboard-text="<?php echo esc_url(add_query_arg('shi_sitemap', 'activity', home_url())); ?>">
                        <?php echo esc_html__('复制链接', 'social-history-importer'); ?>
                    </button>
                </p>
                <p class="description">
                    <?php echo esc_html__('此 Sitemap 包含所有公开活动记录，可提交给搜索引擎以改善 SEO。', 'social-history-importer'); ?>
                </p>
            </div>
        </div>

        <!-- 导入表单 -->
        <div class="shi-card" id="shi-import-form">
            <h2><?php echo esc_html__('导入数据', 'social-history-importer'); ?></h2>
            
            <div class="shi-card-content">
            <div class="shi-sitemap-tips">
                    <h4><?php echo esc_html__('使用说明：', 'social-history-importer'); ?></h4>
                    <ul>
                        <li><?php echo sprintf(
                            esc_html__('本插件可以导入来自即刻的历史数据，你需要首先通过即刻上的@%s 导出你的历史动态', 'social-history-importer'),
                            '<a href="https://web.okjike.com/u/6dbe8236-4104-4980-8fb0-1c01f1ea32bd" target="_blank">黄即精神股东2.0</a>'
                        ); ?></li>
                        <li><?php echo esc_html__('你需要将导出的 Excel 文件，另存为 CSV 格式，在此处上传。', 'social-history-importer'); ?></li>
                        <li><?php echo esc_html__('导入支持断点续传，如果进度卡住了，刷新本页面后点击继续导入即可。', 'social-history-importer'); ?></li>
                    </ul>
                </div>
                <p></p>
                <?php
                // 检查是否有未完成的导入任务
                $current_import = get_option('shi_current_import');
                $progress = get_option('shi_import_progress');
                if (!empty($current_import) && 
                    isset($progress['processed']) && 
                    isset($progress['total']) && 
                    $progress['processed'] < $progress['total']) {
                    
                    $percentage = round(($progress['processed'] / $progress['total']) * 100);
                    $last_activity = isset($current_import['last_activity']) 
                        ? human_time_diff(strtotime($current_import['last_activity'])) . '前'
                        : '未知时间';
                    ?>
                    <div class="shi-notice shi-notice-warning">
                        <p>
                            发现未完成的导入任务 (已完成 <?php echo $percentage; ?>%, 最后活动: <?php echo $last_activity; ?>)
                            <button type="button" class="button button-primary shi-resume-import">继续导入</button>
                            <button type="button" class="button shi-cancel-import">取消导入</button>
                        </p>
                    </div>
                    <?php
                }
                ?>
                
                <form method="post" enctype="multipart/form-data" id="shi-upload-form" onsubmit="return false;">
                    <?php wp_nonce_field('shi_ajax_nonce', 'nonce'); ?>
                    
                    <div class="shi-form-row">
                        <label for="csv_file">
                            <?php echo esc_html__('选择CSV文件', 'social-history-importer'); ?>
                        </label>
                        <input type="file" 
                               name="csv_file" 
                               id="csv_file" 
                               accept=".csv" 
                               required>
                        <p class="description">
                            <?php echo esc_html__('请选择从社交平台导出的CSV文件。', 'social-history-importer'); ?>
                        </p>
                    </div>

                    <div class="shi-form-row">
                        <button type="button" class="button button-primary" id="shi-start-import">
                            <?php echo esc_html__('开始导入', 'social-history-importer'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 导入进度 -->
        <div class="shi-card" id="shi-import-progress" style="display: none;">
            <h2><?php echo esc_html__('导入进度', 'social-history-importer'); ?></h2>
            <div class="shi-card-content">
                <div class="shi-progress-container">
                    <div class="shi-progress-header">
                        <span class="shi-progress-title">准备导入...</span>
                        <span class="shi-progress-count">0/0</span>
                    </div>
                    <div class="shi-progress-bar">
                        <div class="shi-progress-inner" style="width: 0%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 日志查看器 -->
        <div class="shi-card" id="shi-log-section" style="display: none;">
            <h2><?php echo esc_html__('导入日志', 'social-history-importer'); ?></h2>
            <div class="shi-card-content">
                <div id="shi-log-content" class="shi-log-content"></div>
            </div>
        </div>

        <!-- 日志设置 -->
        <div class="shi-card" id="shi-settings-section">
            <h2><?php echo esc_html__('全局设置', 'social-history-importer'); ?></h2>
            <div class="shi-card-content">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('shi_options');
                    do_settings_sections('shi_options');
                    submit_button(__('保存设置', 'social-history-importer'));
                    ?>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 导入完成模态框 -->
<div class="shi-modal" id="shi-complete-modal" style="display: none;">
    <div class="shi-modal-content">
        <h3><?php echo esc_html__('导入完成', 'social-history-importer'); ?></h3>
        <p><?php echo esc_html__('所有数据已导入完成！', 'social-history-importer'); ?></p>
        <div class="shi-modal-footer">
            <button class="button button-primary shi-modal-close">
                <?php echo esc_html__('确定', 'social-history-importer'); ?>
            </button>
        </div>
    </div>
</div>

<!-- 错误提示模态框 -->
<div class="shi-modal" id="shi-error-modal" style="display: none;">
    <div class="shi-modal-content">
        <h3><?php echo esc_html__('导入错误', 'social-history-importer'); ?></h3>
        <p id="shi-error-message"></p>
        <div class="shi-modal-footer">
            <button class="button button-primary shi-modal-close">
                <?php echo esc_html__('确定', 'social-history-importer'); ?>
            </button>
        </div>
    </div>
</div> 