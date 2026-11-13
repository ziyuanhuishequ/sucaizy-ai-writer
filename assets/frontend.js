(function ($) {
    'use strict';

    var titleDefaults = [
        '#post_title',
        'input[name="post_title"]',
        'input[name="title"]',
        'input[placeholder*="请输入标题"]',
        'textarea[placeholder*="请输入标题"]',
        '[contenteditable="true"][placeholder*="请输入标题"]',
        '[contenteditable="true"][data-placeholder*="请输入标题"]',
        '[contenteditable="true"][aria-label*="标题"]',
        '#title',
        '.post-title input',
        '.new-post-title input'
    ];
    var contentDefaults = [
        '#post_content',
        'textarea[name="post_content"]',
        'textarea[name="content"]',
        '#content',
        '.post-content textarea',
        '.w-e-text[contenteditable="true"]',
        '.ql-editor[contenteditable="true"]',
        '[contenteditable="true"][data-placeholder]',
        '[contenteditable="true"][data-lexical-editor="true"]',
        '[contenteditable="true"][role="textbox"]',
        '.wp-editor-area'
    ];

    function setMessage(selector, text, error) {
        $(selector).text(text || '').toggleClass('is-error', !!error).toggleClass('is-success', !error && !!text);
    }

    var timerHandle = null;
    var timerStart = 0;

    function setBusy(busy) {
        var apiReady = typeof sawFrontend === 'undefined' || sawFrontend.apiReady;
        $('#saw-front-generate').prop('disabled', busy || !apiReady);
        $('#saw-front-loading').prop('hidden', !busy);
        if (busy) {
            timerStart = new Date().getTime();
            if (timerHandle) {
                window.clearInterval(timerHandle);
            }
            timerHandle = window.setInterval(function () {
                $('#saw-front-timer').text('（已用时 ' + ((new Date().getTime() - timerStart) / 1000).toFixed(1) + ' 秒）');
            }, 200);
        } else {
            if (timerHandle) {
                window.clearInterval(timerHandle);
                timerHandle = null;
            }
            $('#saw-front-timer').text('');
        }
    }

    function fillTitles(titles, recommended) {
        var select = $('#saw-front-titles').empty();
        (titles || []).forEach(function (title) {
            select.append($('<option>').val(title).text(title === recommended ? '推荐：' + title : title));
        });
        if (recommended) {
            select.val(recommended);
        }
        $('#saw-front-title').val(recommended || '');
    }

    function renderStats(data) {
        var box = $('#saw-front-stats');
        var parts = [];
        if (data.timing && data.timing.total) {
            parts.push('耗时 ' + data.timing.total + ' 秒');
        }
        if (data.cached) {
            parts.push('资料来自缓存');
        }
        if (data.feature_stats && data.feature_stats.length) {
            var okCount = data.feature_stats.filter(function (item) { return item.ok; }).length;
            parts.push('特点字数达标 ' + okCount + '/' + data.feature_stats.length);
        }
        if (data.usage && (data.usage.input || data.usage.output)) {
            parts.push('token ' + data.usage.input + '→' + data.usage.output);
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

    function renderWarnings(warnings) {
        var box = $('#saw-front-warnings');
        if (!warnings || !warnings.length) {
            box.prop('hidden', true).text('');
            return;
        }
        box.text('校验提示：' + warnings.slice(0, 4).join('；') + (warnings.length > 4 ? ' 等 ' + warnings.length + ' 条' : ''))
            .prop('hidden', false);
    }

    function selectors(custom, defaults) {
        return custom ? [custom].concat(defaults) : defaults;
    }

    function usableElement(element) {
        return element && !element.closest('.saw-front') && !element.disabled && element.type !== 'hidden' && element.getClientRects().length > 0;
    }

    function findField(list) {
        var i;
        var elements;
        var j;
        for (i = 0; i < list.length; i += 1) {
            try {
                elements = document.querySelectorAll(list[i]);
            } catch (ignore) {
                continue;
            }
            for (j = 0; j < elements.length; j += 1) {
                if (usableElement(elements[j])) {
                    return elements[j];
                }
            }
        }
        return null;
    }

    function notify(element) {
        ['input', 'change', 'keyup', 'blur'].forEach(function (name) {
            element.dispatchEvent(new Event(name, { bubbles: true }));
        });
    }

    function fillTitle(value) {
        var field = findField(selectors(sawFrontend.titleSelector, titleDefaults));
        var prototype;
        var descriptor;
        if (!field) {
            return false;
        }
        if (field.isContentEditable) {
            field.textContent = value;
        } else {
            prototype = field.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
            descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');
            if (descriptor && descriptor.set) {
                descriptor.set.call(field, value);
            } else {
                field.value = value;
            }
            field.setAttribute('value', value);
        }
        field.focus();
        notify(field);
        return true;
    }

    function fillTinyMce(html, preferredField) {
        var editor;
        var editors;
        var i;
        if (!window.tinymce) {
            return false;
        }
        if (preferredField && preferredField.id) {
            editor = window.tinymce.get(preferredField.id);
            if (editor) {
                editor.setContent(html);
                editor.fire('input');
                editor.fire('change');
                editor.save();
                return true;
            }
        }
        editors = window.tinymce.editors || [];
        for (i = 0; i < editors.length; i += 1) {
            editor = editors[i];
            if (editor && editor.targetElm && !editor.targetElm.closest('.saw-front')) {
                editor.setContent(html);
                editor.fire('input');
                editor.fire('change');
                editor.save();
                return true;
            }
        }
        return false;
    }

    function fillContent(html) {
        var field = findField(selectors(sawFrontend.contentSelector, contentDefaults));
        if (fillTinyMce(html, field)) {
            return true;
        }
        if (!field) {
            return false;
        }
        if (field.isContentEditable) {
            field.innerHTML = html;
        } else {
            field.value = html;
        }
        notify(field);
        return true;
    }

    function safePreview(html) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        Array.prototype.forEach.call(parsed.querySelectorAll('script,style,iframe,object,embed,form'), function (node) {
            node.remove();
        });
        Array.prototype.forEach.call(parsed.body.querySelectorAll('*'), function (node) {
            Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
                if (/^on/i.test(attribute.name)) {
                    node.removeAttribute(attribute.name);
                }
            });
        });
        return parsed.body.innerHTML;
    }

    function controlText(element) {
        return $.trim(element.value || element.textContent || '');
    }

    function placeLauncherInSidebar() {
        var launcher = document.getElementById('saw-front-launcher');
        var controls;
        var matches;
        var common;
        var parent;
        var best;
        var rect;
        var mount;
        if (!launcher || launcher.classList.contains('saw-front-embedded')) {
            return true;
        }
        controls = Array.prototype.slice.call(document.querySelectorAll('button, a, [role="button"], input[type="button"], input[type="submit"]'));
        matches = controls.filter(function (element) {
            var text = controlText(element);
            var elementRect = element.getBoundingClientRect();
            return elementRect.width > 0 && elementRect.height > 0 && !element.closest('.saw-front-dock') && (text.indexOf('保存草稿') !== -1 || text.indexOf('提交发布') !== -1);
        });
        if (!matches.length) {
            return false;
        }

        common = matches[0];
        while (common && !matches.every(function (element) { return common.contains(element); })) {
            common = common.parentElement;
        }
        if (!common) {
            return false;
        }
        best = common;
        parent = common.parentElement;
        while (parent && parent !== document.body) {
            rect = parent.getBoundingClientRect();
            if (rect.width >= 180 && rect.width <= 520 && rect.height > 0 && rect.height <= 280) {
                best = parent;
                parent = parent.parentElement;
                continue;
            }
            break;
        }
        if (!best.parentNode) {
            return false;
        }
        mount = document.createElement('div');
        mount.className = 'saw-front-mount';
        best.parentNode.insertBefore(mount, best.nextSibling);
        mount.appendChild(launcher);
        launcher.classList.add('saw-front-embedded');
        return true;
    }

    $(document).on('click', '#saw-front-launcher', function () {
        var dock = $('#saw-front-dock');
        var willShow = dock.prop('hidden');
        dock.prop('hidden', !willShow);
        $(this).attr('aria-expanded', willShow ? 'true' : 'false').text(willShow ? '关闭 AI 写作' : 'AI 写文章');
    });

    function closeWriter() {
        $('#saw-front-dock').prop('hidden', true);
        $('#saw-front-launcher').attr('aria-expanded', 'false').text('AI 写文章');
    }

    $(document).on('click', '#saw-front-close', closeWriter);

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && !$('#saw-front-dock').prop('hidden')) {
            closeWriter();
        }
    });

    if (typeof sawFrontend !== 'undefined' && !sawFrontend.apiReady) {
        $('#saw-front-generate').prop('disabled', true);
    }

    $(document).on('click', '#saw-front-generate', function () {
        var input = $.trim($('#saw-front-input').val());
        if (!input) {
            setMessage('#saw-front-message', '请输入项目链接或资源名称。', true);
            return;
        }
        setBusy(true);
        setMessage('#saw-front-message', '', false);
        $.post(sawFrontend.ajaxUrl, {
            action: 'saw_generate_article',
            nonce: sawFrontend.nonce,
            input: input,
            type: $('#saw-front-type').val(),
            notes: $('#saw-front-notes').val()
        }).done(function (response) {
            var data;
            if (!response.success) {
                setMessage('#saw-front-message', response.data && response.data.message ? response.data.message : '生成失败。', true);
                return;
            }
            data = response.data;
            fillTitles(data.title_options || [], data.recommended_title || '');
            $('#saw-front-content').html(safePreview(data.content_html || ''));
            $('#saw-front-preview').html(safePreview(data.content_html || ''));
            $('#saw-front-result').prop('hidden', false);
            renderStats(data);
            renderWarnings(data.warnings || []);
            setMessage('#saw-front-message', '文章已生成，请检查后填入投稿编辑器。', false);
        }).fail(function (xhr) {
            var response = xhr.responseJSON;
            setMessage('#saw-front-message', response && response.data && response.data.message ? response.data.message : '请求失败，请检查接口设置。', true);
        }).always(function () {
            setBusy(false);
        });
    });

    $(document).on('click', '#saw-front-title-refresh', function () {
        if (typeof sawFrontend === 'undefined') {
            return;
        }
        var button = $(this);
        var input = $.trim($('#saw-front-input').val());
        if (!input) {
            setMessage('#saw-front-message', '请先输入项目链接或资源名称。', true);
            return;
        }
        button.prop('disabled', true);
        setMessage('#saw-front-message', '正在换一批标题…', false);
        $.post(sawFrontend.ajaxUrl, {
            action: 'saw_regenerate_titles',
            nonce: sawFrontend.nonce,
            input: input,
            type: $('#saw-front-type').val(),
            notes: $('#saw-front-notes').val(),
            count: sawFrontend.titleCount || 5
        }).done(function (response) {
            if (!response.success) {
                setMessage('#saw-front-message', response.data && response.data.message ? response.data.message : '生成标题失败。', true);
                return;
            }
            fillTitles(response.data.title_options || [], response.data.recommended_title || '');
            renderWarnings(response.data.warnings || []);
            setMessage('#saw-front-message', '已换一批标题，正文保持不变。', false);
        }).fail(function (xhr) {
            var response = xhr.responseJSON;
            setMessage('#saw-front-message', response && response.data && response.data.message ? response.data.message : '生成标题失败。', true);
        }).always(function () {
            button.prop('disabled', false);
        });
    });

    $(document).on('change', '#saw-front-titles', function () {
        $('#saw-front-title').val($(this).val());
    });

    $(document).on('input', '#saw-front-content', function () {
        $('#saw-front-preview').html(safePreview($(this).html()));
    });

    $(document).on('click', '#saw-front-preview-toggle', function () {
        var preview = $('#saw-front-preview');
        var willShow = preview.prop('hidden');
        preview.prop('hidden', !willShow);
        $(this).attr('aria-expanded', willShow ? 'true' : 'false').text(willShow ? '收起预览' : '预览排版');
    });

    $(document).on('click', '#saw-front-fill', function () {
        var titleDone = fillTitle($('#saw-front-title').val());
        var contentDone = fillContent($('#saw-front-content').html());
        if (titleDone && contentDone) {
            setMessage('#saw-front-fill-message', '标题和正文已填入投稿编辑器，请检查后提交。', false);
            return;
        }
        if (!titleDone && !contentDone) {
            setMessage('#saw-front-fill-message', '未找到标题框和正文编辑器，请在插件设置中填写对应的 CSS 选择器。', true);
            return;
        }
        setMessage('#saw-front-fill-message', titleDone ? '标题已填入，但未找到正文编辑器。' : '正文已填入，但未找到标题框。', true);
    });

    $(function () {
        var observer;
        if (placeLauncherInSidebar()) {
            return;
        }
        observer = new MutationObserver(function () {
            if (placeLauncherInSidebar()) {
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        window.setTimeout(function () {
            observer.disconnect();
        }, 15000);
    });
})(jQuery);
