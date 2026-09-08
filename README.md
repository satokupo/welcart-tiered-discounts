# Welcart Tiered Discounts

このブランチ（main）は、主たる提出用です。制限時間を超えてから調整を加えた参考版は [devブランチ](https://github.com/satokupo/welcart-tiered-discounts/tree/dev) にあります。

Welcart に、登録販売価格と数量の小計に応じた段階式割引を追加する独立 WordPress プラグインです。たとえば「10,000円以上で500円引き」「30,000円以上で2,000円引き」を設定できます。条件を満たす有効な段のうち、しきい値が最も高い1段を適用します。

## 導入と確認

1. WordPress と Welcart を用意し、Welcart を有効にします。
2. このリポジトリの `plugin/` の中身を `wp-content/plugins/welcart-tiered-discounts/` に配置し、「Welcart Tiered Discounts」を有効にします。
3. WordPress の「設定」→「ステップ割引」で、しきい値・固定額／割合・割引値・有効状態を保存します。初期状態は割引なしです。
4. 登録販売価格10,000円の商品を1個カートに入れます。税込10%・送料／手数料／ポイント0なら、割引500円、請求9,500円になります。定価・参考価格は判定に使いません。
5. カート、購入確認画面、購入後のWelcart受注データを確認します。確認後に設定が変わった場合は、更新された条件を確認してから確定します。
6. 受注を編集するときは、数量などを変更して「ステップ割引を再計算」を押し、表示を確認して保存します。注文時の全設定で計算し、元注文と保存済み変更履歴を保持します。

固定額は円単位、割合は小数第2位まで（例: 10.25%）を設定できます。しきい値と固定額の最大値は99,999,999円です。割合の割引額は円未満を切り捨て、商品代金を上限にします。送料・手数料・別途加算する消費税・利用ポイントはしきい値の基準に含めません。

設定が破損している場合は購入確定を停止します。設定画面を確認し、WordPress/PHPのエラーログにある `WTD` の理由コードを調査してください。無効化しても、既存受注と注文時の記録は削除しません。

## 検証環境

| 用途 | WordPress | Welcart | PHP | 結果 |
|---|---|---|---|---|
| 主受入れ | 7.0.4 | 2.12.1 | 8.3.33 | 実HTTP購入・税・ポイント・受注保存を検証 |
| 下限補助 | 5.6.19 | 2.12.1 | 7.4.33 | 有効化・native カート→確認ゲート→注文保存を確認。HTTP・メール本文は未確認 |
| 最新補助 | 7.1 | 取得不可 | 8.5.9 | 既存DBの認証不一致で有効化・購入経路を検証できず |

他の割引・標準キャンペーンとの併用、外部決済の実取引、メールの実配送は保証対象外です。検証時はローカル振込方式と送信抑止を使います。翻訳可能な文言を用意していますが、日本語・英語の翻訳提供、カテゴリ指定、会員ランク指定は後続対象です。

## 開発・テスト

WordPress、Welcart、テーマ、DBの実体はDocker volumeで管理し、`plugin/` をbind mountします。初回の環境準備・A〜Eの割当は[ローカル開発手順](docs/SSoT/LOCAL_DEVELOPMENT.md)に従ってください。

```sh
./scripts/dev.sh init
./scripts/dev.sh recommended config
./scripts/dev.sh recommended bootstrap
./scripts/dev.sh recommended wp plugin activate welcart-tiered-discounts
./scripts/dev.sh recommended quality
./scripts/dev.sh recommended integration
```

`quality` はComposer検査・PHP lint・PHPCS/WPCS・実単体テストを実行します。`integration` は割り当てたローカルWordPressにダミー商品・会員・受注を作成するため、本番環境では実行しないでください。試験用MUプラグインは、この入口でだけ有効にします。実HTTP試験と管理画面の操作を並行する場合は、同じ設定や受注を同時に変更しないでください。

Plugin Check は `./scripts/dev.sh recommended wp plugin check welcart-tiered-discounts` で実行します。終了コードに加えて出力中の指摘も確認してください。現在の `Tested up to: 7.0` は実測範囲です。最新WordPressを要求するディレクトリ掲載用チェックには未解消の指摘があり、7.1対応を表明していません。

## 提出資料

- [設計メモ・AI活用レポート・検証記録](docs/visdoc/briefing/0907_Welcart割引実装/実装・検証レポート.md)
- [HTML版](docs/visdoc/briefing/0907_Welcart割引実装/実装・検証レポート.html)
- [使用したフック・既存APIと採用理由](docs/SSoT/INTEGRATIONS.md)
- [Issue #4 詳細計画と結果報告](https://github.com/satokupo/welcart-tiered-discounts/issues/4)

## 開発資料

- [ローカル開発の運用方針と操作手順](docs/SSoT/LOCAL_DEVELOPMENT.md): 初回設定、起動・停止、ブラウザ表示、接続できない場合の確認、品質検査
- [仕様・設計の正本](docs/SSoT/README.md)
- [技術構成と運用](docs/SSoT/TECH_STACK_AND_OPERATIONS.md): 環境の固定版・検証基準
- [環境の実測記録](docs/ENVIRONMENT_VERIFICATION.md)
