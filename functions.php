<?php
/**
 * Astra Pointcard Child Theme functions and definitions
 * 複数のプラグインコードを統合したバージョン
 */

// 親テーマのスタイルシートを読み込む
add_action('wp_enqueue_scripts', 'astra_child_enqueue_styles');
function astra_child_enqueue_styles() {
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('child-style', get_stylesheet_directory_uri() . '/style.css', array('parent-style'));
}

// 子テーマのセットアップ
function astra_child_theme_setup() {
    // テーマサポート
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    
    // 画像ディレクトリの作成確認
    $upload_dir = wp_upload_dir();
    $image_dir = $upload_dir['basedir'] . '/pointcard-images';
    if (!file_exists($image_dir)) {
        wp_mkdir_p($image_dir);
    }
    
    // 管理者スタンプ用ディレクトリの設定
    $manager_stamps_dir = $upload_dir['basedir'] . '/manager-stamps/';
    if (!file_exists($manager_stamps_dir)) {
        wp_mkdir_p($manager_stamps_dir);
    }
}
add_action('after_setup_theme', 'astra_child_theme_setup');

// ポイントカード用カスタムポストタイプ（メニュー統合版）
function create_pointcard_post_type() {
    register_post_type('pointcard',
        array(
            'labels' => array(
                'name' => 'ポイントカード',
                'singular_name' => 'ポイントカード',
                'menu_name' => 'ポイントカード一覧',
                'all_items' => 'ポイントカード一覧'
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'pointcard-settings', // メインメニューではなくサブメニューとして表示
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'supports' => array('title')
        )
    );
}
add_action('init', 'create_pointcard_post_type');

// テスト用の簡易ショートコード
function pointcard_simple_test() {
    return '<p>ポイントカードテスト</p>';
}
add_shortcode('pointcard_test', 'pointcard_simple_test');

// ユーザー登録時のフック
function create_user_pointcard($user_id) {
    $user = get_userdata($user_id);
    
    // 新規ポイントカードの作成
    $post_id = wp_insert_post(array(
        'post_title'    => $user->user_email . 'のポイントカード',
        'post_status'   => 'publish',
        'post_type'     => 'pointcard'
    ));
    
    // ACFフィールドの更新
    update_field('user_id', $user_id, $post_id);
    update_field('email', $user->user_email, $post_id);
    update_field('points', 0, $post_id);
    update_field('last_used_date', date('Y-m-d H:i:s'), $post_id);
    update_field('point_history_json', '[]', $post_id);
    update_field('exchange_history_json', '[]', $post_id);
    
    // ユーザー固有のQRコードトークンを生成
    $token = wp_generate_password(32, false);
    update_user_meta($user_id, 'qr_point_token', $token);
}
add_action('user_register', 'create_user_pointcard');

// ポイント履歴をJSONから取得
function get_point_history($post_id) {
    $json = get_field('point_history_json', $post_id);
    if (empty($json)) {
        return array();
    }
    return json_decode($json, true) ?: array();
}

// 交換履歴をJSONから取得
function get_exchange_history($post_id) {
    $json = get_field('exchange_history_json', $post_id);
    if (empty($json)) {
        return array();
    }
    return json_decode($json, true) ?: array();
}

// ポイント履歴を追加
function add_point_history_entry($post_id, $entry) {
    $history = get_point_history($post_id);
    $history[] = $entry;
    update_field('point_history_json', json_encode($history), $post_id);
}

// 交換履歴を追加
function add_exchange_history_entry($post_id, $entry) {
    $history = get_exchange_history($post_id);
    $history[] = $entry;
    update_field('exchange_history_json', json_encode($history), $post_id);
}

// QRコード表示部分を生成する関数
/**
 * 修正2: QRコード表示部分の改善 - よりシンプルなリンク形式に
 */
function display_qr_code_section($user_id) {
    // ユーザー固有のトークンを取得
    $token = get_user_meta($user_id, 'qr_point_token', true);
    
    // トークンがなければ生成
    if (empty($token)) {
        $token = wp_generate_password(32, false);
        update_user_meta($user_id, 'qr_point_token', $token);
    }
    
    // ユーザーのメールアドレスを取得
    $user_info = get_userdata($user_id);
    $email = $user_info->user_email;
    
    // トップページを使用（シンプルなアプローチ）
    $admin_page_url = home_url('/');
    $qr_data = $admin_page_url . '?email_search=' . urlencode($email) . '&token=' . $token;
    
    $output = '<div class="qr-code-section">
        <h3>QRコード</h3>
        <p>店舗でポイント追加時に提示してください</p>
        <div id="qrcode"></div>
        <div class="qr-instructions">
            <p>QRコードをタップして拡大表示</p>
        </div>
        <script>
            // QRコード生成用スクリプトは別関数で定義
            document.addEventListener("DOMContentLoaded", function() {
                generateQRCode("' . esc_js($qr_data) . '");
            });
        </script>
    </div>';
    
    return $output;
}


// QRコード生成用JavaScriptを出力する関数
function get_qrcode_javascript() {
    // QRコード生成ライブラリを読み込み
    wp_enqueue_script('qrcode-lib', get_stylesheet_directory_uri() . '/assets/js/qrcode.min.js', array(), null, true);
    
    $output = '<script>
        function generateQRCode(data) {
            var qrcode = new QRCode(document.getElementById("qrcode"), {
                text: data,
                width: 200,
                height: 200,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
            
            // QRコードをクリック/タップで拡大表示
            document.getElementById("qrcode").addEventListener("click", function() {
                var qrImage = document.querySelector("#qrcode img");
                if (qrImage) {
                    qrImage.classList.toggle("enlarged");
                }
            });
        }
    </script>';
    
    return $output;
}

/**
 * 修正5: 管理者スタンプを表示する機能の改善
 */
/**
 * 変更2-1: スタンプ表示順を変更 - 最新のスタンプが後ろに表示されるように修正
 */
function get_admin_stamps_for_user($post_id) {
    $stamps_data = array();
    
    // JSONからポイント履歴を取得
    $point_history = get_point_history($post_id);
    
    // 履歴を日付順にソート（古いものから）- 変更点
    usort($point_history, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
    
    if (!empty($point_history)) {
        $stamp_index = 0;
        
        // 最新の10ポイント分だけ処理
        foreach ($point_history as $entry) {
            // ポイント数を取得
            $points = isset($entry['points']) ? intval($entry['points']) : 1;
            
            // スタンプ画像を取得 - 優先順位を明確に
            $stamp_url = '';
            
            // 1. 履歴内のスタンプURLがあればそれを使用（最優先）
            if (isset($entry['stamp_url']) && !empty($entry['stamp_url'])) {
                $stamp_url = $entry['stamp_url'];
            } 
            // 2. 管理者IDからスタンプURLを取得
            elseif (isset($entry['admin_id']) && !empty($entry['admin_id'])) {
                $stamp_url = get_stamp_image_for_admin($entry['admin_id']);
            }
            // 3. それでもなければデフォルトスタンプを使用
            else {
                $stamp_url = get_stylesheet_directory_uri() . '/assets/images/stamp.png';
            }
            
            // 各ポイントにスタンプ画像を割り当て
            for ($i = 0; $i < $points && $stamp_index < 10; $i++) {
                $stamps_data[$stamp_index] = $stamp_url;
                $stamp_index++;
            }
            
            // 10個達したらループ終了
            if ($stamp_index >= 10) {
                break;
            }
        }
    }
    
    return $stamps_data;
}



// ポイントカード表示ショートコード（交換履歴ベース版）
function pointcard_display_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>ポイントカードを表示するにはログインしてください。</p>';
    }
    
    $current_user = wp_get_current_user();
    $email = $current_user->user_email;
    
    // ポイントカード情報を取得
    $args = array(
        'post_type' => 'pointcard',
        'meta_query' => array(
            array(
                'key' => 'email',
                'value' => $email,
                'compare' => '='
            )
        )
    );
    
    $query = new WP_Query($args);
    
    if ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $points = get_field('points', $post_id);
        $last_used_date = get_field('last_used_date', $post_id);
        
        // 交換履歴を取得
        $exchange_history = get_exchange_history($post_id);
        $exchanged_count = count($exchange_history);
        
        // 現在の有効ポイント（交換済み分を除く）
        $available_points = $points - ($exchanged_count * 10);
        
        // 1年以上経過しているか確認
        $last_used_timestamp = strtotime($last_used_date);
        $one_year_ago = strtotime('-1 year');
        $is_expired = ($last_used_timestamp < $one_year_ago);
        
        if ($is_expired) {
            return '<div class="pointcard-expired">
                <h2>ポイントカード有効期限切れ</h2>
                <p>最終利用日から1年以上経過したため、ポイントは無効になりました。</p>
                <p>最終利用日: ' . date('Y年m月d日', $last_used_timestamp) . '</p>
            </div>';
        }
        
        // 有効なポイントカードを表示
        $output = '<div class="pointcard-container">';
        
        // QRコード表示部分を追加（QRコードプラグインの機能を統合）
        $output .= display_qr_code_section($current_user->ID);
        
        // 特典交換済みカードを表示
        if ($exchanged_count > 0) {
            $output .= '<div class="completed-cards">
                <h3>特典交換済みカード: ' . $exchanged_count . '枚</h3>';
            
            for ($i = 0; $i < $exchanged_count; $i++) {
                $output .= '<div class="card completed">
                    <div class="card-header">特典交換済</div>
                    <div class="stamps-container">';
                
                // 10個のスタンプを表示
                for ($j = 0; $j < 10; $j++) {
                    $output .= '<div class="stamp stamped">
                        <img src="' . get_stylesheet_directory_uri() . '/assets/images/stamp.png" alt="スタンプ">
                    </div>';
                }
                
                $output .= '</div></div>';
            }
            
            $output .= '</div>';
        }
        
        // スタンプ情報を取得（管理者スタンプ機能統合）
        $stamps_data = get_admin_stamps_for_user($post_id);
        $current_points = $available_points; // 利用可能なポイント
        
        // 現在進行中のカードを表示
        $output .= '<div class="current-card">
            <h3>現在のポイントカード</h3>
            <div class="card">
                <div class="stamps-container">';
        
        // 10個のスタンプ枠を表示
        for ($i = 0; $i < 10; $i++) {
            if ($i < $current_points) {
                // 管理者スタンプがあれば表示、なければデフォルト
                $stamp_url = (isset($stamps_data[$i])) ? $stamps_data[$i] : get_stylesheet_directory_uri() . '/assets/images/stamp.png';
                $output .= '<div class="stamp stamped">
                    <img src="' . esc_url($stamp_url) . '" alt="スタンプ">
                </div>';
            } else {
                $output .= '<div class="stamp empty"></div>';
            }
        }
        
        $output .= '</div>';
        
        // 特典交換ボタン（有効ポイントが10以上の場合）
        if ($available_points >= 10) {
            $output .= '<div class="reward-eligible">
                <p>特典交換が可能です。次回ご来店時にお申し出ください。</p>
            </div>';
        }
        
        $output .= '</div></div>';
        
        // ポイント情報
        $output .= '<div class="point-info">
            <p>累計ポイント: ' . $points . ' ポイント</p>
            <p>使用可能ポイント: ' . $available_points . ' ポイント</p>
            <p>特典交換回数: ' . $exchanged_count . ' 回</p>
            <p>最終利用日: ' . date('Y年m月d日', $last_used_timestamp) . '</p>
            <p>有効期限: ' . date('Y年m月d日', strtotime('+1 year', $last_used_timestamp)) . '</p>
        </div>';
        
        $output .= '</div>'; // .pointcard-container閉じ
        
        // QRコード生成用のJavaScriptを追加
        $output .= get_qrcode_javascript();
        
        wp_reset_postdata();
        return $output;
    } else {
        return '<p>ポイントカード情報が見つかりませんでした。管理者にお問い合わせください。</p>';
    }
}
add_shortcode('pointcard', 'pointcard_display_shortcode');


/**
 * 修正4: QRコードスキャナー部分の改善
 * - HTML5 QRコードスキャナーの直接的なリダイレクト
 */
function display_qr_scanner_section() {
    $output = '<div class="qr-scanner-section">
        <h2>QRコードスキャナー</h2>
        <div id="qr-reader"></div>
        <div id="qr-reader-results"></div>
        
        <script src="' . get_stylesheet_directory_uri() . '/assets/js/html5-qrcode.min.js"></script>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                function onScanSuccess(decodedText, decodedResult) {
                    // QRコードのデコード成功
                    document.getElementById("qr-reader-results").innerHTML = 
                        "<p>スキャン成功!</p>";
                    
                    // 読み取ったURLへ直接リダイレクト
                    window.location.href = decodedText;
                }

                function onScanFailure(error) {
                    // エラー処理（ユーザーには表示しない）
                    console.warn(`QRコードスキャンエラー: ${error}`);
                }

                let html5QrcodeScanner = new Html5QrcodeScanner(
                    "qr-reader", { 
                        fps: 10, 
                        qrbox: 250,
                        // スキャナーのカスタム設定
                        formatsToSupport: [ Html5QrcodeSupportedFormats.QR_CODE ],
                        rememberLastUsedCamera: true
                    });
                html5QrcodeScanner.render(onScanSuccess, onScanFailure);
            });
        </script>
    </div>';
    
    return $output;
}


