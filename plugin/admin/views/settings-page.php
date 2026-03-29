<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = get_option( APB_OPTION_KEY, array() );

$shared_secret       = esc_attr( $settings['shared_secret'] ?? '' );
$default_post_status = esc_attr( $settings['default_post_status'] ?? 'draft' );
$default_post_author = esc_attr( $settings['default_post_author'] ?? '' );
$default_category    = esc_attr( $settings['default_category'] ?? '' );
$site_tone           = esc_textarea( $settings['site_tone'] ?? '' );
$worker_pull_enabled = ! empty( $settings['worker_pull_enabled'] );
$ai_api_key          = esc_attr( $settings['ai_api_key'] ?? '' );
$ai_base_url         = esc_attr( $settings['ai_base_url'] ?? '' );
$ai_model            = esc_attr( $settings['ai_model'] ?? '' );
$retry_multiplier    = esc_attr( $settings['retry_multiplier'] ?? '30' );

$default_system_prompt    = esc_textarea( $settings['default_system_prompt'] ?? '' );
$topic_system_prompt      = esc_textarea( $settings['topic_system_prompt'] ?? '' );
$slug_system_prompt       = esc_textarea( $settings['slug_system_prompt'] ?? '' );

$title_similarity_threshold = esc_attr( $settings['title_similarity_threshold'] ?? '0.55' );
$max_quality_retries        = esc_attr( $settings['max_quality_retries'] ?? '2' );
$max_slug_retries           = esc_attr( $settings['max_slug_retries'] ?? '10' );
$min_article_words          = esc_attr( $settings['min_article_words'] ?? '1500' );
$code_lines_per_segment     = esc_attr( $settings['code_lines_per_segment'] ?? '40' );
$code_max_segments          = esc_attr( $settings['code_max_segments'] ?? '3' );

$article_prompt_template    = esc_textarea( $settings['article_prompt_template'] ?? '' );
$kernel_prompt_template     = esc_textarea( $settings['kernel_prompt_template'] ?? '' );
$kernel_max_total_segments  = esc_attr( $settings['kernel_max_total_segments'] ?? '5' );

$max_tokens_article         = esc_attr( $settings['max_tokens_article'] ?? '8192' );
$max_tokens_topic           = esc_attr( $settings['max_tokens_topic'] ?? '1024' );

$post_date_randomize          = ! empty( $settings['post_date_randomize'] );
$post_date_max_offset_days    = esc_attr( $settings['post_date_max_offset_days'] ?? '90' );
$persona_injection            = ! empty( $settings['persona_injection'] );
$half_width_punctuation       = ! empty( $settings['half_width_punctuation'] );
$typo_injection               = ! empty( $settings['typo_injection'] );
$typo_density                 = esc_attr( $settings['typo_density'] ?? '0.8' );
$max_article_words            = esc_attr( $settings['max_article_words'] ?? '2500' );
$category_balance_threshold   = esc_attr( $settings['category_balance_threshold'] ?? '0.6' );

$users      = get_users( array( 'who' => 'authors', 'orderby' => 'display_name' ) );
$categories = get_categories( array( 'hide_empty' => false ) );
?>

