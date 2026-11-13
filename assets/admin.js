(function ($) {
    'use strict';

    var generatedSourceUrl = '';
    var timerHandle = null;
    var timerStart = 0;

    function setBusy(button, spinner, busy) {
        button.prop('disabled', busy);
        spinner.toggleClass('is-active', busy);
    }

    function showMessage(target, message, isError) {
        target.text(message || '').toggleClass('saw-error', !!isError).toggleClass('saw-success', !isError && !!message);
    }

    function startTimer() {
        var el = $('#saw-timer');
        timerStart = new Date().getTime();
        el.prop('hidden', false).text('已用时 0.0 秒');
        if (timerHandle) {
            window.clearInterval(timerHandle);
        }
        timerHandle = window.setInterval(function () {
            var elapsed = (new Date().getTime() - timerStart) / 1000;
            el.text('已用时 ' + elapsed.toFixed(1) + ' 秒');
        }, 200);
    }

    function stopTimer(label) {
        var el = $('#saw-timer');
        if (timerHandle) {
            window.clearInterval(timerHandle);
            timerHandle = null;
        }
        if (label) {
            el.text(label);
        }
    }

    function refreshPreview() {
        var frame = document.getElementById('saw-preview');
        if (!frame) {
            return;
        }
        var content = $('#saw-content').html();
        frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><style>body{font:16px/1.8 sans-serif;padding:20px;color:#24272b}h2,h3{margin-top:1.6em}li{margin:.5em 0}a{color:#2271b1}</style></head><body>' + content + '</body></html>';
    }

    function renderWarnings(warnings) {
        var box = $('#saw-warnings');
        box.empty();
        if (!warnings || !warnings.length) {
            box.prop('hidden', true);
            return;
        }
        var list = $('<ul>');
        warnings.slice(0, 12).forEach(function (warning) {
            list.append($('<li>').text(warning));
        });
        box.append($('<p>').addClass('saw-warnings-title').text('校验提示（' + warnings.length + ' 条）'))
            .append(list)
            .prop('hidden', false);
    }

    function renderStats(data) {
        var box = $('#saw-stats');
        var parts = [];
        if (data.timing && data.timing.total) {
            parts.push('总耗时 ' + data.timing.total + ' 秒');
        }
        if (data.timing && typeof data.timing.fetch !== 'undefined') {
            parts.push('资料抓取 ' + data.timing.fetch + ' 秒');
        }
        if (data.timing && typeof data.timing.ai !== 'undefined') {
            parts.push('AI 生成 ' + data.timing.ai + ' 秒');
        }
        if (data.cached) {
            parts.push('资料来自缓存');
        }
        if (data.feature_stats && data.feature_stats.length) {
            var okCount = data.feature_stats.filter(function (item) { return item.ok; }).length;
            var lens = data.feature_stats.map(function (item) { return item.length; });
            parts.push('特点 ' + data.feature_stats.length + ' 条，字数达标 ' + okCount + '/' + data.feature_stats.length + '（' + lens.join('/') + ' 字）');
        }
        if (data.usage && (data.usage.input || data.usage.output)) {
            var usage = 'token 输入 ' + data.usage.input + ' / 输出 ' + data.usage.output;
            if (data.usage.cached) {
                usage += '（缓存命中 ' + data.usage.cached + '）';
            }
            parts.push(usage);
        }
        if (typeof data.cost === 'number') {
            parts.push('本次约 ' + data.cost.toFixed(4) + ' 元');
        }
        if (!parts.length) {
            box.prop('hidden', true).text('');
            return;
        }
        box.text(parts.join('　|　')).prop('hidden', false);
    }

    function fillTitleOptions(titles, recommended) {
        var select = $('#saw-title-options').empty();
        (titles || []).forEach(function (title, index) {
            var text = title === recommended ? '推荐：' + title : title + '（' + title.length + ' 字）';
            select.append($('<option>').val(title).text(text));
        });
        if (recommended) {
            select.val(recommended);
        }
        $('#saw-title').val(recommended || '');
        $('#saw-title-hint').text(titles && titles.length ? '共 ' + titles.length + ' 个备选标题，可切换或手动修改。' : '');
    }

    if (typeof sawAdmin !== 'undefined' && !sawAdmin.apiReady && $('#saw-generate').length) {
        $('#saw-generate').prop('disabled', true);
        showMessage($('#saw-message'), 'API 尚未测试通过，请先到“接口设置”完成测试。', true);
    }

    $('#saw-test-api').on('click', function () {
        var button = $(this);
        var spinner = $('#saw-test-spinner');
        var message = $('#saw-test-message');
        var apiBase = $.trim($('#saw_api_base').val());
        var apiKey = $.trim($('#saw_api_key').val());
        var model = $.trim($('#saw_model').val());
        if (!apiBase || !apiKey || !model) {
            showMessage(message, '请先填写 API Base、API Key 和模型名称。', true);
            return;
        }
        setBusy(button, spinner, true);
        showMessage(message, '正在从 WordPress 服务器测试接口，请稍候...', false);
        $.post(sawAdmin.ajaxUrl, {
            action: 'saw_test_api',
            nonce: sawAdmin.nonce,
            api_base: apiBase,
            api_key: apiKey,
            model: model,
            api_format: $('#saw_api_format').val()
        }).done(function (response) {
            if (!response.success) {
                showMessage(message, response.data && response.data.message ? response.data.message : 'API 测试失败。', true);
                return;
            }
            showMessage(message, response.data.message + ' 测试地址：' + response.data.endpoint, false);
        }).fail(function (xhr) {
            var response = xhr.responseJSON;
            showMessage(message, response && response.data && response.data.message ? response.data.message : 'API 测试请求失败。', true);
        }).always(function () {
            setBusy(button, spinner, false);
        });
    });

    $('#saw-clear-cache').on('click', function () {
        var button = $(this);
        var message = $('#saw-cache-message');
        button.prop('disabled', true);
        showMessage(message, '正在清空缓存…', false);
        $.post(sawAdmin.ajaxUrl, {
            action: 'saw_clear_cache',
            nonce: sawAdmin.nonce
        }).done(function (response) {
            showMessage(message, response.data && response.data.message ? response.data.message : '已完成。', !response.success);
        }).fail(function () {
            showMessage(message, '清空缓存失败。', true);
        }).always(function () {
            button.prop('disabled', false);
        });
    });

    $('#saw-reset-skill').on('click', function () {
        if (typeof sawAdmin === 'undefined' || !sawAdmin.defaultWritingSkill) {
            return;
        }
        $('#saw_writing_skill').val(sawAdmin.defaultWritingSkill).trigger('input').focus();
    });

    $('#saw-generate').on('click', function () {
        var button = $(this);
        var spinner = $('#saw-spinner');
        var message = $('#saw-message');
        var input = $.trim($('#saw-input').val());
        if (!input) {
            showMessage(message, '请输入项目链接或资源名称。', true);
            return;
        }

        setBusy(button, spinner, true);
        startTimer();
        showMessage(message, '正在并发读取资料并生成文章，请稍候…', false);
        $.post(sawAdmin.ajaxUrl, {
            action: 'saw_generate_article',
            nonce: sawAdmin.nonce,
            input: input,
            type: $('#saw-type').val(),
            notes: $('#saw-notes').val()
        }).done(function (response) {
            if (!response.success) {
                showMessage(message, response.data && response.data.message ? response.data.message : '生成失败。', true);
                return;
            }
            var data = response.data;
            fillTitleOptions(data.title_options || [], data.recommended_title || '');
            $('#saw-content').html(data.content_html || '');
            generatedSourceUrl = data.source_url || '';
            $('#saw-result').prop('hidden', false);
            renderStats(data);
            renderWarnings(data.warnings || []);
            refreshPreview();
            var total = data.timing && data.timing.total ? data.timing.total + ' 秒' : '';
            showMessage(message, '文章已生成' + (total ? '（' + total + '）' : '') + '，请检查后保存为草稿。', false);
            $('html, body').animate({ scrollTop: $('#saw-result').offset().top - 40 }, 250);
        }).fail(function (xhr) {
            var response = xhr.responseJSON;
            showMessage(message, response && response.data && response.data.message ? response.data.message : '请求失败，请检查接口设置。', true);
        }).always(function () {
            setBusy(button, spinner, false);
            stopTimer();
        });
    });

    $('#saw-title-refresh').on('click', function () {
        var button = $(this);
        var spinner = $('#saw-title-spinner');
        var input = $.trim($('#saw-input').val());
        if (!input) {
            showMessage($('#saw-message'), '请先填写项目链接或资源名称。', true);
            return;
        }
        setBusy(button, spinner, true);
        $.post(sawAdmin.ajaxUrl, {
            action: 'saw_regenerate_titles',
            nonce: sawAdmin.nonce,
            input: input,
            type: $('#saw-type').val(),
            notes: $('#saw-notes').val(),
            count: (sawAdmin && sawAdmin.titleCount) || 5
        }).done(function (response) {
            if (!response.success) {
                showMessage($('#saw-message'), response.data && response.data.message ? response.data.message : '生成标题失败。', true);
                return;
            }
            fillTitleOptions(response.data.title_options || [], response.data.recommended_title || '');
            renderWarnings(response.data.warnings || []);
            showMessage($('#saw-message'), '已换一批标题，正文保持不变。', false);
        }).fail(function (xhr) {
            var response = xhr.responseJSON;
            showMessage($('#saw-message'), response && response.data && response.data.message ? response.data.message : '生成标题失败。', true);
        }).always(function () {
            setBusy(button, spinner, false);
        });
    });

    $('#saw-title-options').on('change', function () {
        $('#saw-title').val($(this).val());
    });

    $('#saw-content').on('input', refreshPreview);

    $('#saw-save').on('click', function () {
        var button = $(this);
        var spinner = $('#saw-save-spinner');
        var message = $('#saw-save-message');
        setBusy(button, spinner, true);
        showMessage(message, '正在保存草稿…', false);
        $.post(sawAdmin.ajaxUrl, {
            action: 'saw_save_draft',
            nonce: sawAdmin.nonce,
            title: $('#saw-title').val(),
            content: $('#saw-content').html(),
            category: $('#saw-category').val(),
            source_url: generatedSourceUrl
        }).done(function (response) {
            if (!response.success) {
                showMessage(message, response.data && response.data.message ? response.data.message : '保存失败。', true);
                return;
            }
            message.removeClass('saw-error').addClass('saw-success').empty()
                .append(document.createTextNode(response.data.message + ' '))
                .append($('<a>').attr('href', response.data.edit_url).text('打开草稿'));
        }).fail(function (xhr) {
            var response = xhr.responseJSON;
            showMessage(message, response && response.data && response.data.message ? response.data.message : '保存失败。', true);
        }).always(function () {
            setBusy(button, spinner, false);
        });
    });
})(jQuery);
