<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$base_url = rest_url( APB_REST_NAMESPACE );
?>

<div class="wrap apb-docs-wrap">
    <h1 class="apb-page-title">📡 API 文档</h1>
    <p class="apb-page-desc">REST API 接口参考指南,供 Worker 集成使用</p>

    <div class="apb-doc-section">
        <div class="apb-doc-header">
            <span class="apb-doc-icon">🔧</span>
            <h2>基础信息</h2>
        </div>
        <table class="form-table">
            <tr>
                <th>Namespace</th>
                <td><code><?php echo esc_html( APB_REST_NAMESPACE ); ?></code></td>
            </tr>
            <tr>
                <th>基础 URL</th>
                <td><code><?php echo esc_url( $base_url ); ?></code></td>
            </tr>
            <tr>
                <th>认证方式</th>
                <td>Header 传递 Token: <code>X-APB-Token: &lt;shared_secret&gt;</code></td>
            </tr>
        </table>
    </div>

    <div class="apb-section-title">
        <span class="apb-section-icon">🔌</span>
        <h2>接口列表</h2>
    </div>

    <div class="apb-api-card">
        <div class="apb-api-header">
            <span class="apb-method get">GET</span>
            <code class="apb-endpoint">/categories</code>
        </div>
        <p>获取所有分类(排除"未分类"),包含分类的元数据配置.</p>

        <h4>响应字段</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>字段</th>
                    <th>类型</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr><td><code>id</code></td><td>int</td><td>分类 ID</td></tr>
                <tr><td><code>name</code></td><td>string</td><td>分类名称</td></tr>
                <tr><td><code>slug</code></td><td>string</td><td>分类别名</td></tr>
                <tr><td><code>count</code></td><td>int</td><td>文章数量</td></tr>
                <tr><td><code>prompt_append</code></td><td>string</td><td>附加提示词</td></tr>
                <tr><td><code>topic_keywords</code></td><td>string</td><td>主题关键词</td></tr>
                <tr><td><code>topic_priority</code></td><td>int</td><td>主题优先级</td></tr>
                <tr><td><code>source_path</code></td><td>string</td><td>本地文档源路径</td></tr>
                <tr><td><code>system_prompt</code></td><td>string</td><td>分类专属系统提示词</td></tr>
                <tr><td><code>generator</code></td><td>string</td><td>生成器类型: generic 或 kernel</td></tr>
                <tr><td><code>source_map</code></td><td>string</td><td>源映射 JSON</td></tr>
            </tbody>
        </table>

        <h4>响应示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "success": true,
  "data": [
    {
      "id": 2,
      "name": "技术教程",
      "slug": "tech-tutorials",
      "count": 15,
      "prompt_append": "请使用通俗易懂的语言...",
      "topic_keywords": "教程, 编程, 开发",
      "topic_priority": 10,
      "source_path": "/docs/tutorials",
      "system_prompt": "你是一位技术写作专家...",
      "generator": "generic",
      "source_map": "{\"mapping\": [...]}"
    }
  ],
  "message": ""
}</code></pre>
    </div>

    <div class="apb-api-card">
        <div class="apb-api-header">
            <span class="apb-method get">GET</span>
            <code class="apb-endpoint">/config</code>
        </div>
        <p>获取 Worker 所需的系统配置信息.</p>

        <h4>响应字段</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>字段</th>
                    <th>类型</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr><td><code>default_post_status</code></td><td>string</td><td>默认文章状态: draft/publish/pending</td></tr>
                <tr><td><code>default_post_author</code></td><td>int</td><td>默认文章作者 ID</td></tr>
                <tr><td><code>default_category</code></td><td>int</td><td>默认分类 ID</td></tr>
                <tr><td><code>site_tone</code></td><td>string</td><td>站点语调风格</td></tr>
                <tr><td><code>worker_pull_enabled</code></td><td>bool</td><td>Worker 拉取是否启用</td></tr>
                <tr><td><code>ai_api_key</code></td><td>string</td><td>AI API 密钥</td></tr>
                <tr><td><code>ai_base_url</code></td><td>string</td><td>AI API 基础 URL</td></tr>
                <tr><td><code>ai_model</code></td><td>string</td><td>AI 模型名称</td></tr>
                <tr><td><code>retry_multiplier</code></td><td>int</td><td>重试间隔倍数(秒)</td></tr>
                <tr><td><code>default_system_prompt</code></td><td>string</td><td>默认系统提示词</td></tr>
                <tr><td><code>topic_system_prompt</code></td><td>string</td><td>主题生成系统提示词</td></tr>
                <tr><td><code>slug_system_prompt</code></td><td>string</td><td>别名生成系统提示词</td></tr>
                <tr><td><code>title_similarity_threshold</code></td><td>float</td><td>标题相似度阈值 (0-1)</td></tr>
                <tr><td><code>max_quality_retries</code></td><td>int</td><td>最大质量重试次数</td></tr>
                <tr><td><code>max_slug_retries</code></td><td>int</td><td>最大别名重试次数</td></tr>
                <tr><td><code>min_article_words</code></td><td>int</td><td>文章最小字数</td></tr>
                <tr><td><code>code_lines_per_segment</code></td><td>int</td><td>代码块每段行数</td></tr>
                <tr><td><code>code_max_segments</code></td><td>int</td><td>代码块最大段数</td></tr>
                <tr><td><code>article_prompt_template</code></td><td>string</td><td>文章生成提示模板</td></tr>
                <tr><td><code>kernel_prompt_template</code></td><td>string</td><td>内核文章生成提示模板</td></tr>
                <tr><td><code>kernel_max_total_segments</code></td><td>int</td><td>内核文章最大总段数</td></tr>
                <tr><td><code>max_tokens_article</code></td><td>int</td><td>文章生成最大 tokens</td></tr>
                <tr><td><code>max_tokens_topic</code></td><td>int</td><td>主题生成最大 tokens</td></tr>
            </tbody>
        </table>

        <h4>响应示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "success": true,
  "data": {
    "default_post_status": "draft",
    "default_post_author": 1,
    "default_category": 2,
    "site_tone": "professional",
    "worker_pull_enabled": true,
    "ai_api_key": "sk-...",
    "ai_base_url": "https://api.example.com",
    "ai_model": "gpt-4",
    "retry_multiplier": 30,
    "default_system_prompt": "你是一位专业的内容创作者...",
    "title_similarity_threshold": 0.55,
    "max_quality_retries": 2,
    "min_article_words": 1500,
    "max_tokens_article": 8192
  },
  "message": ""
}</code></pre>
    </div>

    <div class="apb-api-card">
        <div class="apb-api-header">
            <span class="apb-method get">GET</span>
            <code class="apb-endpoint">/jobs</code>
        </div>
        <p>获取指定状态的任务列表.</p>

        <h4>Query 参数</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>参数</th>
                    <th>类型</th>
                    <th>必填</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>status</code></td>
                    <td>string</td>
                    <td>否</td>
                    <td>状态筛选,默认: <code>pending</code><br>可选: pending, processing, completed, failed, published</td>
                </tr>
                <tr>
                    <td><code>limit</code></td>
                    <td>int</td>
                    <td>否</td>
                    <td>返回数量,默认: 20,最大: 100</td>
                </tr>
            </tbody>
        </table>

        <h4>响应字段(单个任务)</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>字段</th>
                    <th>类型</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr><td><code>id</code></td><td>int</td><td>任务 ID</td></tr>
                <tr><td><code>topic</code></td><td>string</td><td>文章主题</td></tr>
                <tr><td><code>keywords</code></td><td>string</td><td>关键词</td></tr>
                <tr><td><code>site_profile</code></td><td>string</td><td>站点简介</td></tr>
                <tr><td><code>category_id</code></td><td>int</td><td>分类 ID</td></tr>
                <tr><td><code>status</code></td><td>string</td><td>任务状态</td></tr>
                <tr><td><code>generated_title</code></td><td>string</td><td>生成的标题</td></tr>
                <tr><td><code>generated_excerpt</code></td><td>string</td><td>生成的摘要</td></tr>
                <tr><td><code>generated_html</code></td><td>string</td><td>生成的 HTML 内容</td></tr>
                <tr><td><code>generated_json</code></td><td>string</td><td>生成的原始 JSON</td></tr>
                <tr><td><code>wp_post_id</code></td><td>int</td><td>关联的 WordPress 文章 ID</td></tr>
                <tr><td><code>error_message</code></td><td>string</td><td>错误信息</td></tr>
                <tr><td><code>created_at</code></td><td>string</td><td>创建时间</td></tr>
                <tr><td><code>updated_at</code></td><td>string</td><td>更新时间</td></tr>
                <tr><td><code>processed_at</code></td><td>string</td><td>处理完成时间</td></tr>
            </tbody>
        </table>

        <h4>响应示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "success": true,
  "data": [
    {
      "id": 123,
      "topic": "WordPress REST API 开发指南",
      "keywords": "WordPress, API, 开发",
      "site_profile": "技术博客,专注于 WordPress 开发",
      "category_id": 2,
      "status": "pending",
      "created_at": "2026-03-29 10:00:00",
      "updated_at": "2026-03-29 10:00:00"
    }
  ],
  "message": ""
}</code></pre>
    </div>

    <div class="apb-api-card">
        <div class="apb-api-header">
            <span class="apb-method post">POST</span>
            <code class="apb-endpoint">/jobs</code>
        </div>
        <p>创建新的文章生成任务.</p>

        <h4>请求体</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>字段</th>
                    <th>类型</th>
                    <th>必填</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>topic</code></td>
                    <td>string</td>
                    <td><strong>是</strong></td>
                    <td>文章主题</td>
                </tr>
                <tr>
                    <td><code>keywords</code></td>
                    <td>string</td>
                    <td>否</td>
                    <td>关键词</td>
                </tr>
                <tr>
                    <td><code>site_profile</code></td>
                    <td>string</td>
                    <td>否</td>
                    <td>站点简介</td>
                </tr>
                <tr>
                    <td><code>category_id</code></td>
                    <td>int</td>
                    <td>否</td>
                    <td>分类 ID</td>
                </tr>
            </tbody>
        </table>

        <h4>请求示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "topic": "WordPress REST API 开发指南",
  "keywords": "WordPress, REST API, 开发教程",
  "site_profile": "技术博客,专注于 WordPress 和 PHP 开发",
  "category_id": 2
}</code></pre>

        <h4>响应示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "success": true,
  "data": {
    "id": 124
  },
  "message": "Job created."
}</code></pre>
    </div>

    <div class="apb-api-card">
        <div class="apb-api-header">
            <span class="apb-method post">POST</span>
            <code class="apb-endpoint">/jobs/{id}/claim</code>
        </div>
        <p>Worker 领取任务,状态从 <code>pending</code> 变为 <code>processing</code>.</p>

        <h4>路径参数</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>参数</th>
                    <th>类型</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>id</code></td>
                    <td>int</td>
                    <td>任务 ID(正整数)</td>
                </tr>
            </tbody>
        </table>

        <h4>响应示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "success": true,
  "data": {
    "id": 123,
    "topic": "WordPress REST API 开发指南",
    "status": "processing",
    ...
  },
  "message": "Job claimed."
}</code></pre>

        <h4>错误响应</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>状态码</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>404</td><td>任务不存在</td></tr>
                <tr><td>409</td><td>任务状态不是 pending,无法领取</td></tr>
            </tbody>
        </table>
    </div>

    <div class="apb-api-card">
        <div class="apb-api-header">
            <span class="apb-method post">POST</span>
            <code class="apb-endpoint">/jobs/{id}/complete</code>
        </div>
        <p>Worker 提交生成的内容,创建 WordPress 文章,状态变为 <code>completed</code> 或 <code>published</code>.</p>

        <h4>路径参数</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>参数</th>
                    <th>类型</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>id</code></td>
                    <td>int</td>
                    <td>任务 ID(正整数)</td>
                </tr>
            </tbody>
        </table>

        <h4>请求体</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>字段</th>
                    <th>类型</th>
                    <th>必填</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>generated_title</code></td>
                    <td>string</td>
                    <td><strong>是</strong></td>
                    <td>生成的文章标题</td>
                </tr>
                <tr>
                    <td><code>generated_html</code></td>
                    <td>string</td>
                    <td><strong>是</strong></td>
                    <td>生成的文章 HTML 内容</td>
                </tr>
                <tr>
                    <td><code>generated_excerpt</code></td>
                    <td>string</td>
                    <td>否</td>
                    <td>生成的文章摘要</td>
                </tr>
                <tr>
                    <td><code>generated_json</code></td>
                    <td>string</td>
                    <td>否</td>
                    <td>生成的原始 JSON 数据</td>
                </tr>
                <tr>
                    <td><code>post_slug</code></td>
                    <td>string</td>
                    <td>否</td>
                    <td>自定义文章别名(英文)</td>
                </tr>
                <tr>
                    <td><code>category_id</code></td>
                    <td>int</td>
                    <td>否</td>
                    <td>Worker 重新分类的分类 ID</td>
                </tr>
            </tbody>
        </table>

        <h4>请求示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "generated_title": "WordPress REST API 完整开发指南",
  "generated_html": "&lt;h2&gt;什么是 REST API&lt;/h2&gt;&lt;p&gt;...&lt;/p&gt;",
  "generated_excerpt": "本文详细介绍 WordPress REST API 的开发方法...",
  "generated_json": "{\"title\": \"...\", \"content\": \"...\"}",
  "post_slug": "wordpress-rest-api-development-guide",
  "category_id": 3
}</code></pre>

        <h4>响应示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "success": true,
  "data": {
    "id": 123,
    "status": "published",
    "wp_post_id": 456,
    "generated_title": "WordPress REST API 完整开发指南",
    ...
  },
  "message": "Job completed and post created."
}</code></pre>

        <h4>错误响应</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>状态码</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>400</td><td>缺少必填字段(title 或 html)</td></tr>
                <tr><td>404</td><td>任务不存在</td></tr>
                <tr><td>409</td><td>任务状态不是 processing</td></tr>
                <tr><td>500</td><td>创建 WordPress 文章失败</td></tr>
            </tbody>
        </table>
    </div>

    <div class="apb-api-card">
        <div class="apb-api-header">
            <span class="apb-method post">POST</span>
            <code class="apb-endpoint">/jobs/{id}/fail</code>
        </div>
        <p>Worker 报告任务执行失败,状态变为 <code>failed</code>.</p>

        <h4>路径参数</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>参数</th>
                    <th>类型</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>id</code></td>
                    <td>int</td>
                    <td>任务 ID(正整数)</td>
                </tr>
            </tbody>
        </table>

        <h4>请求体</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>字段</th>
                    <th>类型</th>
                    <th>必填</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>error_message</code></td>
                    <td>string</td>
                    <td>否</td>
                    <td>错误描述信息(默认: "Unknown error.")</td>
                </tr>
            </tbody>
        </table>

        <h4>请求示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "error_message": "AI API 调用超时,请稍后重试"
}</code></pre>

        <h4>响应示例</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "success": true,
  "data": {
    "id": 123,
    "status": "failed",
    "error_message": "AI API 调用超时,请稍后重试"
  },
  "message": "Job marked as failed."
}</code></pre>
    </div>

    <div class="apb-api-card">
        <div class="apb-doc-header">
            <span class="apb-doc-icon">📋</span>
            <h2>通用响应格式</h2>
        </div>
        <p>所有 API 响应均遵循以下统一格式:</p>

        <h4>成功响应</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "success": true,
  "data": { ... },
  "message": ""
}</code></pre>

        <h4>错误响应</h4>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>{
  "success": false,
  "data": null,
  "message": "错误描述信息"
}</code></pre>

        <h4>HTTP 状态码</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>状态码</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>200</td><td>成功</td></tr>
                <tr><td>201</td><td>创建成功</td></tr>
                <tr><td>400</td><td>请求参数错误</td></tr>
                <tr><td>401</td><td>认证失败(Token 无效或缺失)</td></tr>
                <tr><td>403</td><td>权限不足(如 Worker 拉取被禁用)</td></tr>
                <tr><td>404</td><td>资源不存在</td></tr>
                <tr><td>409</td><td>状态冲突(如任务已被领取)</td></tr>
                <tr><td>500</td><td>服务器内部错误</td></tr>
            </tbody>
        </table>
    </div>

    <div class="apb-api-card">
        <div class="apb-doc-header">
            <span class="apb-doc-icon">🔄</span>
            <h2>任务状态流转</h2>
        </div>
        <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;"><code>┌─────────┐    claim     ┌─────────────┐   complete   ┌───────────┐
