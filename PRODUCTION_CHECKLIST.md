# ligacu 本番移行チェックリスト

テスト決済から本番決済へ切り替えるための確認項目です。

## 1. Stripe本番設定

- Stripe Dashboardを本番環境に切り替える。
- 本番用Secret Key `sk_live_...` を取得する。
- Webhook endpointを作成する。

```text
https://ligacu.com/stripe/webhook.php
```

- Webhookイベントは以下だけを有効にする。

```text
checkout.session.completed
checkout.session.expired
```

- Webhook signing secret `whsec_...` を取得する。
- テスト環境の `sk_test_...`、`prod_...`、`price_...` を本番設定に混ぜない。

## 2. Xserver設定

`public_html/config/config.php` を本番値に更新する。

```php
'stripe' => [
    'secret_key' => 'sk_live_xxx',
    'webhook_secret' => 'whsec_xxx',
    'product_id' => '',
],
'app' => [
    'base_url' => 'https://ligacu.com',
    'admin_user' => '任意の管理ユーザー名',
    'admin_password' => '強いパスワード',
],
```

`product_id` は空文字で問題ありません。設定する場合は、Live環境で作成した `prod_...` だけを使います。

## 3. DB確認

- `availability_slots` テーブルが存在する。
- `bookings` テーブルが存在する。
- 管理画面から本番用の空き枠を登録できる。
- 不要なテスト予約データが本番DBに残っていない。

## 4. 本番テスト

- LPで空き枠が表示される。
- 予約フォーム入力後、Stripe Checkoutへ遷移する。
- Checkout画面が本番決済モードになっている。
- 決済完了後、`success.html` に戻る。
- Stripe Dashboardで支払いが成功している。
- Stripe Webhookの送信結果が成功になっている。
- 管理画面で対象枠が「予約済み」になっている。
- 公式LINE導線からカウンセリング回答まで進める。

## 5. 公開後の運用

- 空き枠は管理画面で登録する。
- 現金決済は受け付けない。
- 予約確定はWebhook成功後だけとする。
- Stripe DashboardでWebhook失敗が出ていないか定期確認する。
- 管理画面パスワードは外部共有しない。