// 管理者用ポイント管理ショートコード
/**
 * 変更2-2: ポイント履歴表示に管理者IDを追加
 * 変更2-3: 過去のスタンプに管理者ごとのスタンプ画像を表示する
 */
function pointcard_admin_shortcode() {
    if (!current_user_can('administrator')) {
        return '<p>管理者権限が必要です。</p>';
    }
    
    // QRコードスキャナー部分を追加
    $scanner_html = display_qr_scanner_section();
    
    // JavaScript を追加（確認ダイアログ用）
    $output = '<script>
    function confirmExchange() {
        return confirm("特典を交換しますか？");
    }
    </script>';
    
    $output .= '<div class="pointcard-admin">';
    
    // QRコードスキャナー部分を追加
    $output .= $scanner_html;
    
    // ユーザー検索フォーム
    $output .= '<div class="user-search">
        <h2>ユーザー検索</h2>
        <form method="get">
            <input type="text" name="email_search" placeholder="メールアドレスで検索" value="' . (isset($_GET['email_search']) ? esc_attr($_GET['email_search']) : '') . '">
            <button type="submit">検索</button>
        </form>
    </div>';
    
    // 検索結果表示
    if (isset($_GET['email_search']) && !empty($_GET['email_search'])) {
        $search_email = sanitize_email($_GET['email_search']);
        
        $args = array(
            'post_type' => 'pointcard',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => 'email',
                    'value' => $search_email,
                    'compare' => 'LIKE'
                )
            )
        );
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            $output .= '<div class="search-results">';
            
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $email = get_field('email', $post_id);
                $points = get_field('points', $post_id);
                $last_used_date = get_field('last_used_date', $post_id);
                
                // 交換履歴を取得して特典交換済み回数を計算
                $exchange_history = get_exchange_history($post_id);
                $exchanged_count = count($exchange_history);
                
                // 現在の有効ポイント（交換済み分を除く）
                $available_points = $points - ($exchanged_count * 10);
                
                $output .= '<div class="user-card">
                    <h3>' . $email . ' のポイントカード</h3>
                    <div class="user-info">
                        <p>累計ポイント: ' . $points . ' ポイント</p>
                        <p>使用可能ポイント: ' . $available_points . ' ポイント</p>
                        <p>交換済回数: ' . $exchanged_count . ' 回</p>
                        <p>最終利用日: ' . date('Y年m月d日', strtotime($last_used_date)) . '</p>
                    </div>
                    
                    <div class="point-actions">
                        <form method="post" class="add-point-form">
                            ' . wp_nonce_field('add_point_action', 'add_point_nonce', true, false) . '
                            <input type="hidden" name="action" value="add_point">
                            <input type="hidden" name="post_id" value="' . $post_id . '">
                            <input type="hidden" name="email" value="' . $email . '">
                            <input type="number" name="points" value="1" min="1" max="10">
                            <input type="text" name="memo" placeholder="メモ">
                            <button type="submit">ポイント追加</button>
                        </form>
                        
                        <form method="post" class="exchange-point-form" onsubmit="return confirmExchange()">
                            ' . wp_nonce_field('exchange_reward_action', 'exchange_reward_nonce', true, false) . '
                            <input type="hidden" name="action" value="exchange_reward">
                            <input type="hidden" name="post_id" value="' . $post_id . '">
                            <input type="hidden" name="email" value="' . $email . '">
                            <button type="' . ($available_points < 10 ? 'button' : 'submit') . '" 
                                    class="' . ($available_points < 10 ? 'disabled-button' : '') . '"
                                    ' . ($available_points < 10 ? 'onclick="alert(\'特典交換に必要なポイントが不足しています。10ポイント必要です。現在のポイント: ' . $available_points . 'ポイント\');"' : '') . '>
                                特典交換実行
                            </button>
                        </form>
                    </div>
                    
                    <div class="point-history">
                        <h4>ポイント履歴</h4>';
                
                // ポイント履歴表示（JSON形式から取得）- 全件表示するように変更
                $point_history = get_point_history($post_id);
                if (!empty($point_history)) {
                    $output .= '<table class="history-table">
                        <tr>
                            <th>日付</th>
                            <th>ポイント</th>
                            <th>メモ</th>
                            <th>管理者</th>
                            <th>管理者ID</th>
                            <th>スタンプ</th>
                        </tr>';
                    
                    // 表示件数制限なし - 全件表示
                    foreach ($point_history as $entry) {
                        // 管理者情報を取得
                        $admin_info = '';
                        $admin_id = isset($entry['admin_id']) ? $entry['admin_id'] : '';
                        if (!empty($admin_id)) {
                            $admin_user = get_userdata($admin_id);
                            $admin_info = $admin_user ? $admin_user->display_name : '不明';
                        }
                        
                        // スタンプ画像の取得 - 管理者ごとのスタンプ画像を表示
                        $stamp_url = '';
                        if (isset($entry['stamp_url']) && !empty($entry['stamp_url'])) {
                            $stamp_url = $entry['stamp_url'];
                        } elseif (!empty($admin_id)) {
                            $stamp_url = get_stamp_image_for_admin($admin_id);
                        } else {
                            $stamp_url = get_stylesheet_directory_uri() . '/assets/images/stamp.png';
                        }
                        
                        // 日本時間（JST）で表示
                        $jst_time = new DateTime($entry['date']);
                        $jst_time->setTimezone(new DateTimeZone('Asia/Tokyo'));
                        
                        $output .= '<tr>
                            <td>' . $jst_time->format('Y/m/d H:i') . '</td>
                            <td>' . $entry['points'] . '</td>
                            <td>' . esc_html($entry['memo']) . '</td>
                            <td>' . $admin_info . '</td>
                            <td>' . $admin_id . '</td>
                            <td><img src="' . esc_url($stamp_url) . '" style="width: 40px; height: 40px;" alt="スタンプ"></td>
                        </tr>';
                    }
                    
                    $output .= '</table>';
                } else {
                    $output .= '<p>履歴はありません。</p>';
                }
                
                $output .= '</div>'; // .point-history閉じ
                
                // 特典交換履歴
                $output .= '<div class="exchange-history">
                    <h4>特典交換履歴</h4>';
                
                if (!empty($exchange_history)) {
                    $output .= '<table class="history-table">
                        <tr>
                            <th>日付</th>
                            <th>交換ポイント</th>
                        </tr>';
                    
                    foreach ($exchange_history as $entry) {
                        // 交換履歴も日本時間で表示
                        $jst_time = new DateTime($entry['date']);
                        $jst_time->setTimezone(new DateTimeZone('Asia/Tokyo'));
                        
                        $output .= '<tr>
                            <td>' . $jst_time->format('Y/m/d H:i') . '</td>
                            <td>' . $entry['points'] . '</td>
                        </tr>';
                    }
                    
                    $output .= '</table>';
                } else {
                    $output .= '<p>交換履歴はありません。</p>';
                }
                
                $output .= '</div>'; // .exchange-history閉じ
                $output .= '</div>'; // .user-card閉じ
            }
            
            $output .= '</div>'; // .search-results閉じ
            
            wp_reset_postdata();
        } else {
            $output .= '<p>該当するユーザーが見つかりませんでした。</p>';
        }
    }
    
    $output .= '</div>'; // .pointcard-admin閉じ
    
    return $output;
}
add_shortcode('pointcard_admin', 'pointcard_admin_shortcode');