│ pending │ ───────────► │ processing  │ ───────────► │ completed │
│ (待处理) │              │  (处理中)    │              │  (已完成)  │
└─────────┘              └─────────────┘              └─────┬─────┘
      │                           │                         │
      │                           │ fail                    │ publish
      │                           ▼                         ▼
      │                    ┌─────────┐               ┌───────────┐
      └──────────────────► │ failed  │               │ published │
        (reset)            │ (失败)   │               │  (已发布)  │
                           └────┬────┘               └───────────┘
                                │
                                ▼
                           (pending)
                           重置后重新处理</code></pre>

        <table class="widefat striped" style="margin-top: 15px;">
            <thead>
                <tr>
                    <th>状态</th>
                    <th>说明</th>
                    <th>可执行操作</th>
                </tr>
            </thead>
            <tbody>
                <tr><td><code>pending</code></td><td>待处理</td><td>claim(领取)</td></tr>
                <tr><td><code>processing</code></td><td>处理中</td><td>complete(完成),fail(失败)</td></tr>
                <tr><td><code>completed</code></td><td>已完成(草稿)</td><td>-</td></tr>
                <tr><td><code>published</code></td><td>已发布</td><td>-</td></tr>
                <tr><td><code>failed</code></td><td>失败</td><td>reset(重置)</td></tr>
            </tbody>
        </table>
    </div>

    <style>
        .apb-docs-wrap {
            max-width: 100%;
            box-sizing: border-box;
        }
        
        .apb-section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 30px 0 20px 0;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .apb-section-title h2 {
            margin: 0;
            font-size: 20px;
            color: #1f2937;
            font-weight: 600;
        }
        
        .apb-section-icon {
            font-size: 24px;
        }
        
        .apb-doc-section {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid #e5e7eb;
        }
        
        .apb-doc-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .apb-doc-header h2 {
            margin: 0;
            font-size: 18px;
            color: #1f2937;
            font-weight: 600;
        }
        
        .apb-doc-icon {
            font-size: 24px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            border-radius: 10px;
        }
        
        .apb-api-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s;
        }
        
        .apb-api-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-color: #c7d2fe;
        }
        
        .apb-api-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .apb-method {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            color: white;
            min-width: 60px;
            letter-spacing: 0.5px;
        }
        
        .apb-method.get { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .apb-method.post { background: linear-gradient(135deg, #10b981, #059669); }
        .apb-method.put { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .apb-method.delete { background: linear-gradient(135deg, #ef4444, #dc2626); }
        
        .apb-endpoint {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            background: #f3f4f6;
            padding: 6px 14px;
            border-radius: 6px;
            font-family: 'SF Mono', Monaco, monospace;
        }
        
        .apb-api-card h4 {
            margin: 25px 0 12px 0;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .apb-api-card h4::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 16px;
            background: linear-gradient(180deg, #667eea, #764ba2);
            border-radius: 2px;
        }
        
        .apb-api-card p {
            color: #6b7280;
            line-height: 1.7;
            margin-bottom: 15px;
        }
        
        .apb-api-card pre {
            background: #f8fafc;
            color: #1e293b;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'SF Mono', Monaco, monospace;
            font-size: 13px;
            line-height: 1.6;
            border: 1px solid #e2e8f0;
            margin: 15px 0;
        }
        
        .apb-api-card pre code {
            background: transparent;
            color: #1e293b;
            padding: 0;
            font-size: inherit;
        }
        
        .apb-api-card code {
            background: #f1f5f9;
            color: #334155;
            padding: 3px 8px;
            border-radius: 4px;
            font-family: 'SF Mono', Monaco, monospace;
            font-size: 12px;
            font-weight: 500;
        }
        
        .apb-page-title {
            font-size: 22px;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 8px 0;
        }
        
        .apb-page-desc {
            color: #6b7280;
            margin: 0 0 20px 0;
            font-size: 13px;
        }
        
        .apb-api-card .widefat {
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }
        
        .apb-api-card .widefat thead {
            background: #f8fafc;
        }
        
        .apb-api-card .widefat thead th {
            font-weight: 600;
            color: #374151;
            padding: 12px 15px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .apb-api-card .widefat tbody td {
            padding: 12px 15px;
            color: #4b5563;
        }
        
        .apb-api-card .widefat tbody tr:hover {
            background: #f8fafc;
        }
        
        .apb-api-card .widefat.striped tbody tr:nth-child(odd) {
            background: #f9fafb;
        }
        
        .apb-api-card .widefat.striped tbody tr:nth-child(odd):hover {
            background: #f3f4f6;
        }
        
        .apb-api-card pre code {
            white-space: pre;
        }
        
        @media (max-width: 782px) {
            .apb-api-header {
                flex-wrap: wrap;
            }
            
            .apb-method {
                min-width: 50px;
                padding: 5px 10px;
            }
            
            .apb-endpoint {
                font-size: 14px;
                word-break: break-all;
            }
            
            .apb-api-card pre {
                padding: 15px;
                font-size: 12px;
            }
            
            .apb-api-card .widefat {
                font-size: 12px;
            }
        }
    </style>
</div>
