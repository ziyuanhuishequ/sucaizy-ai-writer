# 参与贡献

欢迎提 issue、改代码、补文档。这个插件的目标很具体：把资源介绍类文章的资料整理和格式校验做扎实，剩下的判断留给作者。保持这个边界，改动就容易被接受。

## 报告问题

开 issue 前先确认：

1. 插件版本、WordPress 版本、PHP 版本（在「素材 AI 写作 → 接口设置 → 运行环境自检」可以直接看到）
2. 有没有在 `wp-content/debug.log` 里留下 `saw_` 开头的报错
3. 是「生成失败」「生成成功但内容不对」还是「界面无反应」——这三类的排查路径完全不同

**贴日志前把 API Key、域名和文章内容删掉。**

## 提交代码

```bash
git clone https://github.com/ziyuanhuishequ/sucaizy-ai-writer.git
cd sucaizy-ai-writer
# 软链或复制到 wp-content/plugins/sucaizy-ai-writer 进行调试
```

改动前先做语法检查，别让 CI 替你发现笔误：

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

### 代码风格

- 4 空格缩进，不用 Tab
- 保持 **PHP 7.2** 语法兼容：不用箭头函数、`match`、构造器属性提升、命名参数、`??=`
- 输出一律转义：`esc_html()`、`esc_attr()`、`esc_url()`，富文本用 `wp_kses_post()`
- 新增 AJAX 动作必须同时有 `check_ajax_referer()` 和 `current_user_can('manage_options')`
- 中文字符串长度一律走 `SAW_Quality::len()` / `cut()`，**不要**直接用 `strlen()` 或 `substr()`，那会按字节切出半个汉字
- 改动用户可见的文案时，同步更新 `readme.txt` 和 `CHANGELOG.md`

### 提交信息

```
fix: 修复 GBK 页面标题乱码
feat: 支持自定义生成温度
docs: 补充 PHP 8.3 安装说明
```

正文里说清楚**为什么改**，而不是复述改了哪几行。

### Pull Request

- 一个 PR 只做一件事，顺手重构请单独提
- 涉及生成效果的改动，附一段修改前后对比（标题 / 特点各一例即可）
- 涉及抓取逻辑的改动，说明测试过的站点类型（UTF-8 / GBK / 无 meta charset）

## 贡献许可

提交即表示你同意以 [GPL-2.0-or-later](LICENSE) 授权你的贡献，并确认代码为你本人原创或来自 GPL 兼容的项目。
