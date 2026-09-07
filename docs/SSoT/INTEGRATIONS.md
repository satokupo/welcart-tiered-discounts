# Welcart 連携と参照一覧

本プラグインの連携契約と、計算・表示・受注に関係する公式 API の参照先を管理する。割引の振る舞いは [機能仕様](FUNCTIONAL_SPEC.md)、保存する情報は [データモデル](DATA_MODEL.md) を正本とする。

## 連携契約と一次資料

- 実装対象版: [技術構成と運用](TECH_STACK_AND_OPERATIONS.md) の Welcart 2.12.1。
- Welcart との接続は既存フック・フィルタを使い、本体・テーマを改変しない。
- カート、購入確認、受注データには同じ割引計算の結果を反映する。表示文字列の変更だけでは金額の整合を満たさない。
- 確定した受注金額を、現在の割引設定で再計算しない。決済・メール・履歴にも対応する注文の金額を使う。
- 公式入口: [関数一覧](https://www.welcart.com/documents/archives/functions)、[フック一覧](https://www.welcart.com/documents/archives/hooks)。下表のファイル名と用途は各個別ページの記載に基づく。
- 公開リファレンスは版を固定したソースではない。対象版のソースが引数・戻り値・呼出し時点の確認元となる。

## 公式 API の参照一覧

本表は公式に公開される API の用途を示す。実装での採用一覧とは区別し、表示・計算・読取りの責務を明記する。

| 関数・フック | 公式に記載された用途 | 文書上の場所と責務 |
|---|---|---|
| [wel_get_cart()](https://www.welcart.com/documents/archives/functions/wel_get_cart) | カートの商品データ取得 | `includes/purchase/wel-purchase-functions.php`。SESSION 内のカートを配列で返す。 |
| [usces_total_price($out)](https://www.welcart.com/documents/archives/functions/usces_total_price) | 購入時のカート金額取得 | `functions/functions.php`。税別設定では税を含まない。 |
| [usces_order_discount](https://www.welcart.com/documents/archives/functions/usces_order_discount) | キャンペーンモードの注文値引き取得・表示 | `template_func.php`、SESSION 由来。公式ページの使用例は別関数 `usces_item_discount` を記載しているため、本表では署名を示さない。 |
| [usces_get_entries()](https://www.welcart.com/documents/archives/functions/usces_get_entries) | 購入手続き中の情報取得 | `functions.php`。グローバルへのセットであり、受注保存用 hook ではない。 |
| [usces_get_cart_rows($out)](https://www.welcart.com/documents/archives/functions/usces_get_cart_rows) / [usces_filter_cart_rows($cart_row, $cart)](https://www.welcart.com/documents/archives/hooks/usces_filter_cart_rows) | カートの商品行・表示確認 | `template_func.php`。HTML の変更だけでは請求額や受注保存を変えられない。 |
| [usces_get_confirm_rows($out)](https://www.welcart.com/documents/archives/functions/usces_get_confirm_rows) / [usces_filter_confirm_rows($cart_row, $cart)](https://www.welcart.com/documents/archives/hooks/usces_filter_confirm_rows) | 確認画面の商品行・表示確認 | `template_func.php`。表示経路と金額の保存経路を区別する。 |
| [usces_internal_tax($args, $out)](https://www.welcart.com/documents/archives/functions/usces_internal_tax) | 値引き等を考慮する内税計算 | `template_func.php`、1.4 導入との記載。外税・保存・決済への反映を一括保証しない。 |
| [usces_filter_internal_tax_total($total, $materials)](https://www.welcart.com/documents/archives/hooks/usces_filter_internal_tax_total) / [usces_filter_internal_tax($tax, $materials)](https://www.welcart.com/documents/archives/hooks/usces_filter_internal_tax) | 内税対象額／内税額の変更点 | `template_func.php`。材料に値引き・ポイント等があるが、これだけで全税設定の正しさを保証しない。 |
| [usces_cart_tax($out)](https://www.welcart.com/documents/archives/functions/usces_cart_tax) | カート商品合計の税額表示 | `template_func.php`。文書上は値引き・送料・手数料を含まない。割引後税額の正本には単独で不十分。 |
| [usces_tax($usces_entries, $out)](https://www.welcart.com/documents/archives/functions/usces_tax) / [usces_confirm_tax()](https://www.welcart.com/documents/archives/functions/usces_confirm_tax) | 確認画面の税・税率別表示 | `template_func.php`。前者は確認画面時点の税額、後者は軽減税率設定に関係する表示。 |
| [wel_get_order($order_id)](https://www.welcart.com/documents/archives/functions/wel_get_order) / [usces_get_ordercartdata($order_id)](https://www.welcart.com/documents/archives/functions/usces_get_ordercartdata) | 確定後の受注・明細の読取り確認 | 前者は `includes/order/wel-order-functions.php`（履歴 2.2.2）、後者は `functions.php`。保存 hook ではない。 |
| [usces_filter_send_order_mail_meisai($msg_meisai, $data, $cart, $entry)](https://www.welcart.com/documents/archives/hooks/usces_filter_send_order_mail_meisai) | 注文メールの明細確認 | 文書上 `function.php`。メール文字列を変えても受注保存・決済金額は整合しない。 |

## 表示に関する公式 API

商品 1 行単位の表示には [usces_filter_cart_row](https://www.welcart.com/documents/archives/hooks/usces_filter_cart_row) と [usces_filter_confirm_row](https://www.welcart.com/documents/archives/hooks/usces_filter_confirm_row)、税の表示文字列には [usces_filter_tax](https://www.welcart.com/documents/archives/hooks/usces_filter_tax)、管理メールの明細には [usces_filter_order_confirm_mail_meisai](https://www.welcart.com/documents/archives/hooks/usces_filter_order_confirm_mail_meisai) がある。いずれも金額計算や保存の代替にはしない。

[usces_get_payments_by_name](https://www.welcart.com/documents/archives/functions/usces_get_payments_by_name) は支払方法設定を取得する。[usces_filter_settle_info_field_value](https://www.welcart.com/documents/archives/hooks/usces_filter_settle_info_field_value) は受注編集の決済情報表示を変更する。

## API の役割と金額の整合

HTML・メール文字列のフィルタは表示を変更する。受注取得関数は保存済みデータを読む。これらの API を使うだけでは、割引額の計算、税・ポイントとの整合、受注への保存は実現しない。

税・ポイント・請求額の接続は [機能仕様](FUNCTIONAL_SPEC.md#ポイント税他の割引)、注文確認と保存の契約は [機能仕様の注文確定](FUNCTIONAL_SPEC.md#注文確定と設定変更) に従う。対象版の接続箇所を確定する調査作業は [実装前の確認](../work/todo/MVP_DESIGN_CHECKS.md) が管理する。

## WordPress の参照先

設定保存は [Options API](https://developer.wordpress.org/apis/options/)、設定画面・保存保護は [Settings API](https://developer.wordpress.org/plugins/settings/settings-api/) とローカルの `wp-plugin-development` Skill を参照する。保存構造の正本は [データモデル](DATA_MODEL.md)。WordPress の具体的な使用関数も実装後に登録箇所と用途を一覧化する。
