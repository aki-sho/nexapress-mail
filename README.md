# NexaPress Mail

NexaPress 2.3.0の管理画面に、SMTP送信・IMAP受信対応のメールクライアントを追加する拡張機能です。複数アカウント、4フォルダ、返信・全員に返信・転送、検索、絞り込み、ページ分割、添付ファイル、接続テスト、手動同期、操作ログを提供します。

## 動作要件

- NexaPress 2.3.0
- PHP 8.1以上（8.2推奨）
- PHP拡張: `imap`, `openssl`, `mbstring`, `dom`, `fileinfo`, `json`, `pdo_mysql`
- MySQL 5.7以上またはMariaDB 10.4以上
- SMTP・IMAPサーバーへの外向き通信
- `storage/private` をWebサーバーユーザーが作成・読み書きできること

SMTPには [PHPMailer](https://github.com/PHPMailer/PHPMailer) 6.12.0（LGPL-2.1-only）をComposerで導入しています。拡張本体はMIT Licenseです。PHPMailerのライセンスは `vendor/phpmailer/phpmailer/LICENSE` に同梱されます。

## インストール

1. Releaseの `nexapress-mail-1.0.0.zip` を取得します。
2. NexaPressの「拡張機能」からZIPをアップロードし、有効化します。
3. 「メール」→「アカウント」でSMTP・IMAP情報とサーバー上のフォルダ名を登録します。
4. SMTP／IMAP接続テスト後、「手動同期」を実行します。

ZIPを手動展開する場合は、`extensions/nexapress-mail/manifest.json` となるよう配置してください。依存ライブラリはZIPに含まれるため、本番環境でComposerを実行する必要はありません。

## セキュリティと保存場所

- SMTP・IMAPパスワードは、ランダム生成した256-bit鍵によるAES-256-GCMで暗号化してDB保存します。
- 鍵と添付ファイルは公開領域外の `NexaPress/storage/private/nexapress-mail/` に保存します。
- 鍵ファイルを失うと既存パスワードは復号できません。DBバックアップと同時に安全な場所へバックアップしてください。
- 全管理操作で `admin.manage` 権限を確認し、全POSTでNexaPressのCSRFトークンを検証します。
- HTMLメールは許可リスト方式で安全化し、イベント属性、スクリプト、フォーム、iframe、外部画像を除去します。
- ヘッダー改行を拒否し、危険な実行形式の添付を保存・送信しません。
- ログにはパスワード、本文、添付内容を記録しません。

## メールサーバー設定

プロバイダーが案内するホスト、ポート、暗号化方式、ユーザー名、アプリパスワードを使用してください。多要素認証を利用している場合、通常のログインパスワードでは接続できないことがあります。フォルダ名はサーバーによって `Sent`, `Sent Items`, `INBOX.Sent` など異なります。

1回の手動同期では、各アカウント・各フォルダにつき最新100件を確認します。既に取得済みのIMAP UIDは重複保存しません。大量メールの初回同期は複数回では過去へ遡らないため、必要に応じて `config/limits.php` の `sync_limit` を調整してください。

## 開発とテスト

```bash
composer install
php tests/run.php
php build.php
```

`build.php` は `dist/nexapress-mail-1.0.0.zip` を作り、先頭エントリ、除外対象、秘密情報らしいファイル名を検査します。実メールサーバーへの接続はCIでは行いません。

## 更新カタログ

CMS側の記述は次で正しく、スペルも拡張ID・Release資産名と一致しています（前の項目があるJSONでは先頭カンマが必要です）。

```json
"manifest_url": "https://raw.githubusercontent.com/aki-sho/nexapress-mail/main/manifest.json",
"download_url": "https://github.com/aki-sho/nexapress-mail/releases/download/v1.0.0/nexapress-mail-1.0.0.zip"
```

## 制限事項

- OAuth 2.0（Gmail／Microsoft 365のXOAUTH2）は1.0.0では未対応です。アプリパスワードまたはSMTP/IMAP基本認証が許可されたアカウントを使用してください。
- 同期は管理画面からの手動実行です。
- サーバー固有のフォルダ自動検出は行わないため、設定画面で実名を指定してください。
