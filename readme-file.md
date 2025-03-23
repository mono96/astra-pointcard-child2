# WordPressポイントカードプラグイン修正マニュアル

## 修正の概要

このマニュアルは、QRコードスキャン時の管理者スタンプ画像表示問題を解決し、ポイントカードプラグインをシンプル化するための修正手順を示しています。

### 主な修正点

1. 管理者IDとスタンプ画像のマッピングを修正
2. QRコード処理のシンプル化（トップページへのリンク）
3. スタンプ表示ロジックの明確化

## 修正手順

### 1. get_stamp_image_for_admin() 関数の修正

この関数は、管理者IDから適切なスタンプ画像URLを取得するためのものです。以下のコードに置き換えてください：

```php
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
```

### 2. display_qr_code_section() 関数の修正

顧客のQRコード表示部分を改善します。以下のコードに置き換えてください：

```php
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
```

### 3. display_qr_scanner_section() 関数の修正

管理者用QRコードスキャナー部分を改善します。以下のコードに置き換えてください：

```php
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
```

### 4. トークン処理用の新規関数追加

`process_pointcard_actions()`関数の前に以下の新規関数を追加してください：

```php
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
```

### 5. process_pointcard_actions() 関数の修正

ポイント追加処理部分を改善します。既存の関数の前半部分を以下のように修正してください：

```php
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
    
    // 特典交換処理は既存のコードをそのまま使用
```

### 6. get_admin_stamps_for_user() 関数の修正

スタンプ表示ロジックを改善します。以下のコードに置き換えてください：

```php
function get_admin_stamps_for_user($post_id) {
    $stamps_data = array();
    
    // JSONからポイント履歴を取得
    $point_history = get_point_history($post_id);
    
    // 履歴を日付順に逆順にソート（最新のものから）
    usort($point_history, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
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
```

## 設定と確認事項

1. WordPressの管理画面でトップページに `[pointcard_admin]` ショートコードが配置されていることを確認してください。
2. 修正後、各管理者アカウントでログインし、実際にポイント追加を行ってスタンプが正しく表示されるか確認してください。
3. デバッグが必要な場合は、`debug_pointcard_admin_info()` 関数を使用して管理者情報とスタンプマッピングを確認できます。

## トラブルシューティング

- スタンプが正しく表示されない場合:
  - 管理者IDとスタンプURLのマッピングが正確か確認
  - ログファイルでエラーメッセージを確認（WP_DEBUG=trueに設定）
  - ポイント履歴のJSONデータ構造を確認

- QRコードがスキャンできない場合:
  - スマートフォンのカメラ設定を確認
  - QRコードの表示サイズが十分か確認
  - トップページに [pointcard_admin] ショートコードが配置されているか確認

この修正により、QRコードスキャン時の管理者スタンプ表示問題が解決し、よりシンプルで使いやすいポイントカードシステムになります。