/**
 * QRコードから直接アクセスされた場合のトークン処理
 */
function process_qr_token_redirect() {
    // 管理者でない場合は処理しない
    if (!is_user_logged_in() || !current_user_can('administrator')) {
        return;
    }
    
    // email_searchとtokenパラメータが両方ある場合は処理
    if (isset($_GET['email_search']) && isset($_GET['token'])) {
        $email = sanitize_email($_GET['email_search']);
        $token = sanitize_text_field($_GET['token']);
        
        // トークンが正しいか検証（セキュリティ強化のため）
        $users = get_users(array(
            'search' => $email,
            'search_columns' => array('user_email'),
            'meta_key' => 'qr_point_token',
            'meta_value' => $token,
        ));
        
        if (!empty($users)) {
            // 検証OK - そのまま表示（既にemail_searchパラメータがあるため）
            // 検証のみを行い、リダイレクトは不要
            
            // 管理者向けメッセージ（デバッグ用・必要に応じて削除可）
            add_action('wp_footer', function() use ($email) {
                echo '<script>console.log("QRコード認証成功: ' . esc_js($email) . '");</script>';
            });
            
            return;
        } else {
            // トークンが無効な場合
            add_action('wp_footer', function() {
                echo '<script>console.log("トークン検証失敗");</script>';
            });
        }
    }
}
add_action('template_redirect', 'process_qr_token_redirect');


// ポイント追加・特典交換処理
/**
 * 修正3: ポイント追加処理の修正 - 管理者スタンプの正確な適用
 */
