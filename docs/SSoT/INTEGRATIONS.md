# Welcart 連携と参照一覧

本プラグインの連携方針、使用する既存 API の一覧、採用理由の正本。公式リファレンス全体の複製ではなく、今回の計算・表示・保存に関係する候補から参照できる入口とする。

## 確認状況と一次資料

- 確認日: 2026-09-07。
- 実装対象版: [技術構成と運用](TECH_STACK_AND_OPERATIONS.md) の Welcart 2.12.1。
- 公式入口: [関数一覧](https://www.welcart.com/documents/archives/functions)、[フック一覧](https://www.welcart.com/documents/archives/hooks)。下表は各個別ページを確認した内容。
- 公開ページは特定版に固定したソースではない。下表のファイル名は文書上の案内で、対象版の行番号・引数・実行順序・挙動は未照合。
- 現在の `plugin/` は `index.php` のみ。プラグインヘッダー、割引処理、Welcart 呼出しは未実装。**使用中・採用済みの Welcart 関数／フック／フィルタは 0 件**。
- ローカルの起動中コンテナに本プロジェクトの環境は確認できず、今回、起動・注文操作・実コードの読取りは行っていない。既存の環境準備 PASS と今回の連携確認は別の証拠である。

## 候補の一覧

以下はすべて候補であり、利用が決まったものではない。記載した限界を理由に機能全体を不可能と判断するものでもない。

| 関数・フック | 調べる用途 | 文書上の場所／単独では満たさないこと |
|---|---|---|
| [wel_get_cart()](https://www.welcart.com/documents/archives/functions/wel_get_cart) | カートの商品データ取得 | `includes/purchase/wel-purchase-functions.php`。SESSION の配列。単価・数量・税のキーは対象版で確認する。 |
| [usces_total_price($out)](https://www.welcart.com/documents/archives/functions/usces_total_price) | 購入時のカート金額取得 | `functions/functions.php`。税別設定では税を含まないとの説明。送料・手数料・値引きの包含と取得時点を確認せず対象小計に採用しない。 |
| [usces_order_discount](https://www.welcart.com/documents/archives/functions/usces_order_discount) | 既存の注文値引き表示・取得経路 | `template_func.php`、SESSION 由来。ページの使用例が別関数 `usces_item_discount` を示すため、署名は転記せずソース確認する。 |
| [usces_get_entries()](https://www.welcart.com/documents/archives/functions/usces_get_entries) | 購入手続き中の情報取得 | `functions.php`。グローバルへのセットであり、受注保存用 hook ではない。 |
| [usces_get_cart_rows($out)](https://www.welcart.com/documents/archives/functions/usces_get_cart_rows) / [usces_filter_cart_rows($cart_row, $cart)](https://www.welcart.com/documents/archives/hooks/usces_filter_cart_rows) | カートの商品行・表示確認 | `template_func.php`。HTML の変更だけでは請求額や受注保存を変えられない。 |
| [usces_get_confirm_rows($out)](https://www.welcart.com/documents/archives/functions/usces_get_confirm_rows) / [usces_filter_confirm_rows($cart_row, $cart)](https://www.welcart.com/documents/archives/hooks/usces_filter_confirm_rows) | 確認画面の商品行・表示確認 | `template_func.php`。表示経路と金額の保存経路を区別する。 |
| [usces_internal_tax($args, $out)](https://www.welcart.com/documents/archives/functions/usces_internal_tax) | 値引き等を考慮する内税計算 | `template_func.php`、1.4 導入との記載。外税・保存・決済への反映を一括保証しない。 |
| [usces_filter_internal_tax_total($total, $materials)](https://www.welcart.com/documents/archives/hooks/usces_filter_internal_tax_total) / [usces_filter_internal_tax($tax, $materials)](https://www.welcart.com/documents/archives/hooks/usces_filter_internal_tax) | 内税対象額／内税額の変更点 | `template_func.php`。材料に値引き・ポイント等があるが、これだけで全税設定の正しさを保証しない。 |
| [usces_cart_tax($out)](https://www.welcart.com/documents/archives/functions/usces_cart_tax) | カート税表示の調査 | `template_func.php`。文書上は値引き・送料・手数料を含まない。割引後税額の正本には単独で不十分。 |
| [usces_tax($usces_entries, $out)](https://www.welcart.com/documents/archives/functions/usces_tax) / [usces_confirm_tax()](https://www.welcart.com/documents/archives/functions/usces_confirm_tax) | 確認画面の税・税率別表示 | `template_func.php`。前者は確認画面時点の税額、後者は軽減税率設定に関係する表示。 |
| [wel_get_order($order_id)](https://www.welcart.com/documents/archives/functions/wel_get_order) / [usces_get_ordercartdata($order_id)](https://www.welcart.com/documents/archives/functions/usces_get_ordercartdata) | 確定後の受注・明細の読取り確認 | 前者は `includes/order/wel-order-functions.php`（履歴 2.2.2）、後者は `functions.php`。保存 hook ではない。 |
| [usces_filter_send_order_mail_meisai($msg_meisai, $data, $cart, $entry)](https://www.welcart.com/documents/archives/hooks/usces_filter_send_order_mail_meisai) | 注文メールの明細確認 | 文書上 `function.php`。メール文字列を変えても受注保存・決済金額は整合しない。 |

## 表示に関する追加候補

商品 1 行単位の表示には [usces_filter_cart_row](https://www.welcart.com/documents/archives/hooks/usces_filter_cart_row) と [usces_filter_confirm_row](https://www.welcart.com/documents/archives/hooks/usces_filter_confirm_row)、税の表示文字列には [usces_filter_tax](https://www.welcart.com/documents/archives/hooks/usces_filter_tax)、管理メールの明細には [usces_filter_order_confirm_mail_meisai](https://www.welcart.com/documents/archives/hooks/usces_filter_order_confirm_mail_meisai) がある。いずれも金額計算や保存の代替にはしない。

[usces_get_payments_by_name](https://www.welcart.com/documents/archives/functions/usces_get_payments_by_name) は支払方法設定の取得候補。[usces_filter_settle_info_field_value](https://www.welcart.com/documents/archives/hooks/usces_filter_settle_info_field_value) は受注編集の決済情報表示に関するもので、決済へ渡す請求額を変える根拠にはならない。

## 実装前に確定する接続経路

1. 商品単価・数量から、仕様どおりの対象小計を取得する箇所。税込価格設定と標準ポイント利用時も確認する。
2. 割引額を既存注文値引きへ渡す hook／filter、その戻り値・符号・実行時点。表示用関数名から推測しない。
3. 値引きから税額・ポイント利用可能額・請求額へ進む順序と、複数税率への配分。
4. 購入確認から受注保存までの金額受渡し、設定変更時の再確認、過去注文の復元に使う保存項目。
5. 受注データから決済・注文メール・履歴へ進む経路。本プラグインが改修しない経路も影響を確認する。

この順序は調査項目の依存関係であり、未確認の Welcart の実行順序を表すものではない。採用後の各記録には、対象版のソース位置、登録箇所、引数と戻り値、用途、選定理由、比較候補、検証結果を含める。現時点では金額保存を担う接続を決めていない。

## WordPress の参照先

設定保存は [Options API](https://developer.wordpress.org/apis/options/)、設定画面・保存保護は [Settings API](https://developer.wordpress.org/plugins/settings/settings-api/) とローカルの `wp-plugin-development` Skill を参照する。保存構造の正本は [データモデル](DATA_MODEL.md)。WordPress の具体的な使用関数も実装後に登録箇所と用途を一覧化する。
