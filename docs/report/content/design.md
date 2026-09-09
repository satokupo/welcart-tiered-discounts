# 設計メモ

## 開発中に作成した設計資料

開発中にAIと作成し、実装のもとにした設計資料は、次の2つのIssueの本文を参照してください。
修正指示やレビューのログは、Issueのコメントに記載されています。
要求を整理してから、その要求を実現するための詳細設計と実装計画へ進めました。

<div class="repository">
<div><strong>要求定義に相当する作業フレーム</strong><p>私が「作業フレーム」と呼んでいる資料です。実現したいこと、対象範囲、制約、受入れ基準を整理し、要求定義に相当するものとして扱っています。</p></div>
<a class="repo-button" href="https://github.com/satokupo/welcart-tiered-discounts/issues/3" target="_blank" rel="noopener noreferrer">Issue #3を開く <span aria-hidden="true">↗</span></a>
</div>

<div class="repository">
<div><strong>詳細設計と実装計画</strong><p>作業フレームの要求をもとに、実際の環境や既存処理の制約を踏まえて、実装方針、作業手順、TDDと検証方法を具体化した資料です。</p></div>
<a class="repo-button" href="https://github.com/satokupo/welcart-tiered-discounts/issues/4" target="_blank" rel="noopener noreferrer">Issue #4を開く <span aria-hidden="true">↗</span></a>
</div>

<div class="repository">
<div><strong>UIのブレストに使った資料</strong><p>実際の管理設定・カート・購入確認・受注編集の画面と、画像生成で作ったUI案を比較した資料の最終版（v6）です。開発中の検討資料であり、適用後の画像は実装結果ではありません。</p></div>
<a class="repo-button" href="ui-comparison.html" target="_blank" rel="noopener noreferrer">UI比較資料を開く <span aria-hidden="true">↗</span></a>
</div>

---

## 開発後に整理した設計のまとめ

以下は、開発を終えた後に、AIとのブレストで検討した内容と実装結果を通して整理したものです。
採用したフックとその理由、見送った候補、翌日に見直した点をまとめています。

## 設計の要点

**割引額を計算する処理を共通化し、Welcartの計算・表示・保存の流れへ接続しました。** Welcart本体・テーマのファイルは変更していません。

設定保存にはWordPress Settings APIを使い、`manage_options`による権限確認、標準nonceの検証、入力値の検証・サニタイズを行います。不正入力では既存設定を保持します。

受注編集では、購入時に有効だった全ティアを保存し、店舗側の管理者による受注編集時にも購入時点のレートで再計算可能にしています。再計算結果は保存時にもサーバーで照合し、金額と履歴の書込みに失敗した場合はDB更新を巻き戻します。

## 使用したフック・フィルタと選択理由

提出版mainの一覧です。Welcart側の連携とWordPress側の補助処理を分けて記載します。

**Welcartのフックとフィルタ**

| フック／フィルタ | 用途と選択理由 |
|---|---|
| `usces_order_discount` | Welcartが注文割引を確定する位置で、登録価格から計算した単一の割引と税率別内訳を反映する |
| `usces_filter_cart_table_footer` | カート表の末尾を拡張し、テンプレートを書き換えず割引行を表示する |
| `usces_confirm_discount_label` | 購入確認と注文時の記録がある受注で、同じ割引名を表示する |
| `usces_filter_confirm_inform` | 購入確認フォームへ、表示した内容に対応する確認用tokenを追加する |
| `usces_purchase_check` | 注文登録前に最新の条件を再計算し、確認画面との差があれば再確認へ戻す |
| `usces_pre_reg_orderdata` | 新規注文の保存前にトランザクションを開始する |
| `usces_action_reg_orderdata` | Welcartの保存後に注文時の記録を作り、保存値を読み戻して確認する |
| `usces_post_reg_orderdata` | メール処理へ進む前に保存結果を再検証し、トランザクションを確定する |
| `usces_filter_ordereditform_carttable` | 既存受注フォームへ、注文時の設定と履歴、再計算操作を追加する |
| `usces_pre_update_orderdata` | 受注更新前に権限と確認内容を検証し、サーバーで計算した保存値を準備する |
| `usces_after_update_orderdata` | 受注更新後に金額と明細、税情報を照合し、履歴を保存して確定する |