function process_pointcard_actions() {
    if (!current_user_can('administrator')) {
        return;
    }
    
    // ポイント追加処理
    if (isset($_POST['action']) && $_POST['action'] == 'add_point') {
        // CSRFチェック
        if (!isset($_POST['add_point_nonce']) || !wp_verify_nonce($_POST['add_point_nonce'], 'add_point_action')) {
            wp_die('セキュリティチェックに失敗しました。');
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $points_to_add = isset($_POST['points']) ? absint($_POST['points']) : 0;
        $memo = isset($_POST['memo']) ? sanitize_text_field($_POST['memo']) : '';
        
        if ($post_id <= 0 || empty($email)) {
            wp_die('無効なパラメータです。');
        }
        
        if ($points_to_add <= 0 || $points_to_add > 10) {
            wp_die('ポイント数は1から10の間で指定してください。');
            return;
        }
        
        // 現在のポイント取得
        $current_points = get_field('points', $post_id);
        $new_points = $current_points + $points_to_add;
        
        // 現在の管理者情報を取得
        $current_admin_id = get_current_user_id();
        
        // 管理者IDに基づいてスタンプ画像を設定（修正した関数を使用）
        $stamp_url = get_stamp_image_for_admin($current_admin_id);
        
        // デバッグ情報をログに記録
        error_log('ポイント追加: 管理者ID=' . $current_admin_id . ', スタンプURL=' . $stamp_url);
        
        // ポイント更新
        update_field('points', $new_points, $post_id);
        update_field('last_used_date', date('Y-m-d H:i:s'), $post_id);
        
        // ポイント履歴に追加
        $entry = array(
            'date' => date('Y-m-d H:i:s'),
            'points' => $points_to_add,
            'memo' => $memo,
            'admin_id' => $current_admin_id,
            'stamp_url' => $stamp_url
        );
        
        // 既存の関数を使用して履歴を追加
        add_point_history_entry($post_id, $entry);
        
        // スクリプトを使って追加メッセージを表示してリダイレクト
        echo '<script>
            alert("ポイントを ' . $points_to_add . ' つ追加しました。");
            window.location.href = "' . add_query_arg(array('email_search' => $email), remove_query_arg('error')) . '";
        </script>';
        exit;
    }
        
    // 特典交換処理
    if (isset($_POST['action']) && $_POST['action'] == 'exchange_reward') {
        // CSRFチェック
        if (!isset($_POST['exchange_reward_nonce']) || !wp_verify_nonce($_POST['exchange_reward_nonce'], 'exchange_reward_action')) {
            wp_die('セキュリティチェックに失敗しました。');
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        
        if ($post_id <= 0 || empty($email)) {
            wp_die('無効なパラメータです。');
        }
        
        // 現在のポイント取得
        $current_points = get_field('points', $post_id);
        
        // 交換履歴を取得して特典交換済み回数を計算
        $exchange_history = get_exchange_history($post_id);
        $exchanged_count = count($exchange_history);
        
        // 現在の有効ポイント（交換済み分を除く）
        $available_points = $current_points - ($exchanged_count * 10);
        
        // 特典交換可能かチェック
        if ($available_points >= 10) {
            // 特典交換履歴に追加
            $entry = array(
                'date' => date('Y-m-d H:i:s'),
                'points' => 10
            );
            
            $exchange_history[] = $entry;
            update_field('exchange_history_json', json_encode($exchange_history), $post_id);
            
            // 最終利用日の更新
            update_field('last_used_date', date('Y-m-d H:i:s'), $post_id);
            
            // 特典交換通知メール送信
            send_reward_exchange_notice($email, 10);
            
            // 完了メッセージとリダイレクト
            echo '<script>
                alert("特典交換が完了しました。");
                window.location.href = "' . add_query_arg(array('email_search' => $email), remove_query_arg('error')) . '";
            </script>';
            exit;
        } else {
            // エラーメッセージとリダイレクト
            echo '<script>
                alert("特典交換に必要なポイントが不足しています。");
                window.location.href = "' . add_query_arg(array('email_search' => $email), remove_query_arg('updated')) . '";
            </script>';
            exit;
        }
    }
}
add_action('template_redirect', 'process_pointcard_actions');


// 追加: メッセージ表示機能
function pointcard_admin_messages() {
    if (isset($_GET['updated'])) {
        $message = '';
        $type = 'success';
        
        switch ($_GET['updated']) {
            case 'point_added':
                $message = 'ポイントを追加しました。';
                break;
            case 'reward_exchanged':
                $message = '特典交換が完了しました。';
                break;
        }
        
        if (!empty($message)) {
            echo '<div class="notice notice-' . $type . ' is-dismissible"><p>' . $message . '</p></div>';
        }
    }
    
    if (isset($_GET['error'])) {
        $message = '';
        $type = 'error';
        
        switch ($_GET['error']) {
            case 'insufficient_points':
                $message = '特典交換に必要なポイントが不足しています。';
                break;
        }
        
        if (!empty($message)) {
            echo '<div class="notice notice-' . $type . ' is-dismissible"><p>' . $message . '</p></div>';
        }
    }
}

// 特典交換通知の修正版
function send_reward_exchange_notice($email, $points_used) {
    if (empty($email) || !is_email($email)) {
        return false;
    }
    
    $site_name = get_bloginfo('name');
    $subject = '【' . $site_name . '】特典交換完了のお知らせ';
    
    $message = "特典交換が完了しました。\n\n" .
               "交換ポイント数: " . $points_used . " ポイント\n" .
               "交換日時: " . date('Y年m月d日 H:i') . "\n\n" .
               "ログインしてポイントを確認: " . site_url('/wp-login.php');
    
    return wp_mail($email, $subject, $message);
}

add_action('template_redirect', 'process_pointcard_actions');


// 通知用のスケジュールイベントを登録
function schedule_pointcard_notifications() {
    if (!wp_next_scheduled('check_pointcard_expirations')) {
        wp_schedule_event(time(), 'daily', 'check_pointcard_expirations');
    }
}
add_action('wp', 'schedule_pointcard_notifications');

// 期限切れ確認とメール通知
function check_pointcard_expirations() {
    $args = array(
        'post_type' => 'pointcard',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    );
    
    $query = new WP_Query($args);
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $email = get_field('email', $post_id);
            $last_used_date = get_field('last_used_date', $post_id);
            $last_used_timestamp = strtotime($last_used_date);
            $today = time();
            
            // 3ヶ月経過チェック
            $three_months = strtotime('+3 months', $last_used_timestamp);
            if ($three_months <= $today && $three_months > $today - 86400) { // 1日以内に3ヶ月が経過
                send_expiration_notice($email, 3, $last_used_date);
            }
            
            // 6ヶ月経過チェック
            $six_months = strtotime('+6 months', $last_used_timestamp);
            if ($six_months <= $today && $six_months > $today - 86400) { // 1日以内に6ヶ月が経過
                send_expiration_notice($email, 6, $last_used_date);
            }
            
            // 11ヶ月経過チェック (期限切れ1ヶ月前)
            $eleven_months = strtotime('+11 months', $last_used_timestamp);
            if ($eleven_months <= $today && $eleven_months > $today - 86400) { // 1日以内に11ヶ月が経過
                send_expiration_notice($email, 11, $last_used_date);
            }
        }
    }
    
    wp_reset_postdata();
}
add_action('check_pointcard_expirations', 'check_pointcard_expirations');

// 期限切れ通知メール送信
function send_expiration_notice($email, $months, $last_used_date) {
    $subject = '';
    $message = '';
    
    $site_name = get_bloginfo('name');
    $expiry_date = date('Y年m月d日', strtotime('+1 year', strtotime($last_used_date)));
    
    switch ($months) {
        case 3:
            $subject = '【' . $site_name . '】ポイントカード更新のお知らせ';
            $message = 'ポイントカードの最終利用から3ヶ月が経過しています。';
            break;
        case 6:
            $subject = '【' . $site_name . '】ポイントカード利用促進のお知らせ';
            $message = 'ポイントカードの最終利用から6ヶ月が経過しています。';
            break;
        case 11:
            $subject = '【' . $site_name . '】ポイントカード有効期限間近のお知らせ';
            $message = 'ポイントカードの有効期限が1ヶ月後に迫っています。';
            break;
    }
    
    $message .= "\n\n最終利用日: " . date('Y年m月d日', strtotime($last_used_date)) . 
                "\n有効期限: " . $expiry_date . 
                "\n\nポイントの有効期限が切れる前にご利用ください。";
    
    $message .= "\n\nログインしてポイントを確認: " . site_url('/wp-login.php');
    
    wp_mail($email, $subject, $message);
}



