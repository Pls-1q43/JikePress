jQuery(document).ready(function($) {
    let selectedFiles = [];
    let uploadedIds = [];
    let isUploading = false;
    
    // 选择图片按钮点击
    $(document).on('click', '#shi-select-button', function(e) {
        e.preventDefault();
        $('#shi-image-upload').trigger('click');
    });

    // 文件选择处理
    $(document).on('change', '#shi-image-upload', function(e) {
        console.log('Files selected:', e.target.files);
        const files = Array.from(e.target.files);
        
        if (files.length > shiSettings.maxFiles) {
            alert('最多只能上传 ' + shiSettings.maxFiles + ' 张图片');
            return;
        }

        selectedFiles = files.filter(file => {
            if (!shiSettings.allowedTypes.includes(file.type)) {
                alert('不支持的文件类型: ' + file.type);
                return false;
            }
            if (file.size > shiSettings.maxSize) {
                alert('文件太大: ' + file.name);
                return false;
            }
            return true;
        });

        showPreviews(selectedFiles);
        $('#shi-upload-button').show();
    });

    // 上传按钮点击
    $(document).on('click', '#shi-upload-button', function(e) {
        e.preventDefault();
        if (selectedFiles.length > 0 && !isUploading) {
            isUploading = true;
            uploadedIds = [];
            
            // 禁用发布按钮和上传按钮
            disableButtons(true);
            
            uploadFiles();
        }
    });

    // 禁用/启用按钮
    function disableButtons(disable) {
        // 禁用 BuddyPress 的发布按钮
        $('#aw-whats-new-submit').prop('disabled', disable);
        
        // 禁用上传相关按钮
        $('#shi-upload-button, #shi-select-button').prop('disabled', disable);
        
        // 添加视觉反馈
        if (disable) {
            $('#aw-whats-new-submit').addClass('loading').css('opacity', '0.5');
            $('#shi-upload-button').addClass('loading').css('opacity', '0.5');
        } else {
            $('#aw-whats-new-submit').removeClass('loading').css('opacity', '1');
            $('#shi-upload-button').removeClass('loading').css('opacity', '1');
        }
    }

    // 上传所有文件
    function uploadFiles() {
        console.log('Starting upload for files:', selectedFiles);
        let uploadedCount = 0;
        const totalFiles = selectedFiles.length;
        
        // 更新进度条标题
        $('.shi-progress-title').text('正在上传图片...');
        
        selectedFiles.forEach((file, index) => {
            console.log('Preparing to upload file:', index, file.name);
            
            const xhr = new XMLHttpRequest();
            const formData = new FormData();
            
            formData.append('action', 'shi_upload_image');
            formData.append('nonce', shiSettings.nonce);
            formData.append('file', file);

            // 监听上传进度
            xhr.upload.onprogress = function(e) {
                console.log('Upload progress for file:', index, e.loaded, e.total);
                if (e.lengthComputable) {
                    const fileProgress = (e.loaded / e.total) * 100;
                    const totalProgress = ((uploadedCount * 100) + fileProgress) / totalFiles;
                    
                    console.log('Progress:', {
                        file: index,
                        fileProgress: fileProgress,
                        totalProgress: totalProgress
                    });
                    
                    // 更新进度条
                    $('.shi-progress-inner').css('width', totalProgress + '%');
                    $('.shi-progress-count').text(`${uploadedCount}/${totalFiles}`);
                    $(`.shi-preview-item:eq(${index}) .shi-upload-status`)
                        .text(`上传中 ${Math.round(fileProgress)}%`);
                }
            };

            xhr.onload = function() {
                console.log('Upload complete for file:', index);
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        console.log('Upload response:', response);
                        if (response.success) {
                            uploadedIds.push(response.data.id);
                            $(`.shi-preview-item:eq(${index}) .shi-upload-status`)
                                .text('已上传')
                                .addClass('success');
                        } else {
                            console.error('Upload failed:', response);
                            $(`.shi-preview-item:eq(${index}) .shi-upload-status`)
                                .text('上传失败')
                                .addClass('error');
                        }
                    } catch (e) {
                        console.error('Response parse error:', e);
                        $(`.shi-preview-item:eq(${index}) .shi-upload-status`)
                            .text('上传失败')
                            .addClass('error');
                    }
                }
                
                uploadedCount++;
                $('.shi-progress-count').text(`${uploadedCount}/${totalFiles}`);
                
                if (uploadedCount === totalFiles) {
                    console.log('All files uploaded');
                    uploadComplete();
                }
            };

            // 开始上传
            console.log('Sending XHR request for file:', index);
            xhr.open('POST', shiSettings.ajaxurl);
            xhr.send(formData);
        });
    }

    // 上传完成处理
    function uploadComplete() {
        console.log('Upload complete, ids:', uploadedIds);
        isUploading = false;
        
        if (uploadedIds.length > 0) {
            const content = $('#whats-new').val();
            const gallery = '[gallery type="rectangular" columns="3" link="file" ids="' + uploadedIds.join(',') + '" size="large"]';
            $('#whats-new').val(content + "\n\n" + gallery);
            
            console.log('Gallery shortcode added:', gallery);
            
            // 启用所有按钮
            disableButtons(false);
            
            // 隐藏上传按钮
            $('#shi-upload-button').hide();
            
            // 清理选择的文件
            selectedFiles = [];
            $('#shi-image-upload').val('');
        }
    }

    // 显示文件预览
    function showPreviews(files) {
        console.log('Showing previews for files:', files);
        $('#shi-image-preview').empty();
        
        // 添加进度条容器
        const progressContainer = $(`
            <div class="shi-progress-container">
                <div class="shi-progress-header">
                    <span class="shi-progress-title">准备上传...</span>
                    <span class="shi-progress-count">0/${files.length}</span>
                </div>
                <div class="shi-progress-bar">
                    <div class="shi-progress-inner" style="width: 0%"></div>
                </div>
            </div>
        `);
        
        console.log('Adding progress container');
        $('#shi-image-preview').append(progressContainer);
        
        files.forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                console.log('Preview loaded for file:', index);
                const preview = $('<div class="shi-preview-item">' +
                    '<img src="' + e.target.result + '">' +
                    '<div class="shi-upload-status">待上传</div>' +
                    '</div>');
                $('#shi-image-preview').append(preview);
            };
            reader.readAsDataURL(file);
        });
    }
}); 