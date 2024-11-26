jQuery(document).ready(function($) {
    let isImporting = false;
    let logRefreshInterval = null;

    // 更新进度显示函数
    function updateProgress(processed, total) {
        const percentage = Math.round((processed / total) * 100);
        
        // 更新进度条
        $('.shi-progress-inner').css('width', percentage + '%');
        
        // 更新进度文本
        $('.shi-progress-count').text(processed + ' / ' + total);
        $('.shi-progress-title').text('正在导入... ' + percentage + '%');
        
        console.log('Progress updated:', {
            processed: processed,
            total: total,
            percentage: percentage
        });
    }

    // 刷新日志函数
    function refreshLogs() {
        $.ajax({
            url: shiSettings.ajax_url,
            type: 'POST',
            data: {
                action: 'shi_get_logs',
                nonce: shiSettings.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    const logsContainer = $('#shi-log-content');
                    if (logsContainer.length) {
                        logsContainer.html(response.data.join('<br>'));
                        // 滚动到底部
                        logsContainer.scrollTop(logsContainer[0].scrollHeight);
                    }
                }
            }
        });
    }

    // 开始定时刷新日志
    function startLogRefresh() {
        if (logRefreshInterval) {
            clearInterval(logRefreshInterval);
        }
        logRefreshInterval = setInterval(refreshLogs, 2000); // 每2秒刷新一次
        refreshLogs(); // 立即刷新一次
    }

    // 停止定时刷新日志
    function stopLogRefresh() {
        if (logRefreshInterval) {
            clearInterval(logRefreshInterval);
            logRefreshInterval = null;
        }
    }

    // 处理导入按钮点击
    $('#shi-start-import').on('click', function(e) {
        e.preventDefault();
        
        // 显示进度区域
        $('#shi-import-progress').show();
        
        // 创建 FormData 对象
        const formData = new FormData($('#shi-upload-form')[0]);
        formData.append('action', 'shi_start_import');
        
        // 发送 AJAX 请求
        $.ajax({
            url: shiSettings.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // 如果是继续未完成的导入
                    if (data.status === 'resumed') {
                        updateProgress(data.processed, data.total);
                        showSuccess(data.message);
                    } else {
                        updateProgress(0, data.total);
                    }
                    
                    processBatch(data.file, data.processed || 0);
                } else {
                    showError(response.data || '导入启动失败');
                }
            },
            error: function(xhr, status, error) {
                showError('导入启动失败: ' + error);
            }
        });
    });

    // 处理单个批次
    function processBatch(file, offset) {
        console.log('Processing batch:', {file: file, offset: offset});
        
        if (!file) {
            console.error('No file provided for processing');
            showError('处理文件错误：未提供文件');
            stopLogRefresh();
            return;
        }

        $.ajax({
            url: shiSettings.ajax_url,
            type: 'POST',
            data: {
                action: 'shi_process_batch',
                nonce: shiSettings.nonce,
                file: file,
                offset: parseInt(offset)
            },
            success: function(response) {
                if (response.success && response.data) {
                    const data = response.data;
                    console.log('Batch processing response:', data);
                    
                    // 更新进度
                    updateProgress(data.processed, data.total);
                    
                    // 检查是否需要继续处理
                    if (!data.is_completed) {
                        console.log('Continuing to next batch:', {
                            current_offset: offset,
                            next_offset: data.next_offset,
                            processed: data.processed,
                            total: data.total
                        });
                        
                        // 确保使用 data.next_offset 继续处理
                        setTimeout(function() {
                            processBatch(file, data.next_offset);
                        }, 500);
                    } else {
                        console.log('Import completed');
                        isImporting = false;
                        stopLogRefresh();
                        showSuccess('所有数据已导入完成！');
                        $('#shi-complete-modal').show();
                    }
                } else {
                    console.error('Batch processing failed:', response);
                    isImporting = false;
                    stopLogRefresh();
                    showError(response.data || '批次处理失败');
                }
            },
            error: function(xhr, status, error) {
                console.error('Batch error:', {xhr: xhr, status: status, error: error});
                
                if (status === 'timeout') {
                    console.log('Timeout occurred, retrying current batch');
                    setTimeout(function() {
                        processBatch(file, offset);
                    }, 3000);
                } else {
                    isImporting = false;
                    stopLogRefresh();
                    showError('批次处理失败: ' + error);
                }
            }
        });
    }

    // 手动刷新日志按钮点击事件
    $('.shi-refresh-logs').on('click', function(e) {
        e.preventDefault();
        refreshLogs();
    });

    // 显示错误信息
    function showError(message) {
        $('.shi-message')
            .removeClass('success')
            .addClass('error')
            .text(message)
            .show();
    }

    // 显示成功信息
    function showSuccess(message) {
        $('.shi-message')
            .removeClass('error')
            .addClass('success')
            .text(message)
            .show();
    }

    // 处理取消导入按钮点击
    $('.shi-cancel-import').on('click', function(e) {
        e.preventDefault();
        
        if (confirm('确定要取消导入任务吗？这将清除所有导入进度。')) {
            $.ajax({
                url: shiSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'shi_cancel_import',
                    nonce: shiSettings.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // 重置界面
                        $('#shi-import-progress').hide();
                        $('.shi-notice-warning').fadeOut();
                        $('#shi-upload-form')[0].reset();
                        
                        // 重置进度条和统计
                        updateProgress(0, 0);
                        
                        showSuccess('导入任务已取消');
                        
                        // 刷新页面���重置所有状态
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showError(response.data || '取消导入失败');
                    }
                },
                error: function(xhr, status, error) {
                    showError('取消导入失败: ' + error);
                }
            });
        }
    });

    // 处理继续导入按钮点击
    $('.shi-resume-import').on('click', function(e) {
        e.preventDefault();
        
        if (isImporting) {
            console.log('Already importing, skipping...');
            return;
        }
        
        console.log('Starting resume import...');
        isImporting = true;
        
        // 显示进度区域和日志区域
        $('#shi-import-progress').show();
        $('#shi-log-section').show();
        
        // 开始刷新日志
        startLogRefresh();
        
        // 禁用按钮
        $(this).prop('disabled', true).addClass('loading');
        
        // 发送继续导入请求
        $.ajax({
            url: shiSettings.ajax_url,
            type: 'POST',
            data: {
                action: 'shi_resume_import',
                nonce: shiSettings.nonce
            },
            success: function(response) {
                console.log('Resume import response:', response);
                
                if (response.success) {
                    const data = response.data;
                    console.log('Resume data:', {
                        file: data.file,
                        total: data.total,
                        processed: data.processed,
                        success: data.success,
                        failed: data.failed
                    });
                    
                    // 确保文件路径存在
                    if (!data.file) {
                        console.error('No file path provided in response');
                        showError('无法继续导入：文件路径缺失');
                        isImporting = false;
                        return;
                    }
                    
                    // 更新进度显示
                    updateProgress(data.processed, data.total);
                    
                    // 开始处理批次，使用当前已处理的数量作为偏移量
                    processBatch(data.file, parseInt(data.processed));
                    
                    showSuccess('继续导入任务');
                } else {
                    console.error('Resume import failed:', response);
                    isImporting = false;
                    $('.shi-resume-import').prop('disabled', false).removeClass('loading');
                    showError(response.data || '继续导入失败');
                }
            },
            error: function(xhr, status, error) {
                console.error('Resume import ajax error:', {
                    status: status,
                    error: error,
                    xhr: xhr
                });
                isImporting = false;
                $('.shi-resume-import').prop('disabled', false).removeClass('loading');
                showError('继续导入失败: ' + error);
            }
        });
    });

    // 确保在页面卸载时停止日志刷新
    $(window).on('beforeunload', function() {
        stopLogRefresh();
    });

    // 复制 Sitemap URL
    $('.shi-copy-url').on('click', function() {
        const url = $(this).data('clipboard-text');
        
        // 创建临时输���框
        const temp = $('<input>');
        $('body').append(temp);
        temp.val(url).select();
        
        // 复制
        try {
            document.execCommand('copy');
            // 显示成功提示
            const $button = $(this);
            const originalText = $button.text();
            $button.text('已复制！');
            setTimeout(function() {
                $button.text(originalText);
            }, 2000);
        } catch (err) {
            console.error('复制失败:', err);
        }
        
        // 移除临时输入框
        temp.remove();
    });
});