// ログイン関連のカスタマイズ
function custom_login_settings() {
    // ログイン画面のロゴURLを変更
    add_filter('login_headerurl', function() {
        return home_url();
    });
    
    // ログイン画面のロゴテキストを変更
    add_filter('login_headertext', function() {
        return get_bloginfo('name');
    });
    
    // ログイン後のリダイレクト先を変更
    add_filter('login_redirect', function($redirect_to, $request, $user) {
        if (isset($user->roles) && is_array($user->roles)) {
            if (in_array('administrator', $user->roles)) {
                // 管理者はダッシュボードへ
                return admin_url();
            } else {
                // 一般ユーザーはポイントカード表示ページへ
                return home_url('/pointcard/');
            }
        }
        return $redirect_to;
    }, 10, 3);
}
add_action('init', 'custom_login_settings');

// カスタムスタイルシートの追加
function add_pointcard_styles() {
    wp_enqueue_style('pointcard-style', get_stylesheet_directory_uri() . '/assets/css/pointcard.css');
    
    // インラインでCSSを追加
    $custom_css = "
        .disabled-button {
            background-color: #cccccc !important;
            color: #666666 !important;
            cursor: not-allowed !important;
            opacity: 0.7;
            border-color: #aaaaaa !important;
        }
        
        .disabled-button:hover {
            background-color: #cccccc !important;
            color: #666666 !important;
        }
    ";
    wp_add_inline_style('pointcard-style', $custom_css);
}
add_action('wp_enqueue_scripts', 'add_pointcard_styles');


// 管理画面にメディアアップローダーのスクリプトを読み込む
function load_media_files() {
    wp_enqueue_media();
}
add_action('admin_enqueue_scripts', 'load_media_files');


// 管理画面メニュー - 統合版
function pointcard_admin_menu() {
    // メインの「ポイントカード」メニューのみ登録
    add_menu_page(
        'ポイントカード管理',
        'ポイントカード',
        'manage_options',
        'pointcard-settings',
        'pointcard_settings_page',
        'dashicons-id',
        30
    );
    
    // サブメニュー：設定（デフォルトページ）
    add_submenu_page(
        'pointcard-settings',
        'ポイントカード設定',
        '設定',
        'manage_options',
        'pointcard-settings', // 親と同じスラッグ
        'pointcard_settings_page'
    );
    
    // 管理者スタンプページ
    add_submenu_page(
        'pointcard-settings',
        '管理者スタンプ',
        '管理者スタンプ',
        'manage_options',
        'admin-stamps',
        'manager_stamps_page_callback'
    );
}
add_action('admin_menu', 'pointcard_admin_menu');

// 管理者スタンプ一覧ページのコンテンツ
function manager_stamps_page_callback() {
    ?>
    <div class="wrap">
        <h1>管理者スタンプ一覧</h1>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>管理者名</th>
                    <th>スタンプ画像</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $admin_users = get_users(array('role' => 'administrator'));
                
                foreach ($admin_users as $user) {
                    $stamp_image = get_user_meta($user->ID, 'stamp_image', true);
                    ?>
                    <tr>
                        <td><?php echo esc_html($user->display_name); ?></td>
                        <td>
                            <?php if (!empty($stamp_image)) : ?>
                                <img src="<?php echo esc_url($stamp_image); ?>" style="max-width: 100px; height: auto;" />
                            <?php else : ?>
                                <em>未設定</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->ID); ?>" class="button">編集</a>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}


// 設定ページのコンテンツ
function pointcard_settings_page() {
    ?>
    <div class="wrap">
        <h1>ポイントカード設定</h1>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('pointcard_options');
            do_settings_sections('pointcard-settings');
            submit_button();
            ?>
        </form>
        
        <hr>
        
        <h2>ショートコード一覧</h2>
        <table class="form-table">
            <tr>
                <th>ユーザー用ポイントカード表示</th>
                <td><code>[pointcard]</code></td>
            </tr>
            <tr>
                <th>管理者用ポイント管理画面</th>
                <td><code>[pointcard_admin]</code></td>
            </tr>
        </table>
        
        <hr>
        
        <h2>統計情報</h2>
        <?php pointcard_display_stats(); ?>
    </div>
    <?php
}

// 統計情報の表示
function pointcard_display_stats() {
    global $wpdb;
    
    // ポイントカードの総数
    $total_cards = wp_count_posts('pointcard')->publish;
    
    // 総ポイント数を計算
    $args = array(
        'post_type' => 'pointcard',
        'posts_per_page' => -1,
        'fields' => 'ids'
    );
    
    $query = new WP_Query($args);
    $total_points = 0;
    $total_exchanged = 0;
    
    if ($query->have_posts()) {
        foreach ($query->posts as $post_id) {
            $points = get_field('points', $post_id);
            $total_points += $points;
            
            // 交換回数
            $exchange_history = get_exchange_history($post_id);
            $total_exchanged += count($exchange_history);
        }
    }
    
    ?>
    <table class="form-table">
        <tr>
            <th>総ポイントカード数</th>
            <td><?php echo $total_cards; ?></td>
        </tr>
        <tr>
            <th>総発行ポイント数</th>
            <td><?php echo $total_points; ?></td>
        </tr>
        <tr>
            <th>総特典交換回数</th>
            <td><?php echo $total_exchanged; ?></td>
        </tr>
    </table>
    <?php
}

// 設定オプションの登録
function pointcard_register_settings() {
    register_setting('pointcard_options', 'pointcard_options');
    
    add_settings_section(
        'pointcard_general_section',
        '基本設定',
        'pointcard_general_section_callback',
        'pointcard-settings'
    );
    
    add_settings_field(
        'expiry_period',
        '有効期限（月）',
        'pointcard_expiry_period_callback',
        'pointcard-settings',
        'pointcard_general_section'
    );
    
    add_settings_field(
        'points_per_reward',
        '特典交換に必要なポイント',
        'pointcard_points_per_reward_callback',
        'pointcard-settings',
        'pointcard_general_section'
    );
}
add_action('admin_init', 'pointcard_register_settings');

// セクションの説明
function pointcard_general_section_callback() {
    echo '<p>ポイントカードの基本設定を行います。</p>';
}

// 有効期限設定フィールド
function pointcard_expiry_period_callback() {
    $options = get_option('pointcard_options');
    $period = isset($options['expiry_period']) ? $options['expiry_period'] : 12;
    ?>
    <input type="number" name="pointcard_options[expiry_period]" value="<?php echo $period; ?>" min="1" max="60">
    <p class="description">最終利用日からの有効期限（月数）</p>
    <?php
}

// 特典交換ポイント設定フィールド
function pointcard_points_per_reward_callback() {
    $options = get_option('pointcard_options');
    $points = isset($options['points_per_reward']) ? $options['points_per_reward'] : 10;
    ?>
    <input type="number" name="pointcard_options[points_per_reward]" value="<?php echo $points; ?>" min="1" max="100">
    <p class="description">特典と交換するために必要なポイント数</p>
    <?php
}

// ダッシュボードウィジェットの追加
function pointcard_dashboard_widgets() {
    wp_add_dashboard_widget(
        'pointcard_summary_widget',
        'ポイントカード概要',
        'pointcard_summary_widget_callback'
    );
}
add_action('wp_dashboard_setup', 'pointcard_dashboard_widgets');

// ダッシュボードウィジェットのコンテンツ
function pointcard_summary_widget_callback() {
    // 最近のポイント付与
    $args = array(
        'post_type' => 'pointcard',
        'posts_per_page' => 5,
        'orderby' => 'modified',
        'order' => 'DESC'
    );
    
    $query = new WP_Query($args);
    
    if ($query->have_posts()) {
        echo '<h4>最近のポイント活動</h4>';
        echo '<ul>';
        
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $email = get_field('email', $post_id);
            $points = get_field('points', $post_id);
            $last_used_date = get_field('last_used_date', $post_id);
            
            echo '<li>';
            echo '<strong>' . esc_html($email) . '</strong> - ';
            echo esc_html($points) . 'ポイント / ';
            echo '最終更新: ' . date('Y/m/d', strtotime($last_used_date));
            echo '</li>';
        }
        
        echo '</ul>';
        
        echo '<p><a href="' . admin_url('edit.php?post_type=pointcard') . '">すべてのポイントカードを表示</a></p>';
        
        wp_reset_postdata();
    } else {
        echo '<p>ポイントカードの活動はまだありません。</p>';
    }
}

