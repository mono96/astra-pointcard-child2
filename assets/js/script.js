/**
 * QRコードポイント追加機能スクリプト
 */
(function($) {
    'use strict';

    // HTML5 QRコードスキャナー初期化
    var html5QrCode;
    var isScanning = false;
    
    // DOM要素のキャッシュ
    var $qrReader, $scanResult, $startCamera, $stopCamera, $pointForm;
    var $userEmail, $currentPoints, $userToken, $addPointButton;
    
    // 初期化
    function init() {
        // DOM要素の取得
        $qrReader = $('#qr-reader');
        $scanResult = $('#scan-result');
        $startCamera = $('#start-camera');
        $stopCamera = $('#stop-camera');
        $pointForm = $('#point-form');
        $userEmail = $('#user-email');
        $currentPoints = $('#current-points');
        $userToken = $('#user-token');
        $addPointButton = $('#add-point-button');
        
        // イベントリスナー
        $startCamera.on('click', startCamera);
        $stopCamera.on('click', stopCamera);
        
        // 有効なトークンがURLにある場合
        var urlParams = new URLSearchParams(window.location.search);
        var token = urlParams.get('valid_token');
        
        if (token) {
            displayUserForm(token);
            updateStatus('QRコードから有効なトークンを検出しました');
        }
        
        // エラーがある場合
        var error = urlParams.get('error');
        if (error === 'invalid_token') {
            showMessage('無効なQRコードです。正しいポイントカードQRコードを使用してください。', 'error');
        }
        
        // 環境情報の表示
        $('#browser-info').text(navigator.userAgent);
        $('#device-info').text(/Mobi|Android/i.test(navigator.userAgent) ? "はい" : "いいえ");
        
        // 初期化状態を更新
        updateStatus('初期化完了 - 「カメラを開始」をクリックしてください');
        
        // QRスキャナー初期化
        if (typeof Html5Qrcode !== 'undefined') {
            setTimeout(initScanner, 1000);
        }
    }
    
    // スキャナーの初期化
    function initScanner() {
        try {
            updateStatus("スキャナーを初期化中...");
            
            if (typeof Html5Qrcode === "undefined") {
                updateStatus("エラー: スキャナーライブラリが読み込まれていません");
                return;
            }
            
            html5QrCode = new Html5Qrcode("qr-reader");
            updateStatus("スキャナー初期化完了 - 「カメラを開始」をクリックしてください");
        } catch (err) {
            updateStatus("スキャナー初期化エラー: " + err.message);
            console.error("Init error:", err);
        }
    }
    
    // カメラを開始
    function startCamera() {
        if (!html5QrCode) {
            updateStatus("スキャナーが初期化されていません");
            return;
        }
        
        if (isScanning) {
            updateStatus("すでにスキャン中です");
            return;
        }
        
        updateStatus("カメラアクセスを要求中...");
        
        var config = {
            fps: 10,
            qrbox: 250,
            aspectRatio: 1.0
        };
        
        try {
            html5QrCode.start(
                { facingMode: "environment" }, // バックカメラを優先
                config,
                onScanSuccess,
                onScanFailure
            ).then(function() {
                isScanning = true;
                updateStatus("カメラ起動完了 - QRコードをスキャンしてください");
                $startCamera.hide();
                $stopCamera.show();
            }).catch(function(err) {
                updateStatus("カメラ起動エラー: " + err.message);
                console.error("Camera start error:", err);
            });
        } catch (err) {
            updateStatus("カメラ起動例外エラー: " + err.message);
            console.error("Camera exception:", err);
        }
    }
    
    // カメラ停止
    function stopCamera() {
        if (html5QrCode && isScanning) {
            html5QrCode.stop().then(function() {
                isScanning = false;
                updateStatus("カメラを停止しました");
                $startCamera.show();
                $stopCamera.hide();
            }).catch(function(err) {
                updateStatus("カメラ停止エラー: " + err.message);
                console.error("Camera stop error:", err);
            });
        }
    }
    
    // QRコードスキャン成功時のコールバック
    function onScanSuccess(decodedText, decodedResult) {
        try {
            // デバッグ情報を表示
            console.log("スキャンされたURL:", decodedText);
            
            // HTMLエンティティをデコード
            var decodedUrl = decodedText.replace(/&amp;/g, "&");
            
            const url = new URL(decodedUrl);
            const params = new URLSearchParams(url.search);
            
            // qr_point_actionパラメータの確認（大文字小文字を区別しない）
            var hasAction = false;
            var token = "";
            
            // すべてのパラメータをチェック
            for (const [key, value] of params.entries()) {
                if (key.toLowerCase() === "qr_point_action" && value.toLowerCase() === "add") {
                    hasAction = true;
                }
                if (key.toLowerCase() === "token") {
                    token = value;
                }
            }
            
            if (hasAction && token) {
                // スキャン停止
                stopCamera();
                
                // ユーザー情報を表示
                displayUserForm(token);
                updateStatus("QRコードのスキャンに成功しました");
            } else {
                updateStatus("このQRコードは有効なポイントカードコードではありません");
                showMessage(
                    "このQRコードは有効なポイントカードコードではありません。<br>" +
                    "URL: " + decodedText + "<br>" +
                    "デコード後: " + decodedUrl + "<br>" +
                    "アクション: " + (hasAction ? "有効" : "無効") + "<br>" +
                    "トークン: " + (token ? "有効" : "無効"),
                    "error"
                );
            }
        } catch (e) {
            updateStatus("QRコードの解析に失敗しました: " + e.message);
            showMessage(
                "QRコードの解析に失敗しました。<br>" +
                "URL: " + decodedText + "<br>" +
                "エラー: " + e.message,
                "error"
            );
        }
    }
    
    // QRコードスキャン失敗時のコールバック
    function onScanFailure(error) {
        // スキャン処理中のエラーは無視（ログのみ）
        console.log("Scan failure:", error);
    }
    
    // ユーザー情報とポイント追加フォームを表示
    function displayUserForm(token) {
        $userToken.val(token);
        $qrReader.hide();
        $pointForm.show();
        
        // Ajax呼び出しでユーザー情報を取得
        $.ajax({
            type: "POST",
            url: qr_point_vars.ajax_url,
            data: {
                action: "qr_point_get_user",
                nonce: qr_point_vars.nonce,
                token: token
            },
            success: function(response) {
                if (response.success) {
                    $userEmail.text(response.data.email);
                    $currentPoints.text(response.data.points);
                    
                    // ポイント追加ボタンのイベント設定
                    $addPointButton.off('click').on('click', function(e) {
                        e.preventDefault();
                        addPoint(token);
                    });
                } else {
                    $userEmail.text("エラー: " + response.data);
                    $currentPoints.text("N/A");
                }
            },
            error: function() {
                $userEmail.text("通信エラー");
                $currentPoints.text("N/A");
            }
        });
    }
    
    // ポイント追加処理
    function addPoint(token) {
        const points = $('#points').val();
        const memo = $('#memo').val();
        
        $addPointButton.prop("disabled", true).text("処理中...");
        
        $.ajax({
            type: "POST",
            url: qr_point_vars.ajax_url,
            data: {
                action: "qr_point_add_point",
                nonce: qr_point_vars.nonce,
                token: token,
                points: points,
                memo: memo
            },
            success: function(response) {
                if (response.success) {
                    showMessage("ポイントを追加しました！ 新しいポイント: " + response.data.new_points, "success");
                    
                    // 2秒後にページをリロード
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    showMessage("エラー: " + response.data, "error");
                    $addPointButton.prop("disabled", false).text("ポイント追加");
                }
            },
            error: function() {
                showMessage("サーバーとの通信に失敗しました。", "error");
                $addPointButton.prop("disabled", false).text("ポイント追加");
            }
        });
    }
    
    // メッセージ表示関数
    function showMessage(message, type) {
        $scanResult.html(`<div class="message-box ${type}">${message}</div>`).show();
    }
    
    // ステータス更新
    function updateStatus(message) {
        $('#status-message').text(message);
        console.log("Scanner status:", message);
    }
    
    // DOMの準備ができたら初期化
    $(document).ready(function() {
        // QRスキャナーページの場合のみ初期化
        if ($('#qr-reader').length > 0) {
            init();
        }
    });

})(jQuery);
