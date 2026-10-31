# NexaPress Mail

> 最新バージョン: 1.1.1（2026-10-31）。ローカルの未公開変更を反映し、ソースと配布物を揃えた同期リリースです。

NexaPress 2.3.0の管理画面に、SMTP送信・IMAP受信対応のメールクライアントを追加する拡張機能です。複数アカウント、4フォルダ、返信・全員に返信・転送、検索、添付ファイルに加え、Gmail／Microsoft 365のOAuth認証、差分・過去メール同期、自動受信、下書き自動保存・復元を提供します。

## 動作要件

- NexaPress 2.3.0
- PHP 8.1以上（8.2推奨）
- PHP拡張: `curl`, `imap`, `openssl`, `mbstring`, `dom`, `fileinfo`, `json`, `pdo_mysql`
- MySQL 5.7以上またはMariaDB 10.4以上
- SMTP・IMAPサーバーへの外向き通信
- `storage/private` をWebサーバーユーザーが作成・読み書きできること
- 添付を使用する場合は、PHPの `upload_tmp_dir` が書込可能であること。10MiB×10件を送る構成では `upload_max_filesize=10M`、`post_max_size=110M`、`max_file_uploads=10` 以上を設定してください。

SMTPには [PHPMailer](https://github.com/PHPMailer/PHPMailer) 6.12.0（LGPL-2.1-only）をComposerで導入しています。拡張本体はMIT Licenseです。PHPMailerのライセンスは `vendor/phpmailer/phpmailer/LICENSE` に同梱されます。

## インストール

1. `nexapress-mail-1.1.1.zip` を取得します。
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
- 更新後の初回起動時に保存済みHTML本文を100件ずつ再処理します。表示・下書き取得時と保存時にも安全化します。
- SMTP・IMAPはSSL/TLSまたはSTARTTLSが必須で、サーバー証明書を検証します。既存の「暗号化なし」アカウントは、設定を変更するまで接続・送受信できません。
- 暗号鍵は確認から生成まで排他制御し、既存の鍵を上書きしません。`credential.lock` はロック用ファイルとして保持します。
- ヘッダー改行を拒否し、危険な実行形式の添付を保存・送信しません。
- ログにはパスワード、本文、添付内容を記録しません。

## メールサーバー設定

プロバイダーが案内するホスト、ポート、暗号化方式、ユーザー名、アプリパスワードを使用してください。多要素認証を利用している場合、通常のログインパスワードでは接続できないことがあります。フォルダ名はサーバーによって `Sent`, `Sent Items`, `INBOX.Sent` など異なります。

初回の通常同期は、各アカウント・各フォルダの最新100件を取得します。以後は最後のUIDより新しいメールを100件ずつ取得し、未取得分を次回へ持ち越します。「過去メールを追加取得」では開始日・終了日（両端を含む）を指定でき、同じ期間で繰り返すと過去へ100件ずつ遡ります。空欄は期間指定なしです。取得位置はアカウント・フォルダごとにDB保存されます。期間を変更すると過去取得の位置がリセットされ、新着用の位置は維持されます。

既読・スター・削除状態も各フォルダ200件ずつ巡回して確認します。サーバー上で削除・移動された元メールは通常一覧から非表示にし、キャッシュを残します。移動先の取得時にはMessage-ID・差出人・件名が一致するキャッシュを再利用します。UIDVALIDITYや接続先が変わった場合は古いUIDとの対応を解除して再取得するため、別メールを誤操作しません。取得件数と状態確認件数は `config/limits.php` で変更できます。

旧版からの初回移行時は、古い受信・送信キャッシュを一覧に残したままUIDとの対応を解除し、取得したメールから対応を確認し直します。送信済みメールのキャッシュを再利用する際も、ローカルで保持していたBCC宛先を残します。

受信時はメール全体25MiB（転送エンコードを含む）、本文合計2MiB（UTF-8変換後）、添付1件10MiB・合計20MiB・10件、MIME構造100パート・20階層を上限とします。取得前にサイズと構造を検査し、実データも上限付き一時ファイルへ取得して検証します。上限超過メールはローカル保存せず、ログと同期結果に除外件数を表示して次のメールへ進みます。元メールはサーバーに残ります。上限は `config/limits.php` で変更できます。

## OAuth 2.0の設定

1. Google CloudまたはMicrosoft EntraでWebアプリを登録し、クライアントIDとシークレットの値を用意します。コールバックは `https://サイトのURL/admin/extensions/nexapress-mail/oauth/callback` です。CMSがサブディレクトリにある場合はそのパスも含め、アプリ登録と設定画面に同じHTTPS URLを指定します。
2. アカウントの認証方式を「OAuth 2.0」にし、プロバイダーとアプリ情報を設定します。「メールサーバーの推奨設定を入力」で接続先を入力できます。フォルダ名は実際のサーバー設定に合わせて変更してください。
3. アカウントを保存し、「OAuthに接続／再認証」で本人の同意を完了します。SMTP／IMAP接続テスト後に同期します。

Googleは `https://mail.google.com/`、MicrosoftはExchange Onlineの委任スコープ `IMAP.AccessAsUser.All`・`SMTP.Send` と `offline_access` を要求します。組織のアプリ同意・公開設定、SMTP AUTHとIMAPの利用許可も確認してください。設定の詳細は [GoogleのOAuth手順](https://developers.google.com/identity/protocols/oauth2/web-server) と [Microsoftのメール用OAuth手順](https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth) を参照してください。

認証はセッションに結び付けた使い捨てstateとPKCEを使用します。シークレット・アクセス／更新トークンは暗号化してDB保存し、期限前に更新します。アプリ設定を変更すると接続トークンを無効化します。「このアプリの接続を解除」はローカルのトークンを削除します。プロバイダー側の同意解除は各社のアカウント管理画面で行ってください。OAuthトークンの送信先は選択したプロバイダーの公式メールサーバーに制限しています。

## 自動受信

アカウントで「自動受信を有効にする」と受信間隔（初期値300秒）を設定し、サーバーのcronまたはWindowsタスクスケジューラで次のCLIを1分ごとに実行します。CLI版PHPにも上記PHP拡張とDB・ストレージへのアクセス権が必要です。

```bash
php /var/www/NexaPress/extensions/nexapress-mail/bin/sync.php --base-path=/var/www/NexaPress
```

cronの例:

```cron
* * * * * /usr/bin/php /var/www/NexaPress/extensions/nexapress-mail/bin/sync.php --base-path=/var/www/NexaPress
```

Windowsタスクスケジューラはプログラムを `php.exe`、引数を `"E:\NexaPress\extensions\nexapress-mail\bin\sync.php" --base-path="E:\NexaPress"` に設定します。パスは実際の配置先に変更してください。`--account=12` で特定アカウントだけを対象にできます。CLIは自動受信が有効で実行時刻に達したアカウントだけを処理し、管理画面を開く必要はありません。WebからCLIを直接実行することはできません。

手動実行と定期実行は同じアカウントのロックを共有します。失敗すると60秒から最大3600秒まで間隔を延ばして再試行し、成功すると設定間隔に戻ります。概要画面には実行中・完了・失敗、最終完了時刻、次回予定を表示し、失敗理由を操作ログへ記録します。OSの定期実行設定は、このZIPを配置するだけでは作成されません。

## 下書き自動保存と入力復元

入力変更から約1.5秒後と、変更が残っている間は5秒ごとに下書きをサーバーへ保存します。宛先の入力途中でも保存できます。下書きIDをURLに反映するため、保存後は再読み込みや下書き一覧から再開できます。添付は自動保存後にサーバー側の一覧へ移し、本文だけの更新で消えません。添付の削除も保存時に反映します。

通信中断時は、このタブのsessionStorageにもテキスト入力を保持し、再読み込み後に復元ボタンを表示します（最大24時間、ブラウザーの保存機能が利用できる場合）。未保存の添付はブラウザーの制約により復元できないため再選択が必要です。送信失敗時は先に保存した本文と添付を下書きから再表示します。複数画面の同時編集はリビジョンで検出し、古い入力による上書きを止めます。送信・手動保存が完了した入力のタブ内バックアップは削除します。

## 開発とテスト

```bash
composer install
php tests/run.php
php build.php
```

`build.php` は `dist/nexapress-mail-1.1.1.zip` を作り、先頭エントリ、除外対象、秘密情報らしいファイル名を検査します。実メールサーバーへの接続はCIでは行いません。

Playwrightが利用できる開発環境では `node tests/browser.cjs` でブラウザーの保存・復元・添付・送信失敗・競合・OAuth設定画面を検証できます。必要に応じて `NPM_PHP_BINARY` と `NPM_BROWSER_EXECUTABLE` で実行ファイルを指定してください。このテストは一時的なSQLite DBとSMTP失敗用のスタブを使い、実メールは送信しません。

## 更新カタログ

CMS側の記述は次で正しく、スペルも拡張ID・Release資産名と一致しています（前の項目があるJSONでは先頭カンマが必要です）。

```json
"manifest_url": "https://raw.githubusercontent.com/aki-sho/nexapress-mail/main/manifest.json",
"download_url": "https://github.com/aki-sho/nexapress-mail/releases/download/v1.1.1/nexapress-mail-1.1.1.zip"
```

## 制限事項

- OAuth接続には各プロバイダーでのアプリ登録と本人の同意が必要です。
- 自動受信にはサーバー側の定期実行登録が必要です。
- サーバー固有のフォルダ自動検出は行わないため、設定画面で実名を指定してください。
