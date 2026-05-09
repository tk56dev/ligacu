# ligacu Xserver通常レンタルサーバー版

このフォルダは、通常のXserverレンタルサーバーの `ligacu.com/public_html` にアップロードして、LP、予約、Stripe決済、管理画面を動かすためのPHP/MySQL版です。

Node.js、Express、SQLite、`npm start` は使いません。

## アップロードする場所

`xserver-public_html` の中身を、Xserverの以下へアップロードします。

```text
ligacu.com/public_html/
```

アップロード後の形:

```text
public_html/
  index.html
  assets/
  success.html
  cancel.html
  .htaccess
  api/
  admin/
  stripe/
  config/
  sql/
```

## MySQL作成

XserverのサーバーパネルでMySQLデータベースとMySQLユーザーを作成します。

作成後、phpMyAdminで以下のSQLを実行してください。

```text
public_html/sql/schema.sql
```

作成されるテーブル:

- `availability_slots`
- `bookings`

## 設定ファイル

`public_html/config/config.example.php` をコピーして `config.php` を作成します。

```text
public_html/config/config.php
```

設定例:

```php
<?php

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'YOUR_DATABASE_NAME',
        'user' => 'YOUR_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'stripe' => [
        'secret_key' => 'sk_live_xxx',
        'webhook_secret' => 'whsec_xxx',
        'product_id' => '',
    ],
    'app' => [
        'base_url' => 'https://ligacu.com',
        'admin_user' => 'admin',
        'admin_password' => 'CHANGE_ME',
    ],
];
```

`config/` は `.htaccess` で外部アクセスを拒否していますが、本番では強い管理パスワードを設定してください。

## Stripe設定

Stripe Dashboardの本番環境でWebhook endpointを作成します。

```text
https://ligacu.com/stripe/webhook.php
```

送信イベント:

```text
checkout.session.completed
checkout.session.expired
```

作成後に表示される `whsec_...` を `config/config.php` の `webhook_secret` に設定します。

`product_id` は任意です。設定する場合はLive環境で作成した `prod_...` だけを使ってください。テスト環境の `prod_...` をLive Secret Keyと一緒に使うとCheckout作成に失敗します。空文字のままでも、Checkoutには商品名 `ligacu Recovery Session 90min` が表示されます。

## 管理画面

```text
https://ligacu.com/admin/
```

Basic認証のユーザー名とパスワードは `config/config.php` の以下です。

```php
'admin_user' => 'admin',
'admin_password' => 'CHANGE_ME',
```

管理画面でできること:

- 空き枠追加
- 10分刻みの開始/終了時間選択
- 価格設定
- 空き枠一覧
- 予約済み枠の確認
- 予約者情報の確認
- 空き枠削除

## 管理画面にログインできない場合

XserverのPHP実行方式によってBasic認証ヘッダーの渡り方が異なるため、以下も同梱しています。

```text
public_html/admin/.htaccess
public_html/admin/auth-check.php
```

まず以下へアクセスしてください。

```text
https://ligacu.com/admin/auth-check.php
```

正しいID/パスワードで `admin auth ok` と表示されれば、認証は通っています。
それでも `/admin/` に入れない場合はブラウザ側の保存済みBasic認証情報を消すか、シークレットウィンドウで再ログインしてください。

`auth-check.php` でも弾かれる場合は、`config/config.php` の以下に余分な空白や全角文字が入っていないか確認してください。

```php
'admin_user' => 'admin',
'admin_password' => 'CHANGE_ME',
```

## 予約確定ルール

- LPのフォーム送信時にbookingを `pending` 作成します。
- 同時にslotを `pending` にします。
- Stripe Checkoutへ遷移します。
- `success.html` に戻っただけでは予約確定しません。
- `checkout.session.completed` Webhook受信後だけ `payment_status = paid`、`booking_status = confirmed`、slot `status = booked` にします。
- `checkout.session.expired` または30分以上放置されたpendingは `available` に戻します。

## 本番前チェック

- `config/config.php` に本番DB情報を設定した。
- StripeのLive Secret Key `sk_live_...` を設定した。
- Stripe本番Webhook Secret `whsec_...` を設定した。
- `base_url` が `https://ligacu.com` になっている。
- `admin_password` を強い値に変更した。
- phpMyAdminで `sql/schema.sql` を実行した。
- `/admin/` で空き枠追加ができる。
- LPからCheckoutへ遷移できる。
- Stripe決済後に `/admin/` で予約済みになる。

## セキュリティ設定

同梱の `.htaccess` で以下を設定しています。

- ディレクトリ一覧表示の禁止
- `.env`、`.sql`、`.md`、`.zip`、`.log`、`.sqlite` など公開不要ファイルへの直接アクセス禁止
- `config/` と `sql/` 配下の直接アクセス禁止
- `X-Content-Type-Options`、`Referrer-Policy`、`X-Frame-Options`、`Permissions-Policy`、`Content-Security-Policy`、`Strict-Transport-Security` の送信
- 管理画面のPOST操作にCSRFトークンを必須化

本番では `config/config.php`、バックアップファイル、ZIPファイルを `public_html` に置かない運用を推奨します。誤って置いても `.htaccess` で直接アクセスは拒否しますが、公開ディレクトリに秘密情報を置かない方が安全です。

## テスト環境から本番環境への移行

1. Stripe Dashboardを本番環境に切り替える。
2. 本番用のSecret Key `sk_live_...` を取得する。
3. 本番用Webhook endpointを `https://ligacu.com/stripe/webhook.php` で作成する。
4. Webhookの送信イベントに `checkout.session.completed` と `checkout.session.expired` を追加する。
5. Webhook signing secret `whsec_...` を取得する。
6. `public_html/config/config.php` の `secret_key` と `webhook_secret` を本番用に差し替える。
7. `product_id` を空文字にするか、Live環境で作成した `prod_...` に差し替える。
8. `base_url` が `https://ligacu.com` であることを確認する。
9. 管理画面で本番用の空き枠を登録する。
10. 少額または実価格で本番決済を1件実施し、Stripe支払い成功、Webhook成功、管理画面の予約済み反映を確認する。

本番移行後は、テストキー `sk_test_...`、テストWebhook secret、テスト環境の `prod_...` / `price_...` を `config/config.php` に残さないでください。
