<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APB_Post_Publisher {

    public function publish( object $job ): int|false {
        $settings = $this->get_settings();

        $content = $job->generated_html ?? '';
        $content = $this->convert_code_blocks_to_shortcodes( $content );
        $content = $this->fix_code_in_paragraphs( $content );

        $post_data = array(
            'post_title'   => $job->generated_title ?? '',
            'post_content' => $content,
            'post_excerpt' => $job->generated_excerpt ?? '',
            'post_status'  => $settings['default_post_status'] ?: 'draft',
            'post_type'    => 'post',
        );

        if ( ! empty( $job->post_slug ) ) {
            $post_data['post_name'] = sanitize_title( $job->post_slug );
        }

        $author_id = (int) ( $settings['default_post_author'] ?? 0 );
        if ( $author_id && get_user_by( 'id', $author_id ) ) {
            $post_data['post_author'] = $author_id;
        }

        // 任务带分类就用,没有就兜底全局默认
        $category_id = (int) ( $job->category_id ?? 0 );
        if ( ! $category_id ) {
            $category_id = (int) ( $settings['default_category'] ?? 0 );
        }
        if ( $category_id && term_exists( $category_id, 'category' ) ) {
            $post_data['post_category'] = array( $category_id );
        }

        // 拟人化:过去的随机时间
        if ( ! empty( $job->post_date ) ) {
            $dt = date_create( $job->post_date, wp_timezone() );
            if ( $dt && $dt->getTimestamp() <= current_time( 'timestamp' ) ) {
                $post_data['post_date']     = $dt->format( 'Y-m-d H:i:s' );
                $post_data['post_date_gmt'] = get_gmt_from_date( $post_data['post_date'] );
                // 过去的时间不能挂 future 状态
                if ( $post_data['post_status'] === 'future' ) {
                    $post_data['post_status'] = 'publish';
                }
            }
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return false;
        }

        // 不加这个 meta SyntaxHighlighter 会双重编码,页面直接炸
        update_post_meta( $post_id, '_syntaxhighlighter_encoded', '1' );

        return (int) $post_id;
    }

    private function get_settings(): array {
        $saved   = get_option( APB_OPTION_KEY, array() );
        $defaults = array(
            'default_post_status'  => 'draft',
            'default_post_author'  => '',
            'default_category'     => '',
        );

        return wp_parse_args( $saved, $defaults );
    }

    private function convert_code_blocks_to_shortcodes( string $html ): string {
        $pattern = '/<pre>\s*<code(?:\s+class=[\'"](?:language-)?([a-zA-Z0-9_+-]*)[\'"])?\s*>(.*?)<\/code>\s*<\/pre>/si';

        return preg_replace_callback( $pattern, function( $matches ) {
            $lang = ! empty( $matches[1] ) ? strtolower( $matches[1] ) : 'plain';
            $code = $matches[2];

            // 先解码再重编,免得被 wp_kses_post 吃掉尖括号
            // 前面那个 meta 会让 SyntaxHighlighter 别再编一次
            $code = html_entity_decode( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $code = esc_html( $code );

            $lang = $this->map_syntax_language( $lang );

            return '[sourcecode language="' . esc_attr( $lang ) . '"]' . $code . '[/sourcecode]';
        }, $html );
    }

    private function map_syntax_language( string $lang ): string {
        $map = array(
            'c'          => 'c',
            'cpp'        => 'cpp',
            'csharp'     => 'csharp',
            'css'        => 'css',
            'go'         => 'go',
            'golang'     => 'go',
            'java'       => 'java',
            'javascript' => 'javascript',
            'js'         => 'javascript',
            'php'        => 'php',
            'python'     => 'python',
            'py'         => 'python',
            'ruby'       => 'ruby',
            'rb'         => 'ruby',
            'bash'       => 'bash',
            'shell'      => 'bash',
            'sh'         => 'bash',
            'sql'        => 'sql',
            'xml'        => 'xml',
            'html'       => 'html',
            'yaml'       => 'yaml',
            'yml'        => 'yaml',
            'perl'       => 'perl',
            'pl'         => 'perl',
            'swift'      => 'swift',
            'scala'      => 'scala',
            'haskell'    => 'haskell',
            'erlang'     => 'erlang',
            'diff'       => 'diff',
            'patch'      => 'diff',
            'plain'      => 'plain',
            'text'       => 'plain',
        );

        return $map[ $lang ] ?? 'plain';
    }

    // 万一 Worker 那边漏了,WordPress 端再兜底抓一把
    // AI 有时把代码塞进 <p> 里,检测出来修成 [sourcecode]
    private function fix_code_in_paragraphs( string $html ): string {
        $strong_re = '/^(?:'
            . '#\s*(?:include|define|ifdef|ifndef|endif|pragma|if|elif)\b'
            . '|(?:void|int|float|double|byte|char|long|unsigned|bool|auto|const|static|struct|class|enum)\s+\w+'
            . '|(?:return|break|continue)\b'
            . '|(?:if|else|for|while|do|switch|case)\s*[\({]'
            . '|(?:Wire|Serial|u8g2|SPI|EEPROM|WiFi|BLE|MPU|DHT|accelgyro)\b'
            . '|(?:delay|pinMode|digitalWrite|digitalRead|analogWrite|analogRead|millis)\s*\('
            . '|(?:U8G2_|SSD1306|MPU6050|I2Cdev)\b'
            . '|(?:int16_t|uint8_t|uint16_t|uint32_t|size_t|boolean)\b'
            . '|\}'
            . ')/';

        $weak_re = '/(?:;\s*$|\{\s*$|\}\s*$|\w+\.\w+\(|\/\/.*$)/';

        $self = $this;
        return preg_replace_callback(
            '/<p>(.*?)<\/p>/si',
            function( $match ) use ( $strong_re, $weak_re, $self ) {
                $content = $match[1];
                $raw_lines = preg_split('/<br\s*\/?>/i', $content);
                $lines = array();
                foreach ( $raw_lines as $l ) {
                    $clean = wp_strip_all_tags( $l );
                    $lines[] = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                }

                $non_empty = array_filter( $lines, function( $l ) { return trim( $l ) !== ''; } );
                if ( empty( $non_empty ) ) {
                    return $match[0];
                }

                $code_count = 0;
                foreach ( $non_empty as $l ) {
                    $stripped = trim( $l );
                    if ( $stripped === '' ) continue;
                    if ( preg_match( $strong_re, $stripped ) || preg_match( $weak_re, $stripped ) ) {
                        $code_count++;
                    }
                }

                $total = count( $non_empty );

                // 超过60%像代码就当它是代码
                if ( $total >= 2 && $code_count / $total >= 0.6 ) {
                    $code_text = implode( "\n", $lines );
                    $code_text = html_entity_decode( $code_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                    return "\n" . $self->build_sourcecode_block( $code_text, 'cpp' ) . "\n";
                }

                // 单行也判断下
                if ( $total === 1 ) {
                    $line = reset( $non_empty );
                    if ( preg_match( $strong_re, $line ) || preg_match( $weak_re, $line ) ) {
                        // 确认一下:得有分号或花括号结尾
                        $is_code_line = preg_match( '/[;{}]\s*$/', $line )
                            || ( preg_match( '/;\s*\/\//', $line ) && preg_match( '/[;{}]/', $line ) );
                        // 中文太多就不算代码了
                        $cn_count = preg_match_all( '/[\x{4e00}-\x{9fff}]/u', $line );
                        if ( $is_code_line && $cn_count < mb_strlen( $line ) * 0.4 ) {
                            $code_text = html_entity_decode( $line, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                            return "\n" . $self->build_sourcecode_block( $code_text, 'cpp' ) . "\n";
                        }
                    }
                }

                return $match[0];
            },
            $html
        );
    }

    private function build_sourcecode_block( string $code, string $lang ): string {
        $lang = $this->map_syntax_language( $lang );
        $code = esc_html( $code );
        return '[sourcecode language="' . esc_attr( $lang ) . '"]' . $code . '[/sourcecode]';
    }
}