登録箇所：[購入側の登録](https://github.com/satokupo/welcart-tiered-discounts/blob/9056929086d8b9e9db7379e48bcd75fd4ea457f1/plugin/includes/welcart.php#L13)、[受注側の登録](https://github.com/satokupo/welcart-tiered-discounts/blob/9056929086d8b9e9db7379e48bcd75fd4ea457f1/plugin/includes/orders.php#L12)。

**WordPress側の補助フック**

| フック／フィルタ | 用途と選択理由 |
|---|---|
| `plugins_loaded` | Welcartのロード後に連携を登録する |
| `admin_notices` | Welcartがない場合に管理者へ依存関係を通知する |
| `admin_menu` | WordPressの設定メニューへ画面を追加する |
| `admin_init` | Settings APIへの登録と、受注画面が出力を始める前の保存要求検証に使う |
| `admin_enqueue_scripts` | 設定画面に必要なJSだけを読み込む |
| `the_post`、`template_redirect` | 再確認が必要な場合に動的に登録し、Welcartの確認画面への処理を再接続する |
| `wp_ajax_wtd_preview_order` | 権限とnonceを確認し、DBを変更しない専用の再計算結果を返す |
| `wp_ajax_order_item_ajax` | 注文時の記録がある受注で、標準再計算による保存前のDB変更を防ぐ |
| `wp_ajax_order_item2cart_ajax` | 商品追加を未保存のデータとして扱い、確定保存までDB書込みを保留する |
| `query` | 注文書込み中のSQL失敗を検出するための補助監視 |
| `shutdown` | 処理が未完了で終了した場合に、未確定のDB更新と関連状態を復元する |

登録箇所：[起動時](https://github.com/satokupo/welcart-tiered-discounts/blob/9056929086d8b9e9db7379e48bcd75fd4ea457f1/plugin/welcart-tiered-discounts.php#L29)、[設定画面](https://github.com/satokupo/welcart-tiered-discounts/blob/9056929086d8b9e9db7379e48bcd75fd4ea457f1/plugin/includes/settings.php#L418)、[受注処理](https://github.com/satokupo/welcart-tiered-discounts/blob/9056929086d8b9e9db7379e48bcd75fd4ea457f1/plugin/includes/orders.php#L12)、[再確認](https://github.com/satokupo/welcart-tiered-discounts/blob/9056929086d8b9e9db7379e48bcd75fd4ea457f1/plugin/includes/welcart.php#L473)。

プラグインから発火する既存アクションとして、`usces_admin_delete_orderrow`も使用します。
削除処理でWelcartの拡張処理を通すための呼出しで、プラグインがcallbackを登録する項目とは区別しています。
[該当処理](https://github.com/satokupo/welcart-tiered-discounts/blob/9056929086d8b9e9db7379e48bcd75fd4ea457f1/plugin/includes/orders.php#L149)を参照できます。

## AIの自己レビューで検討した候補と比較

以下は、AIが技術的な採否を整理したものです。私が説明を受けて判断した内容は、次の節に分けて記載します。

| 検討した方法 | 採否と理由 |
|---|---|
| 表示金額だけを変更する | 不採用。税・ポイント・受注データとの整合が取れない |
| 割引を再計算のたびに加算する | 不採用。再計算で割引が累積するため、今回の計算結果を反映する |
| 標準AJAXをそのまま未保存プレビューに使う | 不採用。税条件変更時のDB更新とJSON出力による処理終了が、保存前の確認用途に合わなかった |

## 私が検討して判断したこと

AIの説明や提案を受けて、私が変更や対象外の判断をした例です。
当時の回答は、[Issue #3の追加回答の記録](https://github.com/satokupo/welcart-tiered-discounts/issues/3#issuecomment-5565656949)に詳細がまとめられています。

### 受注編集時の割引を自動計算し、購入時の条件を保つ

AIは、受注編集時に店舗側の管理者が手動で割引額を調整する案を提案しました。しかし、商品や数量を変更するたびに管理者が割引額を計算する運用は使い勝手がよくないと考え、変更後の内容に応じて自動計算する仕組みを求めました。

検討の中では、購入後に店舗のティア設定が変更されていた場合、どの条件で再計算するかという問題も挙がりました。そこで、購入時に有効だった全ティアを注文データに保存し、受注編集でも当時のしきい値と割引率・割引額で再計算するルールにしました。計算結果は管理者が確認して保存します。

### Welcartの価格の扱いを確認し、登録価格をそのまま使う

AIからは、未確定の仕様を詰める中で、税込価格から税抜価格を逆算するなど、複雑な計算ロジックが提案されました。この時点では私自身がWelcartの価格の扱いを十分に把握していなかったため、まず仕組みを調べて報告するよう依頼しました。

その結果、商品に登録する価格は1つで、その金額を税込・税抜のどちらとして扱うかは店舗の設定で決まるとわかりました。そこで、税抜価格への換算を追加せず、もともと登録されている金額をそのまま割引の判定と計算に使う、シンプルな方針を指示しました。

### 他の割引との競合は対象外とし、ポイント対応の拡張余地を残す

AIとの検討では、他の割引と競合した場合の扱いも論点になりました。ただし、組み合わせごとの条件まで考慮すると複雑になるため、競合の検出や制御はMVPに含めないことにしました。MVPでは、標準ポイントによる減額も含め、割引やポイントを適用する前の対象商品小計で判定と割引計算を行います。

一方で、将来は標準ポイントで減額した後の金額を基準にできるよう、拡張性を持たせることを求めました。具体的には、計算の基準額を作る処理と、ティアを選んで割引額を計算する処理を分ける方針です。ポイント適用後の判定そのものは今回実装せず、後から基準額の作り方を変更できる構造にしています。

## 課題の必須要件を超えて含めた範囲と理由

課題で求められていたのは、カート・購入確認・確定後の受注データで割引結果を整合させるところまででした。ただ、実際の店舗業務を想像すると、注文後に在庫状況などによって数量を変更することは十分にありそうだと考えました。そのため、既存の受注編集でも割引を適切に扱えるようにした方がよいと判断し、今回の作業に含めました。

これに伴い、変更後の明細による再計算、購入時のティアの保存、保存前の計算結果の確認など、一連の関連処理も必要になりました。実際の業務を想定すれば、受注編集への対応は今も必要だったと考えています。ただし、変更履歴の保持まで含める必要があったかは、振り返ると見直す余地があったと考えています。

割引方式は、固定額に加えて割合も選べるようにしました。課題では固定額だけで割引するよう指定されておらず、実際の運営では割合で割引したい店舗経営者もいるだろうと考えたためです。また、当時は固定額か割合かという計算方法の違いであれば、ロジックの変更も大きくないだろうと見込み、今回の実装に含めました。

実務を想定して範囲を広げた結果、設計・実装・検証の対象も増え、全体では想定の5〜7時間を超えました。個々の対応には理由がありましたが、課題としてまず満たす範囲と、その後に対応する範囲を、時間の制約に合わせて切り分けきれなかった点は反省しています。

## 作業フレーム内で案が出たが、今回の提出物に含めなかった機能と理由

作業フレームを作る段階では、将来追加したい機能も整理しました。限られた時間でまず基本の段階式割引を完成させるため、次の機能はMVPから外し、追加候補として残しました。

| 機能 | 将来実現したいこと | MVPに含めなかった理由・今回の範囲 |
|---|---|---|
| 日本語・英語への対応 | 管理画面とプラグインが追加するフロント文言を、言語設定に応じて表示する | 基本機能の完成を優先し、翻訳文言の整備と各言語での表示確認は後続に回す |
| 商品カテゴリによる対象選択 | 指定したカテゴリの商品だけでしきい値を判定し、その商品代金に割引を適用する | MVPは全商品を対象とする。カテゴリの指定方法や複数カテゴリに属する商品の扱いなどは、着手時に決める |
| ウィザード／オンボーディング | 導入時の設定を案内する | MVPでは通常の設定画面を用意する範囲に絞る。案内する手順や画面構成は未決のため、後続で具体化する |
| 会員ランクによる振り分け | 会員ランクに応じて割引の対象を分ける | 対象・除外の指定やゲスト購入の扱いが未決のため、MVPに組み込まず候補として残す |
| 標準ポイント適用後の金額による判定 | ポイントで減額した後の金額を、しきい値判定と割引計算の基準にする | MVPは減額前の商品小計を基準とする。前節のとおり、将来変更できる構造を保ち、追加方式の実装は見送る |

検討内容と未決事項は、[MVP後の追加機能の記録](https://github.com/satokupo/welcart-tiered-discounts/blob/9056929086d8b9e9db7379e48bcd75fd4ea457f1/docs/work/todo/POST_MVP.md)にまとめています。

## 翌日に追加検証して見直した設計（dev）

WordPress 7.1環境での確認がエラーで終わっていたことと、既存フックを適切に利用できているかの再チェックが抜けていたことから、翌日に追加検証を行いました。

フックの利用を再点検すると、見直し候補が3つ見つかりました。
専用のテスト環境を構築して、要件を保ったまま置き換えられるかを試し、2つは修正が必要と判断して変更しました。
この2箇所がdev版の設計上の修正部分です。

| 見直した箇所 | main | devでの判断 |
|---|---|---|
| 税額計算 | 独自計算とWelcartの丸め処理 | `$usces->getTax()`と`usces_internal_tax()`へ委譲 |
| 受注画面の再計算ボタン | JSでクリックを捕捉 | `order_edit_form_recalculation`と`order_edit_form_recalculation_reduced`から専用プレビューへ接続 |
| 受注全体の集計 | 注文時の条件と未保存明細から組み立て | `usces_order_recalculation`等への全面置換は見送り。未保存の追加明細と税率別割引を保つ補助処理が増えるため |

受注全体の集計は、未保存の追加明細などの扱いを保つため、現行の方法を維持しました。[採否の詳細](https://github.com/satokupo/welcart-tiered-discounts/blob/334907ec022fd7f3babac1e450b43bb77dabbf1e/docs/SSoT/decisions/2026/09/2026-09-08_受注編集は税計算と再計算入口だけnativeへ委ねる.md)


環境の追加検証では、WordPress 7.1に加えて下限環境の5.6.19でも、前日に未確認だったHTTP購入やメール本文まで確認しました。[追加検証の記録](https://github.com/satokupo/welcart-tiered-discounts/blob/334907ec022fd7f3babac1e450b43bb77dabbf1e/docs/ENVIRONMENT_VERIFICATION.md#2026-09-08-dev版の未実施試験を補完)