<div class="wrap apb-settings-wrap">
    <h1 class="apb-page-title">⚙️ 设置</h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'apb_settings_group' ); ?>

        <div class="apb-section">
            <div class="apb-section-header">
                <span class="apb-icon">🔐</span>
                <h2>基础配置</h2>
            </div>
            <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <label for="apb_shared_secret">共享密钥</label>
                </th>
                <td>
                    <input type="text"
                           id="apb_shared_secret"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[shared_secret]"
                           value="<?php echo $shared_secret; ?>"
                           class="regular-text"
                           autocomplete="off" />
                    <p class="description">
                        外部 Worker 调用 API 时的鉴权令牌,通过请求头 <code>X-APB-Token</code> 传递.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_default_post_status">默认文章状态</label>
                </th>
                <td>
                    <select id="apb_default_post_status"
                            name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[default_post_status]">
                        <option value="draft" <?php selected( $default_post_status, 'draft' ); ?>>草稿(draft)</option>
                        <option value="pending" <?php selected( $default_post_status, 'pending' ); ?>>待审核(pending)</option>
                        <option value="publish" <?php selected( $default_post_status, 'publish' ); ?>>已发布(publish)</option>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_default_post_author">默认作者</label>
                </th>
                <td>
                    <select id="apb_default_post_author"
                            name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[default_post_author]">
                        <option value="0">- 不指定 -</option>
                        <?php foreach ( $users as $u ) : ?>
                            <option value="<?php echo esc_attr( $u->ID ); ?>"
                                    <?php selected( $default_post_author, (string) $u->ID ); ?>>
                                <?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_default_category">默认分类</label>
                </th>
                <td>
                    <select id="apb_default_category"
                            name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[default_category]">
                        <option value="0">- 不指定 -</option>
                        <?php foreach ( $categories as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>"
                                    <?php selected( $default_category, (string) $cat->term_id ); ?>>
                                <?php echo esc_html( $cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_site_tone">站点风格 / 语气</label>
                </th>
                <td>
                    <textarea id="apb_site_tone"
                              name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[site_tone]"
                              rows="3"
                              class="large-text"><?php echo $site_tone; ?></textarea>
                    <p class="description">
                        描述本站的写作风格或语气,此信息会发送给 Worker 作为内容生成参考.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_ai_api_key">AI API Key</label>
                </th>
                <td>
                    <input type="text"
                           id="apb_ai_api_key"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[ai_api_key]"
                           value="<?php echo $ai_api_key; ?>"
                           class="regular-text"
                           autocomplete="off" />
                    <p class="description">
                        Worker 使用的 AI 服务 API Key.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_ai_base_url">AI Base URL</label>
                </th>
                <td>
                    <input type="text"
                           id="apb_ai_base_url"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[ai_base_url]"
                           value="<?php echo $ai_base_url; ?>"
                           class="regular-text"
                           placeholder="https://api.openai.com/v1" />
                    <p class="description">
                        AI 服务的 API 基础地址(OpenAI 兼容格式).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_ai_model">AI 模型</label>
                </th>
                <td>
                    <input type="text"
                           id="apb_ai_model"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[ai_model]"
                           value="<?php echo $ai_model; ?>"
                           class="regular-text"
                           placeholder="gpt-4o" />
                    <p class="description">
                        使用的 AI 模型名称.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_retry_multiplier">重试倍数</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_retry_multiplier"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[retry_multiplier]"
                           value="<?php echo $retry_multiplier; ?>"
                           min="1" max="999"
                           class="small-text" />
                    <p class="description">
                        自主模式最大尝试次数 = 发布数量 × 重试倍数.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">允许 Worker 拉取任务</th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[worker_pull_enabled]"
                               value="1"
                               <?php checked( $worker_pull_enabled ); ?> />
                        允许外部 Worker 通过 API 拉取待处理任务
                    </label>
                </td>
            </tr>

        </table>
        </div>

        <div class="apb-section">
            <div class="apb-section-header">
                <span class="apb-icon">⚡</span>
                <h2>高级参数</h2>
            </div>
            <p class="apb-section-desc">
                以下参数控制 Worker 的行为策略.留空则使用默认值.
            </p>

            <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <label for="apb_default_system_prompt">默认系统提示词</label>
                </th>
                <td>
                    <textarea id="apb_default_system_prompt"
                              name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[default_system_prompt]"
                              rows="5"
                              class="large-text"><?php echo $default_system_prompt; ?></textarea>
                    <p class="description">
                        全局默认系统提示词.分类无专属提示词时使用此项.留空则 Worker 使用极简兜底.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_topic_system_prompt">选题系统提示词</label>
                </th>
                <td>
                    <textarea id="apb_topic_system_prompt"
                              name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[topic_system_prompt]"
                              rows="5"
                              class="large-text"><?php echo $topic_system_prompt; ?></textarea>
                    <p class="description">
                        控制 AI 选题行为的系统提示词.留空则 Worker 使用内置默认.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_slug_system_prompt">Slug 系统提示词</label>
                </th>
                <td>
                    <textarea id="apb_slug_system_prompt"
                              name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[slug_system_prompt]"
                              rows="4"
                              class="large-text"><?php echo $slug_system_prompt; ?></textarea>
                    <p class="description">
                        控制 Slug 生成的系统提示词.留空则 Worker 使用内置默认.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_title_similarity_threshold">标题相似度阈值</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_title_similarity_threshold"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[title_similarity_threshold]"
                           value="<?php echo $title_similarity_threshold; ?>"
                           min="0" max="1" step="0.05"
                           class="small-text" />
                    <p class="description">
                        0~1 之间,超过此阈值的标题视为重复(默认 0.55).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_category_balance_threshold">分类均衡阈值</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_category_balance_threshold"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[category_balance_threshold]"
                           value="<?php echo $category_balance_threshold; ?>"
                           min="0" max="1" step="0.05"
                           class="small-text" />
                    <p class="description">
                        0~1 之间,当某个分类的文章数占总数比例超过此阈值时,自动选择其他分类(默认 0.6,即 60%).设为 1 或 0 可禁用此功能.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_max_quality_retries">质量重试次数</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_max_quality_retries"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[max_quality_retries]"
                           value="<?php echo $max_quality_retries; ?>"
                           min="1" max="10"
                           class="small-text" />
                    <p class="description">
                        文章质量检查不通过时的最大重试次数(默认 2).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_max_slug_retries">Slug 重试次数</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_max_slug_retries"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[max_slug_retries]"
                           value="<?php echo $max_slug_retries; ?>"
                           min="1" max="30"
                           class="small-text" />
                    <p class="description">
                        Slug 生成质量不达标时的最大重试次数(默认 10).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_min_article_words">最小文章字数</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_min_article_words"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[min_article_words]"
                           value="<?php echo $min_article_words; ?>"
                           min="500" max="10000"
                           class="small-text" />
                    <p class="description">
                        文章最低字数要求,用于提示词中告诉 AI(默认 1500,最小 500).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_code_lines_per_segment">每段代码行数</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_code_lines_per_segment"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[code_lines_per_segment]"
                           value="<?php echo $code_lines_per_segment; ?>"
                           min="5" max="200"
                           class="small-text" />
                    <p class="description">
                        源码分析时每段代码的行数(默认 40,最小 5).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_code_max_segments">最大代码段数</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_code_max_segments"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[code_max_segments]"
                           value="<?php echo $code_max_segments; ?>"
                           min="1" max="20"
                           class="small-text" />
                    <p class="description">
                        每个源文件最多提取的代码段数(默认 3,最小 1).
                    </p>
                </td>
            </tr>

        </table>
        </div>

        <div class="apb-section">
            <div class="apb-section-header">
                <span class="apb-icon">🎭</span>
                <h2>拟人化设置</h2>
            </div>
            <div class="apb-info-box">
                <p>以下设置让 AI 生成的文章更像人类写的博客.启用后 Worker 会在内容生成后进行后处理.</p>
            </div>

            <table class="form-table" role="presentation">

            <tr>
                <th scope="row">发布时间随机化</th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[post_date_randomize]"
                               value="1"
                               <?php checked( $post_date_randomize ); ?> />
                        启用随机发布时间(文章发布时间设为过去的随机时刻)
                    </label>
                    <p class="description">
                        模拟人类博客的发布节奏,文章不会在同一时间集中出现.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_post_date_max_offset_days">最大回溯天数</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_post_date_max_offset_days"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[post_date_max_offset_days]"
                           value="<?php echo $post_date_max_offset_days; ?>"
                           min="1" max="365"
                           class="small-text" /> 天
                    <p class="description">
                        发布时间最多往回推多少天(默认 90 天,约 3 个月).近期概率更高.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">人设变量注入</th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[persona_injection]"
                               value="1"
                               <?php checked( $persona_injection ); ?> />
                        启用人设变量(随机注入写作状态/情绪描述)
                    </label>
                    <p class="description">
                        每次生成时约 70% 概率随机注入一条写作状态(如"熬夜写的","刚喝完咖啡"等),
                        影响 AI 写作语气.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">英文标点转换</th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[half_width_punctuation]"
                               value="1"
                               <?php checked( $half_width_punctuation ); ?> />
                        将中文标点自动转为英文标点
                    </label>
                    <p class="description">
                        适合开发者博客风格--程序员日常输入法默认英文标点,开启后文章中的逗号,句号,
                        引号等全部转为英文半角(不影响代码块).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">错别字注入</th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[typo_injection]"
                               value="1"
                               <?php checked( $typo_injection ); ?> />
                        启用错别字注入(同音字/形近字随机替换)
                    </label>
                    <p class="description">
                        在文章中偶尔替换同音字(如"的"→"地","在"→"再"),模拟人类打字错误.不影响代码块.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_typo_density">错别字密度</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_typo_density"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[typo_density]"
                           value="<?php echo $typo_density; ?>"
                           min="0.1" max="10" step="0.1"
                           class="small-text" /> 个/千字
                    <p class="description">
                        每 1000 个中文字中出现几个错别字(默认 0.8,即约 1250 字 1 个错别字).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_max_article_words">最大文章字数</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_max_article_words"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[max_article_words]"
                           value="<?php echo $max_article_words; ?>"
                           min="500" max="10000"
                           class="small-text" />
                    <p class="description">
                        文章最大字数上限.配合"最小文章字数"使用,Worker 会在此范围内随机波动,
                        让每篇文章长度不同(默认 2500).
                    </p>
                </td>
            </tr>

            </table>
        </div>

        <div class="apb-section">
            <div class="apb-section-header">
                <span class="apb-icon">📝</span>
                <h2>文章生成模板</h2>
            </div>
            <div class="apb-info-box">
                <p>以下模板控制 Worker 生成文章时发送给 AI 的用户提示词.留空则 Worker 使用内置默认模板.</p>
                <p><strong>支持的变量:</strong></p>
                <div class="apb-tags">
                    <span class="apb-tag">{topic}</span>
                    <span class="apb-tag">{keywords}</span>
                    <span class="apb-tag">{category}</span>
                    <span class="apb-tag">{site_tone_line}</span>
                    <span class="apb-tag">{site_profile_line}</span>
                    <span class="apb-tag">{min_words}</span>
                    <span class="apb-tag">{segment_count}</span>
                    <span class="apb-tag">{code_sections}</span>
                </div>
            </div>

            <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <label for="apb_article_prompt_template">通用文章模板</label>
                </th>
                <td>
                    <textarea id="apb_article_prompt_template"
                              name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[article_prompt_template]"
                              rows="10"
                              class="large-text code"><?php echo $article_prompt_template; ?></textarea>
                    <p class="description">
                        通用文章生成的用户提示词模板.留空使用内置默认.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_kernel_prompt_template">内核源码分析模板</label>
                </th>
                <td>
                    <textarea id="apb_kernel_prompt_template"
                              name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[kernel_prompt_template]"
                              rows="10"
                              class="large-text code"><?php echo $kernel_prompt_template; ?></textarea>
                    <p class="description">
                        内核源码分析文章的用户提示词模板.留空使用内置默认.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_kernel_max_total_segments">内核最大代码段数</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_kernel_max_total_segments"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[kernel_max_total_segments]"
                           value="<?php echo $kernel_max_total_segments; ?>"
                           min="1" max="20"
                           class="small-text" />
                    <p class="description">
                        内核文章分析时最多使用的代码段总数(默认 5,最小 1).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_max_tokens_article">文章生成 max_tokens</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_max_tokens_article"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[max_tokens_article]"
                           value="<?php echo $max_tokens_article; ?>"
                           min="1024" max="65536"
                           class="small-text" />
                    <p class="description">
                        文章生成时的最大输出 token 数(默认 8192,最小 1024).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="apb_max_tokens_topic">选题生成 max_tokens</label>
                </th>
                <td>
                    <input type="number"
                           id="apb_max_tokens_topic"
                           name="<?php echo esc_attr( APB_OPTION_KEY ); ?>[max_tokens_topic]"
                           value="<?php echo $max_tokens_topic; ?>"
                           min="256" max="8192"
                           class="small-text" />
                    <p class="description">
                        选题生成时的最大输出 token 数(默认 1024,最小 256).
                    </p>
                </td>
            </tr>

        </table>
        </div>

        <div class="apb-section apb-submit-section">
            <?php submit_button( '💾 保存设置', 'primary', 'submit', false, array( 'class' => 'apb-btn-primary' ) ); ?>
        </div>
    </form>

    <div class="apb-section">
        <div class="apb-section-header">
            <span class="apb-icon">🏷️</span>
            <h2>分类配置</h2>
        </div>
        <div class="apb-info-box">
            <p>为每个分类配置 AI 写作提示词和主题自动检测规则.</p>
            <div class="apb-help-grid">
                <div class="apb-help-item">
                    <strong>检测关键词</strong>
                    <span>逗号分隔,Worker 根据这些关键词自动识别文章主题类型</span>
                </div>
                <div class="apb-help-item">
                    <strong>检测优先级</strong>
                    <span>数字越小优先级越高(先匹配),设为 0 则不参与自动检测</span>
                </div>
                <div class="apb-help-item">
                    <strong>生成器类型</strong>
                    <span><code>generic</code> 通用生成 / <code>kernel</code> 内核源码分析模式</span>
                </div>
                <div class="apb-help-item">
                    <strong>源码路径</strong>
                    <span>Worker 机器上的绝对路径,设置后该分类的文章会进行源码分析</span>
                </div>
                <div class="apb-help-item">
                    <strong>源码子目录映射</strong>
                    <span>JSON 格式,如 <code>{"进程":["kernel/"],"网络":["net/"]}</code></span>
                </div>
                <div class="apb-help-item">
                    <strong>系统提示词</strong>
                    <span>分类完整系统提示词(优先级最高),留空则使用全局默认</span>
                </div>
            </div>
        </div>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="apb_save_category_prompts" />
        <?php wp_nonce_field( 'apb_category_prompts', 'apb_cat_prompts_nonce' ); ?>

        <table class="widefat apb-cat-config-table">
            <thead>
                <tr>
                    <th style="width:100px;">分类</th>
                    <th style="width:130px;">检测关键词</th>
                    <th style="width:55px;">优先级</th>
                    <th style="width:80px;">生成器</th>
                    <th style="width:130px;">源码路径</th>
                    <th style="width:130px;">源码映射</th>
                    <th style="width:160px;">系统提示词</th>
                    <th style="width:160px;">追加提示词</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $uncategorized_id = (int) get_option( 'default_category', 1 );
            $all_cats = get_categories( array( 'hide_empty' => false, 'exclude' => $uncategorized_id ) );
            foreach ( $all_cats as $cat ) :
                $saved_prompt        = get_term_meta( $cat->term_id, 'apb_prompt_append', true );
                $saved_keywords      = get_term_meta( $cat->term_id, 'apb_topic_keywords', true );
                $saved_priority      = get_term_meta( $cat->term_id, 'apb_topic_priority', true );
                $saved_source_path   = get_term_meta( $cat->term_id, 'apb_source_path', true );
                $saved_system_prompt = get_term_meta( $cat->term_id, 'apb_system_prompt', true );
                $saved_generator     = get_term_meta( $cat->term_id, 'apb_generator', true ) ?: 'generic';
                $saved_source_map    = get_term_meta( $cat->term_id, 'apb_source_map', true );
            ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $cat->name ); ?></strong>
                        <br><span class="description" style="font-size:11px;">ID: <?php echo (int) $cat->term_id; ?></span>
                    </td>
                    <td>
                        <input type="text"
                               name="cat_keywords[<?php echo (int) $cat->term_id; ?>]"
                               value="<?php echo esc_attr( $saved_keywords ); ?>"
                               style="width:100%;"
                               placeholder="arduino,esp32,单片机,..." />
                    </td>
                    <td>
                        <input type="number"
                               name="cat_priority[<?php echo (int) $cat->term_id; ?>]"
                               value="<?php echo esc_attr( $saved_priority ?: '0' ); ?>"
                               min="0" max="9999"
                               style="width:60px;" />
                    </td>
                    <td>
                        <select name="cat_generator[<?php echo (int) $cat->term_id; ?>]"
                                style="width:100%;">
                            <option value="generic" <?php selected( $saved_generator, 'generic' ); ?>>generic</option>
                            <option value="kernel" <?php selected( $saved_generator, 'kernel' ); ?>>kernel</option>
                        </select>
                    </td>
                    <td>
                        <input type="text"
                               name="cat_source_path[<?php echo (int) $cat->term_id; ?>]"
                               value="<?php echo esc_attr( $saved_source_path ); ?>"
                               style="width:100%;font-family:monospace;font-size:12px;"
                               placeholder="/path/to/source" />
                    </td>
                    <td>
                        <textarea
                            name="cat_source_map[<?php echo (int) $cat->term_id; ?>]"
                            rows="3"
                            style="width:100%;font-family:monospace;font-size:12px;"
                            placeholder='{"进程":["kernel/"],"网络":["net/"]}'><?php echo esc_textarea( $saved_source_map ); ?></textarea>
                    </td>
                    <td class="apb-prompt-cell">
                        <textarea
                            name="cat_system_prompt[<?php echo (int) $cat->term_id; ?>]"
                            class="apb-prompt-hidden" style="display:none;"
                            placeholder="完整替换全局系统提示词(优先级最高)..."><?php echo esc_textarea( $saved_system_prompt ); ?></textarea>
                        <div class="apb-prompt-preview">
                            <?php if ( $saved_system_prompt ) : ?>
                                <?php echo esc_html( mb_substr( $saved_system_prompt, 0, 60 ) ); ?><?php if ( mb_strlen( $saved_system_prompt ) > 60 ) echo '...'; ?>
                            <?php else : ?>
                                <em class="apb-prompt-empty">未设置</em>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button button-small apb-prompt-edit-btn"
                                data-title="系统提示词 · <?php echo esc_attr( $cat->name ); ?>">编辑</button>
                    </td>
                    <td class="apb-prompt-cell">
                        <textarea
                            name="cat_prompts[<?php echo (int) $cat->term_id; ?>]"
                            class="apb-prompt-hidden" style="display:none;"
                            placeholder="追加到全局提示词末尾..."><?php echo esc_textarea( $saved_prompt ); ?></textarea>
                        <div class="apb-prompt-preview">
                            <?php if ( $saved_prompt ) : ?>
                                <?php echo esc_html( mb_substr( $saved_prompt, 0, 60 ) ); ?><?php if ( mb_strlen( $saved_prompt ) > 60 ) echo '...'; ?>
                            <?php else : ?>
                                <em class="apb-prompt-empty">未设置</em>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button button-small apb-prompt-edit-btn"
                                data-title="追加提示词 · <?php echo esc_attr( $cat->name ); ?>">编辑</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="apb-submit-section" style="margin-top:20px;">
            <button type="submit" class="apb-btn-primary">💾 保存分类配置</button>
        </div>
    </form>
    </div>

    <style>
    .apb-settings-wrap {
        max-width: 100%;
        box-sizing: border-box;
    }
    
    .apb-page-title {
        font-size: 22px;
        font-weight: 600;
        color: #1f2937;
        margin: 0 0 20px 0;
    }
    
    .apb-section {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        padding: 25px;
        margin-bottom: 25px;
        border: 1px solid #e5e7eb;
    }
    
    .apb-section-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f3f4f6;
    }
    
    .apb-section-header h2 {
        margin: 0;
        font-size: 18px;
        color: #1f2937;
        font-weight: 600;
    }
    
    .apb-icon {
        font-size: 24px;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f3f4f6;
        border-radius: 10px;
    }
    
    .apb-section-desc {
        color: #6b7280;
        margin: -10px 0 20px 52px;
        font-size: 13px;
    }
    
    .apb-info-box {
        background: #f8fafc;
        border-left: 4px solid #667eea;
        padding: 15px 20px;
        margin-bottom: 20px;
        border-radius: 0 8px 8px 0;
    }
    
    .apb-info-box p {
        margin: 0 0 10px 0;
        color: #4b5563;
    }
    
    .apb-info-box p:last-child {
        margin-bottom: 0;
    }
    
    .apb-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }
    
    .apb-tag {
        background: #e0e7ff;
        color: #4338ca;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-family: monospace;
        font-weight: 500;
    }
    
    .apb-help-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 12px;
        margin-top: 15px;
    }
    
    .apb-help-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .apb-help-item strong {
        color: #374151;
        font-size: 13px;
    }
    
    .apb-help-item span {
        color: #6b7280;
        font-size: 12px;
        line-height: 1.5;
    }
    
    .apb-help-item code {
        background: #e5e7eb;
        padding: 1px 4px;
        border-radius: 3px;
        font-size: 11px;
    }
    
    .apb-settings-wrap .form-table {
        margin-left: 0;
    }
    
    .apb-settings-wrap .form-table th {
        width: 200px;
        padding: 15px 10px 15px 0;
        color: #374151;
        font-weight: 500;
    }
    
    .apb-settings-wrap .form-table td {
        padding: 10px 0;
    }
    
    .apb-settings-wrap input[type="text"],
    .apb-settings-wrap input[type="number"],
    .apb-settings-wrap select,
    .apb-settings-wrap textarea {
        border-radius: 6px;
        border: 1px solid #d1d5db;
        padding: 8px 12px;
        transition: all 0.2s;
        max-width: 100%;
        box-sizing: border-box;
    }
    
    .apb-settings-wrap textarea {
        width: 100%;
        max-width: 600px;
    }
    
    .apb-settings-wrap input.regular-text {
        width: 100%;
        max-width: 400px;
    }
    
    .apb-settings-wrap input[type="number"] {
        width: 130px;
    }

    .apb-settings-wrap input[type="text"]:focus,
    .apb-settings-wrap input[type="number"]:focus,
    .apb-settings-wrap select:focus,
    .apb-settings-wrap textarea:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        outline: none;
    }
    
    .apb-settings-wrap textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    .apb-settings-wrap textarea.code {
        font-family: 'SF Mono', Monaco, monospace;
        font-size: 13px;
    }
    
    .apb-settings-wrap .description {
        color: #6b7280;
        font-size: 12px;
        margin-top: 6px;
        line-height: 1.5;
    }
    
    .apb-settings-wrap .description code {
        background: #f3f4f6;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 11px;
    }
    
    .apb-cat-config-table {
        table-layout: fixed;
        border-radius: 8px;
        overflow: hidden;
        width: 100%;
    }
    
    .apb-cat-config-table thead {
        background: #f9fafb;
    }
    
    .apb-cat-config-table thead th {
        font-weight: 600;
        color: #374151;
        padding: 12px;
        border-bottom: 2px solid #e5e7eb;
        font-size: 12px;
    }
    
    .apb-cat-config-table tbody td {
        padding: 12px;
        vertical-align: top;
    }
    
    .apb-cat-config-table tbody tr:hover {
        background: #f9fafb;
    }
    
    .apb-cat-config-table input,
    .apb-cat-config-table select,
    .apb-cat-config-table textarea {
        border-radius: 4px;
        border: 1px solid #d1d5db;
        padding: 6px 8px;
        font-size: 12px;
        width: 100%;
        box-sizing: border-box;
    }
    
    .apb-cat-config-table textarea {
        min-height: 60px;
        resize: vertical;
        font-family: inherit;
        line-height: 1.4;
    }
    
    .apb-cat-config-table select {
        height: 32px;
    }
    
    .apb-btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white !important;
        border: none !important;
        padding: 12px 30px !important;
        font-size: 14px !important;
        font-weight: 500 !important;
        border-radius: 8px !important;
        cursor: pointer !important;
        transition: all 0.2s !important;
        box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3) !important;
    }
    
    .apb-btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 15px rgba(102, 126, 234, 0.4) !important;
    }
    
    .apb-submit-section {
        text-align: center;
        padding: 30px !important;
    }
    
    @media (max-width: 782px) {
        .apb-settings-wrap .form-table {
            margin-left: 0;
        }
        
        .apb-settings-wrap .form-table th {
            width: auto;
            padding-bottom: 5px;
        }
        
        .apb-help-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>

    <div id="apb-prompt-modal" class="apb-modal-overlay" style="display:none;">
        <div class="apb-modal">
            <div class="apb-modal-header">
                <h3 id="apb-modal-title">编辑提示词</h3>
                <button type="button" class="apb-modal-close">&times;</button>
            </div>
            <div class="apb-modal-body">
                <textarea id="apb-modal-editor" rows="20"></textarea>
            </div>
            <div class="apb-modal-footer">
                <button type="button" class="button apb-modal-cancel">取消</button>
                <button type="button" class="button button-primary apb-modal-confirm">确认</button>
            </div>
        </div>
    </div>

    <style>
    .apb-prompt-cell { position: relative; }
    .apb-prompt-preview {
        font-size: 12px;
        color: #6b7280;
        line-height: 1.4;
        margin-bottom: 6px;
        min-height: 20px;
        word-break: break-all;
        max-height: 48px;
        overflow: hidden;
    }
    .apb-prompt-empty { color: #9ca3af; font-style: italic; }
    .apb-prompt-edit-btn { font-size: 12px !important; }

    .apb-modal-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 100000;
    }
    .apb-modal {
        background: #fff;
        border-radius: 12px;
        width: 720px;
        max-width: 90vw;
        max-height: 85vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    }
    .apb-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid #e5e7eb;
    }
    .apb-modal-header h3 {
        margin: 0;
        font-size: 16px;
        color: #1f2937;
    }
    .apb-modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #6b7280;
        padding: 0 4px;
        line-height: 1;
    }
    .apb-modal-close:hover { color: #1f2937; }
    .apb-modal-body {
        padding: 20px;
        flex: 1;
        overflow: auto;
    }
    .apb-modal-body textarea {
        width: 100%;
        min-height: 400px;
        font-family: inherit;
        font-size: 14px;
        line-height: 1.6;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 12px;
        resize: vertical;
        box-sizing: border-box;
    }
    .apb-modal-body textarea:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        outline: none;
    }
    .apb-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 12px 20px;
        border-top: 1px solid #e5e7eb;
    }
    </style>

    <script>
    (function() {
        var modal = document.getElementById('apb-prompt-modal');
        var editor = document.getElementById('apb-modal-editor');
        var titleEl = document.getElementById('apb-modal-title');
        var currentHidden = null;
        var currentPreview = null;

        function openModal(btn) {
            var cell = btn.closest('.apb-prompt-cell');
            currentHidden = cell.querySelector('.apb-prompt-hidden');
            currentPreview = cell.querySelector('.apb-prompt-preview');
            titleEl.textContent = btn.getAttribute('data-title');
            editor.value = currentHidden.value;
            modal.style.display = 'flex';
            editor.focus();
        }

        function closeModal() {
            modal.style.display = 'none';
            currentHidden = null;
            currentPreview = null;
        }

        function confirmModal() {
            if (!currentHidden) return;
            currentHidden.value = editor.value;
            var text = editor.value.trim();
            if (text) {
                var preview = text.length > 60 ? text.substring(0, 60) + '...' : text;
                currentPreview.textContent = preview;
            } else {
                currentPreview.innerHTML = '<em class="apb-prompt-empty">未设置</em>';
            }
            closeModal();
        }

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('apb-prompt-edit-btn')) {
                openModal(e.target);
            }
        });

        modal.querySelector('.apb-modal-close').addEventListener('click', closeModal);
        modal.querySelector('.apb-modal-cancel').addEventListener('click', closeModal);
        modal.querySelector('.apb-modal-confirm').addEventListener('click', confirmModal);
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
        });
    })();
    </script>

</div>