// 管理者プロフィールにスタンプ画像設定フィールドを追加
function add_stamp_image_field($user) {
    // 管理者だけに表示
    if (!current_user_can('administrator')) {
        return;
    }
    
    // 現在設定されているスタンプ画像を取得
    $stamp_image = get_user_meta($user->ID, 'stamp_image', true);
    ?>
    <h3>ポイントカード用スタンプ画像</h3>
    <table class="form-table">
        <tr>
            <th><label for="stamp_image">スタンプ画像</label></th>
            <td>
                <?php if (!empty($stamp_image)) : ?>
                    <img src="<?php echo esc_url($stamp_image); ?>" style="max-width: 100px; height: auto;" />
                    <br>
                <?php endif; ?>
                <input type="text" name="stamp_image" id="stamp_image" value="<?php echo esc_attr($stamp_image); ?>" class="regular-text" />
                <input type="button" class="button button-secondary" value="画像をアップロード" id="upload_stamp_button" />
                <p class="description">ポイント追加時に使用するスタンプ画像を設定します。200x200px程度の画像を推奨します。</p>
            </td>
        </tr>
    </table>
    
    <script>
    jQuery(document).ready(function($) {
        $('#upload_stamp_button').click(function(e) {
            e.preventDefault();
            
            var image_frame;
            if (image_frame) {
                image_frame.open();
            }
            
            // WPのメディアアップローダーを定義
            image_frame = wp.media({
                title: 'スタンプ画像を選択',
                multiple: false,
                library: {
                    type: 'image',
                }
            });
            
            // 画像が選択されたときの処理
            image_frame.on('select', function() {
                var selection = image_frame.state().get('selection');
                var gallery_ids = new Array();
                var my_index = 0;
                selection.each(function(attachment) {
                    gallery_ids[my_index] = attachment['id'];
                    my_index++;
                });
                
                var ids = gallery_ids.join(",");
                if (ids.length === 0) return;
                
                var attachment = selection.first().toJSON();
                $('#stamp_image').val(attachment.url);
            });
            
            image_frame.open();
        });
    });
    </script>
    <?php
}
add_action('show_user_profile', 'add_stamp_image_field');
add_action('edit_user_profile', 'add_stamp_image_field');

// スタンプ画像設定を保存
function save_stamp_image_field($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }
    
    if (isset($_POST['stamp_image'])) {
        $stamp_url = sanitize_text_field($_POST['stamp_image']);
        update_user_meta($user_id, 'stamp_image', $stamp_url);
        error_log('管理者ID ' . $user_id . ' のスタンプ画像を保存: ' . $stamp_url);
    }
}

add_action('personal_options_update', 'save_stamp_image_field');
add_action('edit_user_profile_update', 'save_stamp_image_field');

/**
 * 管理者IDからスタンプ画像URLを取得する関数
 */
/**
 * 修正1: 管理者IDとスタンプ画像のマッピングを修正
 * 各管理者の実際のユーザーIDとスタンプ画像を正確に紐づけます
 */
function get_stamp_image_for_admin($admin_id) {
    // 管理者IDは数値型であることを確認
    $admin_id = intval($admin_id);
    
    // 管理者IDとスタンプ画像の正確なマッピング
    $admin_stamps = array(
        21 => 'https://pt.amid.co.jp/wp-content/uploads/2025/03/f0a3b54d237599edfec95740b1a31f41.png', // nobuhito (ID:21)
        16 => 'https://pt.amid.co.jp/wp-content/uploads/2025/03/att.H9CWT_BmSMUWlrHKr36pctINbRHGE_I5Dvjg2HRmUrU.jpg', // mono96 (ID:16)
        19 => 'https://pt.amid.co.jp/wp-content/uploads/2025/03/koume.png', // koume18@hotmail.com (ID:19)
    );
    
    // マッピングに存在する場合はそのURLを返す
    if (isset($admin_stamps[$admin_id])) {
        return $admin_stamps[$admin_id];
    }
    
    // ユーザーメタからスタンプ画像を取得（バックアップ手段）
    $stamp_from_meta = get_user_meta($admin_id, 'stamp_image', true);
    if (!empty($stamp_from_meta)) {
        return $stamp_from_meta;
    }
    
    // どちらの方法でも取得できない場合はデフォルトスタンプを返す
    return get_stylesheet_directory_uri() . '/assets/images/stamp.png';
}



/**
 * ポイントカードCSV出力機能
 * 管理画面からポイントカード情報をCSVでエクスポート
 */
/**
 * ポイントカードCSV出力機能
 * 管理画面からポイントカード情報をCSVでエクスポート
 */

// ポイントカード管理メニューにCSVエクスポート機能を追加
function add_pointcard_export_page() {
    add_submenu_page(
        'pointcard-settings',      // 親メニュースラッグ
        'CSVエクスポート',          // ページタイトル
        'CSVエクスポート',          // メニュータイトル
        'manage_options',          // 権限
        'pointcard-csv-export',    // メニュースラッグ
        'pointcard_csv_export_page' // コールバック関数
    );
}
add_action('admin_menu', 'add_pointcard_export_page');

