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

    /* =====================================================================
     * GitHub Release 下载
     *
     * 输入框里出现 GitHub 地址时，在“生成文章”旁边多出一个按钮。
     * 弹窗里列出该仓库所有版本的资产，勾选后下载到服务器指定目录。
     *
     * 下载拆成“准备 → 分片(反复调用) → 合并”三步，每个请求只做几 MB，
     * 这样即使 PHP 的 max_execution_time 只有 30 秒也不会中途断掉。
     * ================================================================== */

    function ghConfig() {
        return (typeof sawAdmin !== 'undefined' && sawAdmin.gh) ? sawAdmin.gh : { root: '', maxMb: 512, byOs: 1, parts: 3 };
    }

    var ghState = {
        repo: '',
        owner: '',
        repoName: '',
        releases: [],
        selected: {},
        subdirTouched: false,
        busy: false
    };

    function ghPost(action, data) {
        return new Promise(function (resolve, reject) {
            $.post(sawAdmin.ajaxUrl, $.extend({ action: action, nonce: sawAdmin.nonce }, data || {}), null, 'json')
                .done(function (response) {
                    if (!response || typeof response.success === 'undefined') {
                        reject(new Error('服务器返回的内容无法识别，可能被其他插件或防火墙拦截了。'));
                        return;
                    }
                    if (!response.success) {
                        reject(new Error((response.data && response.data.message) || '请求失败。'));
                        return;
                    }
                    resolve(response.data);
                })
                .fail(function (xhr) {
                    var payload = xhr && xhr.responseJSON;
                    var message = payload && payload.data && payload.data.message;
                    if (!message && xhr && xhr.status === 413) {
                        message = '请求被服务器拒绝（413），可能是主机限制了请求体大小。';
                    }
                    if (!message && xhr && xhr.status === 0) {
                        message = '连接被中断，请重试。';
                    }
                    reject(new Error(message || '请求失败（HTTP ' + (xhr ? xhr.status : '?') + '）。'));
                });
        });
    }

    /** 从输入内容里认出 GitHub 仓库。支持多行，取第一个能认出来的。 */
    function detectGithub(text) {
        if (!text) {
            return '';
        }
        var lines = String(text).split(/[\r\n]+/);
        var i;
        for (i = 0; i < lines.length; i++) {
            var line = $.trim(lines[i]);
            if (!line) {
                continue;
            }
            var url = line.match(/github\.com\/([A-Za-z0-9][A-Za-z0-9_.-]{0,38})\/([A-Za-z0-9._-]{1,100})/i);
            if (url) {
                return url[1] + '/' + url[2].replace(/\.git$/i, '');
            }
            // 只把“裸的 owner/repo”当仓库，避免把“素材/软件”这种词误判。
            var plain = line.match(/^([A-Za-z0-9][A-Za-z0-9_.-]{0,38})\/([A-Za-z0-9._-]{1,100})$/);
            if (plain && plain[1].length >= 2 && plain[2].length >= 2) {
                return plain[1] + '/' + plain[2].replace(/\.git$/i, '');
            }
        }
        return '';
    }

    function refreshGithubButton() {
        var button = $('#saw-gh-open');
        if (!button.length) {
            return;
        }
        var repo = detectGithub($('#saw-input').val());
        var hint = $('#saw-gh-hint');
        button.prop('hidden', !repo);
        if (repo) {
            button.text(ghState.repo === repo && ghState.releases.length ? '下载 GitHub 内容（' + repo + '）' : '下载 GitHub 内容');
            hint.prop('hidden', false).text('识别到 GitHub 项目 ' + repo + '。可以先把 Release 里的安装包下载到服务器指定目录，写文章时直接用站内链接。');
        } else {
            hint.prop('hidden', true).text('');
        }
    }

    function currentRelease() {
        var index = parseInt($('#saw-gh-release').val(), 10);
        if (isNaN(index) || !ghState.releases[index]) {
            return ghState.releases[0] || null;
        }
        return ghState.releases[index];
    }

    function formatSize(bytes) {
        var value = Number(bytes) || 0;
        if (value <= 0) {
            return '—';
        }
        var units = ['B', 'KB', 'MB', 'GB'];
        var unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        return (unit === 0 ? value.toFixed(0) : value.toFixed(1)) + ' ' + units[unit];
    }

    function renderReleaseOptions() {
        var select = $('#saw-gh-release').empty();
        var onlyLatest = $('#saw-gh-latest-only').prop('checked');
        var list = onlyLatest ? ghState.releases.slice(0, 1) : ghState.releases;
        list.forEach(function (release) {
            var index = ghState.releases.indexOf(release);
            var label = release.tag;
            if (release.name && release.name !== release.tag) {
                label += ' · ' + release.name;
            }
            if (release.prerelease) {
                label += '（预发布）';
            }
            if (release.is_latest) {
                label += ' · 最新';
            }
            label += '　' + (release.assets ? release.assets.length : 0) + ' 个文件';
            select.append($('<option>').val(index).text(label));
        });
        select.prop('disabled', onlyLatest);
    }

    function renderAssets() {
        var box = $('#saw-gh-assets').empty();
        var release = currentRelease();
        if (!release) {
            box.append($('<p>').addClass('description').text('这个版本没有可下载的文件。'));
            updateSelectionSummary();
            return;
        }
        if (!release.assets || !release.assets.length) {
            box.append($('<p>').addClass('description').text('这个版本没有附带文件，只有源码。需要源码可以到仓库首页点 “Code → Download ZIP”。'));
            updateSelectionSummary();
            return;
        }

        var table = $('<table>').addClass('saw-gh-table');
        var head = $('<thead>').append(
            $('<tr>').append(
                $('<th>').addClass('saw-gh-col-check').append($('<input type="checkbox" id="saw-gh-check-all">')),
                $('<th>').text('文件'),
                $('<th>').addClass('saw-gh-col-os').text('系统'),
                $('<th>').addClass('saw-gh-col-size').text('大小'),
                $('<th>').addClass('saw-gh-col-dl').text('下载量')
            )
        );
        var body = $('<tbody>');
        release.assets.forEach(function (asset) {
            var key = release.tag + '|' + asset.name;
            var checked = !!ghState.selected[key];
            var checkbox = $('<input type="checkbox">').addClass('saw-gh-asset')
                .attr('data-key', key)
                .prop('checked', checked);
            var label = $('<span>').addClass('saw-gh-filename').text(asset.name);
            if (asset.arch_label) {
                label.append($('<em>').addClass('saw-gh-arch').text(asset.arch_label));
            }
            var row = $('<tr>').attr('data-key', key).toggleClass('is-checked', checked).append(
                $('<td>').append(checkbox),
                $('<td>').append(label),
                $('<td>').append($('<span>').addClass('saw-gh-os saw-gh-os-' + asset.os).text(asset.os_label)),
                $('<td>').addClass('saw-gh-num').text(asset.size_text),
                $('<td>').addClass('saw-gh-num').text(asset.downloads ? asset.downloads.toLocaleString() : '—')
            );
            body.append(row);
        });
        table.append(head).append(body);
        box.append(table);
        updateSelectionSummary();
    }

    function selectedAssets() {
        var release = currentRelease();
        if (!release) {
            return [];
        }
        var picked = [];
        release.assets.forEach(function (asset) {
            var key = release.tag + '|' + asset.name;
            if (ghState.selected[key]) {
                picked.push(asset);
            }
        });
        return picked;
    }

    function baseSubdir() {
        var release = currentRelease();
        var parts = [ghState.repoName];
        if (release) {
            parts.push(release.tag);
        }
        return parts.filter(Boolean).join('/');
    }

    function fillSubdirIfUntouched() {
        if (!ghState.subdirTouched) {
            $('#saw-gh-subdir').val(baseSubdir());
        }
        updatePathPreview();
    }

    function targetSubdirFor(asset) {
        var subdir = $.trim($('#saw-gh-subdir').val()) || baseSubdir();
        if ($('#saw-gh-by-os').prop('checked') && asset && asset.os && asset.os !== 'other') {
            var labels = { windows: 'Windows', macos: 'macOS', linux: 'Linux', android: 'Android', ios: 'iOS', source: '源码' };
            if (labels[asset.os]) {
                subdir += '/' + labels[asset.os];
            }
        }
        return subdir.replace(/^\/+|\/+$/g, '');
    }

    function absolutePreview(subdir) {
        var root = ghConfig().root || '';
        var clean = String(subdir || '').replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
        if (!root) {
            return clean;
        }
        return root.replace(/\/+$/, '') + (clean ? '/' + clean : '');
    }

    function updatePathPreview() {
        var picked = selectedAssets();
        var box = $('#saw-gh-abs').empty();
        var root = ghConfig().root || '(未设置下载根目录)';
        $('#saw-gh-root').text(root.replace(/\/+$/, '') + '/');
        if (!picked.length) {
            box.text('勾选文件后显示具体保存位置。');
            return;
        }
        box.append($('<span>').text('将保存到：'));
        var list = $('<ul>').addClass('saw-gh-paths');
        picked.slice(0, 8).forEach(function (asset) {
            var full = absolutePreview(targetSubdirFor(asset)) + '/' + asset.name;
            list.append($('<li>').text(full));
        });
        if (picked.length > 8) {
            list.append($('<li>').text('…等共 ' + picked.length + ' 个文件'));
        }
        box.append(list);
    }

    function updateSelectionSummary() {
        var picked = selectedAssets();
        $('#saw-gh-start').prop('disabled', !picked.length || ghState.busy);
        var total = picked.reduce(function (sum, asset) {
            return sum + (Number(asset.size) || 0);
        }, 0);
        var checkAll = $('#saw-gh-check-all');
        var release = currentRelease();
        if (checkAll.length && release && release.assets.length) {
            checkAll.prop('checked', picked.length === release.assets.length);
            checkAll.prop('indeterminate', picked.length > 0 && picked.length < release.assets.length);
        }
        updatePathPreview();
        if (picked.length) {
            $('#saw-gh-abs').attr('data-total', total);
        }
    }

    function openGithubModal() {
        var repo = detectGithub($('#saw-input').val());
        if (!repo) {
            showMessage($('#saw-message'), '请先在输入框里填写 GitHub 项目地址。', true);
            return;
        }
        $('#saw-gh-modal').prop('hidden', false);
        document.body.classList.add('saw-modal-open');
        scanRepository(repo);
    }

    function closeGithubModal() {
        if (ghState.busy) {
            if (!window.confirm('还有文件正在下载，关闭窗口会中断当前请求（已下载的分片会保留，下次可以继续）。确定关闭吗？')) {
                return;
            }
        }
        $('#saw-gh-modal').prop('hidden', true);
        document.body.classList.remove('saw-modal-open');
    }

    function scanRepository(repo) {
        ghState.repo = repo;
        ghState.selected = {};
        ghState.releases = [];
        ghState.subdirTouched = false;
        $('#saw-gh-repo').text('正在读取 ' + repo + ' 的 Release…');
        $('#saw-gh-assets').empty();
        $('#saw-gh-release').empty();
        $('#saw-gh-progress').prop('hidden', true).empty();
        $('#saw-gh-refresh').prop('hidden', true);
        $('#saw-gh-subdir').val('');
        showMessage($('#saw-gh-message'), '正在扫描 Release 列表…', false);
        $('#saw-gh-spinner').addClass('is-active');

        ghPost('saw_gh_scan', { input: repo }).then(function (data) {
            ghState.owner = data.owner;
            ghState.repoName = data.repo_name;
            ghState.releases = data.releases || [];
            var title = data.repo;
            var count = ghState.releases.length;
            $('#saw-gh-repo').text(title + '　共 ' + count + ' 个版本' + (data.cached ? '（缓存结果，5 分钟内不重复请求）' : ''));

            renderReleaseOptions();
            // 地址里带了版本号就直接定位到那个版本。
            if (data.target_tag) {
                ghState.releases.forEach(function (release, index) {
                    if (release.tag === data.target_tag) {
                        $('#saw-gh-latest-only').prop('checked', false);
                        renderReleaseOptions();
                        $('#saw-gh-release').val(index);
                    }
                });
            }
            // 地址指向具体文件时预先勾上。
            if (data.target_asset) {
                var release = currentRelease();
                if (release) {
                    ghState.selected[release.tag + '|' + data.target_asset] = true;
                }
            }
            // 先定好“按系统归档”，再渲染资产和路径，否则预览用的是旧勾选状态。
            $('#saw-gh-by-os').prop('checked', ghConfig().byOs !== 0);
            renderAssets();
            fillSubdirIfUntouched();
            updatePathPreview();

            var rootReady = data.root_ready;
            var msgs = [];
            if (!rootReady) {
                msgs.push('保存目录 ' + data.root + ' 还不可写，第一次下载会自动创建；如果失败请到设置页检查目录权限。');
            }
            if (data.max_mb) {
                msgs.push('单个文件上限 ' + data.max_mb + ' MB。');
            }
            showMessage($('#saw-gh-message'), msgs.join(' '), !rootReady);
        }).catch(function (error) {
            $('#saw-gh-repo').text('读取 ' + repo + ' 失败');
            showMessage($('#saw-gh-message'), error.message, true);
            $('#saw-gh-refresh').prop('hidden', false);
        }).then(function () {
            $('#saw-gh-spinner').removeClass('is-active');
        });
    }

    /** 单个文件：准备 → 逐片下载 → 合并 */
    function downloadFile(asset, subdir) {
        var jobBox = $('<div>').addClass('saw-gh-job').attr('data-name', asset.name);
        var head = $('<div>').addClass('saw-gh-job-head').append(
            $('<span>').addClass('saw-gh-job-name').text(asset.name),
            $('<span>').addClass('saw-gh-job-meta').text('准备中…')
        );
        var bar = $('<div>').addClass('saw-gh-bar').append($('<i>').css('width', '0%'));
        jobBox.append(head).append(bar);
        $('#saw-gh-progress').append(jobBox);

        var jobId = '';
        var totalBytes = Number(asset.size) || 0;
        var lastBytes = 0;
        var lastTime = Date.now();
        var speed = 0;

        function paint(progress) {
            var percent = progress && typeof progress.percent === 'number' ? progress.percent : 0;
            jobBox.find('.saw-gh-bar i').css('width', Math.max(2, percent) + '%');
            var received = progress ? progress.received : 0;
            var now = Date.now();
            if (now - lastTime > 700) {
                var delta = received - lastBytes;
                if (delta > 0) {
                    speed = delta / ((now - lastTime) / 1000);
                }
                lastBytes = received;
                lastTime = now;
            }
            var meta = (progress ? progress.received_text : '0 B') + ' / ' + (progress ? progress.total_text : formatSize(totalBytes));
            if (progress && progress.total > 0) {
                meta += ' · ' + percent + '%';
            }
            if (speed > 0 && percent < 100) {
                meta += ' · ' + formatSize(speed) + '/s';
            }
            if (progress && progress.parts > 1) {
                meta += ' · ' + progress.parts_done + '/' + progress.parts + ' 片';
            }
            head.find('.saw-gh-job-meta').text(meta);
        }

        return ghPost('saw_gh_prepare', {
            url: asset.url,
            filename: asset.name,
            size: totalBytes,
            subdir: subdir
        }).then(function (data) {
            jobId = data.job_id;
            totalBytes = data.size || totalBytes;
            jobBox.attr('data-job', jobId);
            head.find('.saw-gh-job-meta').text('0 B / ' + (data.size_text || formatSize(totalBytes)) + ' · 线路 ' + (data.route || '直连'));
            paint({ received: 0, total: totalBytes, percent: 0, received_text: '0 B', total_text: data.size_text, parts: data.parts, parts_done: 0 });

            // 每个分片各跑一条链，分片之间并发。
            var chains = [];
            var partCount = Math.max(1, Number(data.parts) || 1);
            for (var index = 0; index < partCount; index++) {
                chains.push(runPart(jobId, index, paint));
            }
            return Promise.all(chains);
        }).then(function () {
            head.find('.saw-gh-job-meta').text('正在合并分片…');
            return ghPost('saw_gh_finish', { job_id: jobId });
        }).then(function (result) {
            jobBox.addClass('is-done');
            jobBox.find('.saw-gh-bar i').css('width', '100%');
            head.find('.saw-gh-job-meta').text(result.size_text + ' · 已保存');
            var detail = $('<div>').addClass('saw-gh-job-done');
            detail.append($('<code>').text(result.file));
            if (result.url) {
                detail.append($('<a>').addClass('saw-gh-link').attr({ href: result.url, target: '_blank', rel: 'noopener' }).text('下载到本机'));
            }
            detail.append($('<button type="button">').addClass('button-link saw-gh-copy').attr('data-copy', result.file).text('复制路径'));
            jobBox.append(detail);
            return result;
        }).catch(function (error) {
            jobBox.addClass('is-error');
            head.find('.saw-gh-job-meta').text('失败：' + error.message);
            if (jobId) {
                jobBox.append($('<button type="button">').addClass('button-link saw-gh-abort').attr('data-job', jobId).text('删除未完成的分片'));
            }
            throw error;
        });
    }

    /**
     * 跑一个分片的下载循环。
     *
     * 每次请求只拿一片，服务端按已写入的字节数继续，所以中断后重来不会从头下。
     * 连续 5 次请求都没有新字节进来就判定这条分片卡死，避免无限循环。
     */
    function runPart(jobId, index, paint) {
        var stagnation = 0;
        var lastSize = -1;

        function step() {
            return ghPost('saw_gh_part', { job_id: jobId, index: index }).then(function (data) {
                if (data.progress) {
                    paint(data.progress);
                }
                if (data.done) {
                    return true;
                }
                if (data.received === lastSize) {
                    stagnation++;
                } else {
                    stagnation = 0;
                    lastSize = data.received;
                }
                if (stagnation >= 5) {
                    throw new Error('分片 ' + (index + 1) + ' 连续多次没有进展，可能是线路不稳定，请重试。');
                }
                return step();
            });
        }
        return step();
    }

    /** 限制并发的简单任务池，避免同时发起几十个请求把浏览器连接数占满 */
    function runPool(items, limit, worker) {
        var cursor = 0;
        var results = [];
        function next() {
            if (cursor >= items.length) {
                return Promise.resolve();
            }
            var position = cursor++;
            return Promise.resolve(worker(items[position], position)).then(function (value) {
                results[position] = value;
                return next();
            });
        }
        var runners = [];
        var size = Math.min(limit, items.length);
        for (var i = 0; i < size; i++) {
            runners.push(next());
        }
        return Promise.all(runners).then(function () {
            return results;
        });
    }

    function startGithubDownload() {
        var picked = selectedAssets();
        if (!picked.length) {
            showMessage($('#saw-gh-message'), '请先勾选要下载的文件。', true);
            return;
        }
        var maxMb = ghConfig().maxMb || 512;
        var oversized = picked.filter(function (asset) {
            return asset.size > maxMb * 1048576;
        });
        if (oversized.length) {
            showMessage($('#saw-gh-message'), '这些文件超过单文件上限（' + maxMb + ' MB），请到设置页调高上限：' + oversized.map(function (a) { return a.name; }).join('、'), true);
            return;
        }

        ghState.busy = true;
        $('#saw-gh-start').prop('disabled', true);
        $('#saw-gh-progress').prop('hidden', false).empty();
        $('#saw-gh-spinner').addClass('is-active');

        var done = [];
        var failed = [];
        var totalBytes = picked.reduce(function (sum, asset) { return sum + (Number(asset.size) || 0); }, 0);
        showMessage($('#saw-gh-message'), '开始下载 ' + picked.length + ' 个文件，共 ' + formatSize(totalBytes) + '。下载中可以继续用这个页面，不要关闭浏览器标签。', false);

        runPool(picked, 2, function (asset) {
            return downloadFile(asset, targetSubdirFor(asset)).then(function (result) {
                done.push(result);
            }).catch(function (error) {
                failed.push({ name: asset.name, message: error.message });
            });
        }).then(function () {
            ghState.busy = false;
            $('#saw-gh-start').prop('disabled', !selectedAssets().length);
            $('#saw-gh-spinner').removeClass('is-active');

            if (!failed.length) {
                var summary = '全部下载完成，共 ' + done.length + ' 个文件。';
                var first = done[0];
                if (first && first.dir) {
                    summary += ' 目录：' + first.dir;
                }
                showMessage($('#saw-gh-message'), summary, false);
                return;
            }
            var lines = failed.map(function (item) { return item.name + '：' + item.message; });
            showMessage($('#saw-gh-message'), (done.length ? '成功 ' + done.length + ' 个，失败 ' + failed.length + ' 个。' : '下载失败。') + ' ' + lines.join('　'), true);
            $('#saw-gh-refresh').prop('hidden', false);
        });
    }

    // 输入框变化时决定要不要显示下载按钮。用 input 事件，粘贴也能立刻识别。
    $(document).on('input', '#saw-input', refreshGithubButton);
    $(document).on('click', '#saw-gh-open', openGithubModal);
    $(document).on('click', '#saw-gh-modal [data-saw-close]', closeGithubModal);

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && !$('#saw-gh-modal').prop('hidden')) {
            closeGithubModal();
        }
    });

    $(document).on('change', '#saw-gh-latest-only', function () {
        renderReleaseOptions();
        ghState.selected = {};
        renderAssets();
        fillSubdirIfUntouched();
    });

    $(document).on('change', '#saw-gh-release', function () {
        ghState.selected = {};
        renderAssets();
        fillSubdirIfUntouched();
    });

    $(document).on('change', '#saw-gh-check-all', function () {
        var checked = $(this).prop('checked');
        var release = currentRelease();
        if (!release) {
            return;
        }
        release.assets.forEach(function (asset) {
            var key = release.tag + '|' + asset.name;
            if (checked) {
                ghState.selected[key] = true;
            } else {
                delete ghState.selected[key];
            }
        });
        renderAssets();
    });

    $(document).on('change', '.saw-gh-asset', function () {
        var key = $(this).attr('data-key');
        if ($(this).prop('checked')) {
            ghState.selected[key] = true;
        } else {
            delete ghState.selected[key];
        }
        $(this).closest('tr').toggleClass('is-checked', $(this).prop('checked'));
        updateSelectionSummary();
    });

    $(document).on('click', '#saw-gh-select-all', function () {
        var release = currentRelease();
        if (!release) {
            return;
        }
        release.assets.forEach(function (asset) {
            ghState.selected[release.tag + '|' + asset.name] = true;
        });
        renderAssets();
    });

    $(document).on('click', '#saw-gh-select-none', function () {
        ghState.selected = {};
        renderAssets();
    });

    $(document).on('input', '#saw-gh-subdir', function () {
        ghState.subdirTouched = true;
        updatePathPreview();
    });

    $(document).on('change', '#saw-gh-by-os', updatePathPreview);

    $(document).on('click', '#saw-gh-start', startGithubDownload);

    $(document).on('click', '#saw-gh-refresh', function () {
        scanRepository(ghState.repo || detectGithub($('#saw-input').val()));
    });

    $(document).on('click', '.saw-gh-copy', function () {
        var button = $(this);
        var text = button.attr('data-copy') || '';
        var fallback = function () {
            var input = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
            input[0].select();
            try {
                document.execCommand('copy');
                button.text('已复制');
            } catch (error) {
                button.text('复制失败，请手动选中');
            }
            input.remove();
            window.setTimeout(function () { button.text('复制路径'); }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                button.text('已复制');
                window.setTimeout(function () { button.text('复制路径'); }, 2000);
            }).catch(fallback);
        } else {
            fallback();
        }
    });

    $(document).on('click', '.saw-gh-abort', function () {
        var button = $(this);
        var jobId = button.attr('data-job');
        button.prop('disabled', true).text('正在删除…');
        ghPost('saw_gh_abort', { job_id: jobId }).then(function (data) {
            button.closest('.saw-gh-job').remove();
            showMessage($('#saw-gh-message'), data.message, false);
        }).catch(function (error) {
            button.prop('disabled', false).text('删除失败，重试');
            showMessage($('#saw-gh-message'), error.message, true);
        });
    });

    // 页面刚打开时如果输入框里已经有地址（比如浏览器恢复表单内容），也要显示按钮。
    refreshGithubButton();
})(jQuery);
