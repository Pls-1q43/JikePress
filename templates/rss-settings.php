<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="shi-sitemap-tips">
        <h4><?php echo esc_html__('使用说明：', 'social-history-importer'); ?></h4>
        <p><?php echo sprintf(
            esc_html__('本插件支持通过 RSS 自动同步来自第三方社交平台的动态。在这里填入的 RSS 地址，必须是由 %s 生成的 RSS。通过该功能将即刻动态同步到本地经过测试，理论上 Twitter 亦可正常运行。', 'social-history-importer'),
            '<a href="https://docs.rsshub.app/zh/" target="_blank">RSSHub</a>'
        ); ?></p>
    </div>
    
    <form method="post" action="options.php">
        <?php
        settings_fields('shi_rss_options');
        do_settings_sections('shi_rss_options');
        ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="shi_rss_feed_url">RSS Feed URL</label>
                </th>
                <td>
                    <input type="url" 
                           id="shi_rss_feed_url" 
                           name="shi_rss_feed_url" 
                           value="<?php echo esc_attr(get_option('shi_rss_feed_url')); ?>"
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">启用自动同步</th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="shi_rss_sync_enabled" 
                               value="1" 
                               <?php checked(get_option('shi_rss_sync_enabled'), true); ?>>
                        每30分钟自动同步一次
                    </label>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>
</div> 