// CSVエクスポートページの表示
function pointcard_csv_export_page() {
    // アクセス権チェック
    if (!current_user_can('manage_options')) {
        wp_die('このページにアクセスする権限がありません。');
    }
    
    // エクスポート実行のチェック
    if (isset($_POST['export_csv']) && check_admin_referer('pointcard_export_csv')) {
        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'all';
        
        // CSVエクスポート実行（常に履歴を含める）
        export_pointcard_csv($period, true);
        // この関数はCSVをダウンロードさせた後に終了するため、以下のコードは実行されない
    }
    
    ?>
    <div class="wrap">
        <h1>ポイントカードCSVエクスポート</h1>
        
        <div class="card">
            <h2>CSVエクスポート</h2>
            <p>ポイントカード情報をCSV形式でエクスポートします。</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('pointcard_export_csv'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">期間</th>
                        <td>
                            <select name="period">
                                <option value="all">全期間</option>
                                <option value="today">今日</option>
                                <option value="yesterday">昨日</option>
                                <option value="last7days">過去7日間</option>
                                <option value="last30days">過去30日間</option>
                                <option value="thismonth">今月</option>
                                <option value="lastmonth">先月</option>
                            </select>
                        </td>
                    </tr>
                    <!-- 詳細データは常に含めるため、オプションを削除 -->
                    <tr>
                        <th scope="row">履歴データ</th>
                        <td>
                            <p class="description">全てのポイント履歴と交換履歴を含めて出力します。</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="export_csv" class="button button-primary" value="CSVをエクスポート">
                </p>
            </form>
        </div>
    </div>
    <?php
}

// CSVエクスポート処理
/**
 * 変更2-3: CSVエクスポートにも管理者IDを追加
 */
function export_pointcard_csv($period = 'all', $include_history = true) {
    // 期間に基づくクエリ引数を設定
    $date_query = array();
    
    switch ($period) {
        case 'today':
            $date_query = array(
                'year' => date('Y'),
                'month' => date('m'),
                'day' => date('d')
            );
            break;
        case 'yesterday':
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $date_query = array(
                'year' => date('Y', strtotime($yesterday)),
                'month' => date('m', strtotime($yesterday)),
                'day' => date('d', strtotime($yesterday))
            );
            break;
        case 'last7days':
            $date_query = array(
                'after' => date('Y-m-d', strtotime('-7 days'))
            );
            break;
        case 'last30days':
            $date_query = array(
                'after' => date('Y-m-d', strtotime('-30 days'))
            );
            break;
        case 'thismonth':
            $date_query = array(
                'year' => date('Y'),
                'month' => date('m')
            );
            break;
        case 'lastmonth':
            $last_month = date('Y-m-d', strtotime('first day of last month'));
            $date_query = array(
                'year' => date('Y', strtotime($last_month)),
                'month' => date('m', strtotime($last_month))
            );
            break;
        default: // 'all'
            $date_query = array();
            break;
    }
    
    // ポイントカードデータを取得
    $args = array(
        'post_type' => 'pointcard',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    );
    
    // 期間指定がある場合
    if (!empty($date_query)) {
        $args['date_query'] = array($date_query);
    }
    
    $query = new WP_Query($args);
    
    // CSVヘッダー
    $csv_headers = array(
        'ID',
        'メールアドレス',
        '累計ポイント',
        '使用可能ポイント',
        '交換済回数',
        '最終利用日',
        '有効期限',
        '登録日',
        'ポイント履歴',
        '交換履歴'
    );
    
    // CSVデータ
    $csv_data = array();
    $csv_data[] = $csv_headers;
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            
            // 基本データを取得
            $email = get_field('email', $post_id);
            $points = get_field('points', $post_id);
            $last_used_date = get_field('last_used_date', $post_id);
            
            // 交換履歴を取得して特典交換済み回数を計算
            $exchange_history = get_exchange_history($post_id);
            $exchanged_count = count($exchange_history);
            
            // 現在の有効ポイント（交換済み分を除く）
            $available_points = $points - ($exchanged_count * 10);
            
            // 有効期限を計算
            $expiry_date = date('Y-m-d', strtotime('+1 year', strtotime($last_used_date)));
            
            // 基本データ行
            $row = array(
                $post_id,
                $email,
                $points,
                $available_points,
                $exchanged_count,
                $last_used_date,
                $expiry_date,
                get_the_date('Y-m-d')
            );
            
            // 常に全ての履歴を含める
            // ポイント履歴
            $point_history = get_point_history($post_id);
            $point_history_str = '';
            foreach ($point_history as $entry) {
                $admin_name = '';
                $admin_id = isset($entry['admin_id']) ? $entry['admin_id'] : '不明';
                if (isset($entry['admin_id'])) {
                    $admin_user = get_userdata($entry['admin_id']);
                    $admin_name = $admin_user ? $admin_user->display_name : '不明';
                }
                
                $stamp_url = isset($entry['stamp_url']) ? $entry['stamp_url'] : '未設定';
                
                // CSVエクスポートの日時も日本時間に変更
                $jst_time = new DateTime($entry['date']);
                $jst_time->setTimezone(new DateTimeZone('Asia/Tokyo'));
                
                $point_history_str .= sprintf(
                    "[%s] %sポイント (管理者: %s, ID: %s) %s - スタンプ: %s\n",
                    $jst_time->format('Y/m/d H:i'),
                    $entry['points'],
                    $admin_name,
                    $admin_id,
                    isset($entry['memo']) ? $entry['memo'] : '',
                    $stamp_url
                );
            }
            $row[] = $point_history_str;
            
            // 交換履歴
            $exchange_history_str = '';
            foreach ($exchange_history as $entry) {
                // 交換履歴のCSVエクスポートも日本時間に変更
                $jst_time = new DateTime($entry['date']);
                $jst_time->setTimezone(new DateTimeZone('Asia/Tokyo'));
                
                $exchange_history_str .= sprintf(
                    "[%s] %sポイント交換\n",
                    $jst_time->format('Y/m/d H:i'),
                    $entry['points']
                );
            }
            $row[] = $exchange_history_str;
            
            $csv_data[] = $row;
        }
    }
    
    wp_reset_postdata();
    
    // CSVダウンロード処理
    $filename = 'pointcard-export-' . date('Y-m-d') . '.csv';
    
    // ヘッダーを送信
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // CSVをストリームで出力（UTF-8 BOMを追加）
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM
    
    foreach ($csv_data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}



// 特定の期間内のポイント集計データを取得するショートコード
function pointcard_stats_shortcode($atts) {
    // 属性のデフォルト値を設定
    $atts = shortcode_atts(array(
        'period' => 'all', // all, today, yesterday, last7days, last30days, thismonth, lastmonth
    ), $atts);
    
    $period = $atts['period'];
    
    // 期間に基づくクエリ引数を設定
    $date_query = array();
    
    switch ($period) {
        case 'today':
            $date_query = array(
                'year' => date('Y'),
                'month' => date('m'),
                'day' => date('d')
            );
            $period_label = '今日';
            break;
        case 'yesterday':
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $date_query = array(
                'year' => date('Y', strtotime($yesterday)),
                'month' => date('m', strtotime($yesterday)),
                'day' => date('d', strtotime($yesterday))
            );
            $period_label = '昨日';
            break;
        case 'last7days':
            $date_query = array(
                'after' => date('Y-m-d', strtotime('-7 days'))
            );
            $period_label = '過去7日間';
            break;
        case 'last30days':
            $date_query = array(
                'after' => date('Y-m-d', strtotime('-30 days'))
            );
            $period_label = '過去30日間';
            break;
        case 'thismonth':
            $date_query = array(
                'year' => date('Y'),
                'month' => date('m')
            );
            $period_label = '今月';
            break;
        case 'lastmonth':
            $last_month = date('Y-m-d', strtotime('first day of last month'));
            $date_query = array(
                'year' => date('Y', strtotime($last_month)),
                'month' => date('m', strtotime($last_month))
            );
            $period_label = '先月';
            break;
        default: // 'all'
            $date_query = array();
            $period_label = '全期間';
            break;
    }
    
    // ポイントカードデータを取得
    $args = array(
        'post_type' => 'pointcard',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    );
    
    // 期間指定がある場合
    if (!empty($date_query)) {
        $args['date_query'] = array($date_query);
    }
    
    $query = new WP_Query($args);
    
    // 集計データ初期化
    $total_cards = $query->found_posts;
    $total_points = 0;
    $total_exchanged = 0;
    $active_users = 0;
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            
            // ポイント数を取得
            $points = get_field('points', $post_id);
            $total_points += $points;
            
            // 交換回数を取得
            $exchange_history = get_exchange_history($post_id);
            $exchanged_count = count($exchange_history);
            $total_exchanged += $exchanged_count;
            
            // アクティブユーザー数（ポイントが1以上）
            if ($points > 0) {
                $active_users++;
            }
        }
    }
    
    wp_reset_postdata();
    
    // 集計結果を表示
    $output = '<div class="pointcard-stats">
        <h3>ポイントカード集計 (' . $period_label . ')</h3>
        <table class="stats-table">
            <tr>
                <th>総ポイントカード数</th>
                <td>' . $total_cards . '枚</td>
            </tr>
            <tr>
                <th>総発行ポイント数</th>
                <td>' . $total_points . 'ポイント</td>
            </tr>
            <tr>
                <th>総特典交換回数</th>
                <td>' . $total_exchanged . '回</td>
            </tr>
            <tr>
                <th>アクティブユーザー数</th>
                <td>' . $active_users . '人</td>
            </tr>
        </table>
    </div>';
    
    return $output;
}
add_shortcode('pointcard_stats', 'pointcard_stats_shortcode');

// シンプルなユーザー登録のみ（ポイントカード登録なし）
function simple_user_add_shortcode() {
    if (!current_user_can('administrator')) {
        return '<p>管理者権限が必要です。</p>';
    }
    
    $message = '';
    $error = '';
    $existing_user_html = '';
    
    // 登録成功フラグがあれば成功メッセージを表示
    if (isset($_GET['registration_complete']) && $_GET['registration_complete'] == '1') {
        $message = 'ユーザー登録が完了しました。';
    }
    
    // 重複エラーフラグがあればエラーメッセージを表示
    if (isset($_GET['duplicate_error']) && $_GET['duplicate_error'] == '1') {
        $error = 'このメールアドレスのユーザーは既に存在します。';
    }
    
    // ユーザー検索
    if (isset($_GET['check_email']) && !empty($_GET['check_email'])) {
        $check_email = sanitize_email($_GET['check_email']);
        
        if (is_email($check_email)) {
            $existing_user = get_user_by('email', $check_email);
            
            if ($existing_user) {
                $existing_user_html = '<div class="existing-user">
                    <h3>「' . esc_html($check_email) . '」の既存ユーザー情報</h3>
                    <p>ユーザーID: ' . $existing_user->ID . '</p>
                    <p>ユーザー名: ' . $existing_user->user_login . '</p>
                    <p>表示名: ' . $existing_user->display_name . '</p>
                    <p class="warning">※ このメールアドレスは既に登録されています。</p>
                </div>';
            } else {
                $existing_user_html = '<div class="no-existing-user">
                    <p>「' . esc_html($check_email) . '」のユーザーは存在しません。新規登録できます。</p>
                </div>';
            }
        }
    }
    
    // フォーム送信処理
    if (isset($_POST['action']) && $_POST['action'] == 'add_user') {
        // CSRFチェック
        if (!isset($_POST['add_user_nonce']) || !wp_verify_nonce($_POST['add_user_nonce'], 'add_user_action')) {
            $error = 'セキュリティチェックに失敗しました。';
        } else {
            $user_email = isset($_POST['user_email']) ? sanitize_email($_POST['user_email']) : '';
            $force_create = isset($_POST['force_create']) && $_POST['force_create'] == '1';
            
            if (empty($user_email) || !is_email($user_email)) {
                $error = '有効なメールアドレスを入力してください。';
            } else {
                // ユーザーの重複チェック
                $existing_user = get_user_by('email', $user_email);
                
                if ($existing_user && !$force_create) {
                    $error = 'このメールアドレスのユーザーは既に存在します。';
                } else {
                    if (!$existing_user) {
                        // ランダムパスワードを生成
                        $random_password = wp_generate_password(16, true, false);
                        
                        // ユーザー作成
                        $user_id = wp_create_user($user_email, $random_password, $user_email);
                        
                        if (is_wp_error($user_id)) {
                            $error = $user_id->get_error_message();
                        } else {
                            // ユーザーロールを購読者に設定
                            $user = new WP_User($user_id);
                            $user->set_role('subscriber');
                            
                            $message = 'ユーザーを追加しました。メールアドレス: ' . $user_email;
                        }
                    } else {
                        $message = 'ユーザーは既に存在します。メールアドレス: ' . $user_email;
                    }
                }
            }
        }
    }
    
    // フォーム表示
    $output = '<div class="simple-user-add">';
    
    if (!empty($message)) {
        $output .= '<div class="message success">' . esc_html($message) . '</div>';
    }
    
    if (!empty($error)) {
        $output .= '<div class="message error">' . esc_html($error) . '</div>';
    }
    
    // ユーザー検索フォーム
    $output .= '<div class="email-check-form">
        <h3>ユーザー検索</h3>
        <p>メールアドレスを入力して「チェック」ボタンをクリックすると、既存ユーザーを検索できます。</p>
        <form method="get">
            <div class="form-row">
                <label for="check_email">メールアドレス</label>
                <input type="email" id="check_email" name="check_email" value="' . (isset($_GET['check_email']) ? esc_attr($_GET['check_email']) : '') . '" required>
                <button type="submit" class="button-secondary">チェック</button>
            </div>
        </form>
    </div>';
    
    // 既存ユーザー情報表示
    $output .= $existing_user_html;
    
    $output .= '<h2>新規ユーザー追加</h2>
    <form method="post" class="add-user-form">
        ' . wp_nonce_field('add_user_action', 'add_user_nonce', true, false) . '
        <input type="hidden" name="action" value="add_user">
        
        <div class="form-row">
            <label for="user_email">メールアドレス</label>
            <input type="email" id="user_email" name="user_email" value="' . (isset($_GET['check_email']) ? esc_attr($_GET['check_email']) : '') . '" required>
        </div>
        
        <div class="form-row checkbox-row">
            <label>
                <input type="checkbox" name="force_create" value="1"> 重複を許可して作成（既存ユーザーがいる場合でも登録処理を続行）
            </label>
        </div>
        
        <div class="form-row">
            <button type="submit" class="button-primary">ユーザーを追加</button>
        </div>
    </form>
    </div>';
    
    return $output;
}
add_shortcode('simple_user_add', 'simple_user_add_shortcode');



/**
 * 修正6: デバッグ情報の拡張 - 開発中のみ有効にすることを推奨
 */
function debug_pointcard_admin_info() {
    if (!current_user_can('administrator')) {
        return;
    }
    
    // 現在のユーザー情報
    $current_user_id = get_current_user_id();
    $current_user = get_userdata($current_user_id);
    
    echo '<div style="background: #f0f0f0; border: 1px solid #ddd; padding: 10px; margin: 10px 0; font-family: monospace;">';
    echo '<h3>デバッグ情報</h3>';
    echo '<p>現在のユーザーID: ' . $current_user_id . '</p>';
    echo '<p>ユーザー名: ' . $current_user->user_login . '</p>';
    echo '<p>メールアドレス: ' . $current_user->user_email . '</p>';
    
    // スタンプ画像の取得試行 - 実際の関数を呼び出し結果を表示
    $stamp_image = get_stamp_image_for_admin($current_user_id);
    echo '<p>スタンプ画像: ' . $stamp_image . '</p>';
    
    // すべての管理者の一覧とスタンプのマッピング結果
    $admins = get_users(array('role' => 'administrator'));
    echo '<h4>管理者スタンプマッピング</h4>';
    echo '<ul>';
    foreach ($admins as $admin) {
        $admin_stamp = get_stamp_image_for_admin($admin->ID);
        echo '<li>ID:' . $admin->ID . ' - ' . $admin->user_login . ' (' . $admin->user_email . ') - スタンプ: ' . $admin_stamp . '</li>';
    }
    echo '</ul>';
    
    echo '</div>';
}


// 管理者ページにデバッグ情報を表示
function add_debug_to_pointcard_admin($content) {
    if (has_shortcode($content, 'pointcard_admin')) {
        return debug_pointcard_admin_info() . $content;
    }
    return $content;
}
add_filter('the_content', 'add_debug_to_pointcard_admin');