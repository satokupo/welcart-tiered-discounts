# Welcart Tiered Discounts

Welcart 向けの独立 WordPress プラグインを開発するリポジトリです。開発対象のソースは `plugin/` に置き、WordPress 本体、Welcart 本体、テーマ、データベースは Docker のランタイム側で管理します。

この README は、課題実装に入る前のローカル開発環境を起動・検証するための入口です。環境の固定値と完了条件の正本は [`docs/SSoT/TECH_STACK_AND_OPERATIONS.md`](docs/SSoT/TECH_STACK_AND_OPERATIONS.md)、21項目の実測記録は [`docs/ENVIRONMENT_VERIFICATION.md`](docs/ENVIRONMENT_VERIFICATION.md) にあります。

## 前提

ホスト側に次のものを用意してください。

- Docker Desktop または Docker Engine
- Docker Compose v2（`docker compose` サブコマンド）
- Git
- WordPress 管理画面・ショップ画面を確認するブラウザ

PHP、Composer、PHP_CodeSniffer、WPCS、PHPUnit、WP-CLI はホストへグローバル導入せず、Docker の品質ツールまたは各 WordPress コンテナから実行します。Apple Silicon では品質ツールと `recommended` / `latest` は native arm64、`minimum` の MySQL 5.5.62 だけは `linux/amd64` emulation を使用します。

## 環境プロファイル

| プロファイル | WordPress | PHP | MySQL | Welcart | テーマ | 位置づけ |
|---|---:|---:|---:|---:|---|---|
| `recommended` | 7.0.4 | 8.3 | 8.4.11 | 2.12.1 | WordPress 同梱テーマ | 主開発・主受入れ。起動から基準注文まで必須 |
| `latest` | 7.1 | 8.5 | 26.7.0 Innovation | 2.12.1 | WordPress 同梱テーマ | 前方互換性の観測。実行と記録は必須、成功は必須でない |
| `minimum` | 5.6.19 | 7.4 | 5.5.62 | 2.12.1 | WordPress 同梱テーマ | 公称下限の観測。DB は `linux/amd64`。実行と記録は必須、成功は必須でない |

`latest` と `minimum` の環境起因の失敗は、試した固定版・architecture・ログ・再現可能な停止理由を記録します。補助環境の結果を `recommended` の代替にはしません。

## 初回のローカル設定

ローカル専用の認証情報は `docker/local.env` に置きます。初回だけ次を実行してください。

```sh
./scripts/dev.sh recommended init
```

このコマンドは、コミット可能な [`docker/local.env.example`](docker/local.env.example) から `docker/local.env` を生成します。`docker/local.env` は `.gitignore` 対象です。実用の認証情報、本番・個人サービスの値、外部 SMTP の認証情報は設定しないでください。サンプルや文書へ秘密値を転記しないでください。

## 起動・停止・リセット

各プロファイルは別の Compose project、network、volume、loopback port を使います。通常の停止では volume を削除しません。

```sh
# 構成を展開して確認
./scripts/dev.sh recommended config

# 推奨環境を起動
./scripts/dev.sh recommended up

# 初回の WordPress / Welcart セットアップ（起動後に一度）
./scripts/dev.sh recommended bootstrap

# 補助環境を試行する場合
./scripts/dev.sh latest up
./scripts/dev.sh latest bootstrap
./scripts/dev.sh minimum up
./scripts/dev.sh minimum bootstrap

# 通常停止（volume は保持）
./scripts/dev.sh recommended down

# 明示的な初期化（対象プロファイルの volume を削除）
./scripts/dev.sh recommended reset
```

`reset` は対象環境のデータを削除する操作です。必要な検証記録を保存したことを確認してから、対象プロファイルを明示して実行してください。WordPress 本体・Welcart 本体・テーマは Docker volume 内へ展開し、リポジトリへ取り込みません。

## ソースマウント

ホストの `plugin/` が各環境の次の同一 slug へ bind mount されます。

```text
/var/www/html/wp-content/plugins/welcart-tiered-discounts
```

ホストで `plugin/` を編集すると、コンテナ内の同じファイルへ即時反映されます。WordPress 本体、Welcart 本体、テーマのソースをこのリポジトリへコピーしたり、直接改変したりしません。

## 初期化後の確認

`recommended` の初期化は、標準の WordPress 管理画面とショップ画面から確認します。管理者認証は `docker/local.env` のローカル値を使用してください。

1. `http://127.0.0.1:8080` と `/wp-admin/` をブラウザで開く。
2. WordPress が日本語、Asia/Tokyo、JPY のローカル設定になっていることを確認する。
3. Welcart が有効で、カート・会員などの初期ページと商品カテゴリが生成されていることを確認する。
4. 管理画面の通常操作で、ダミー店舗、配送方法、オフライン決済、既知価格の商品を設定する。
5. ショップ画面から商品をカートへ追加し、カート、購入確認、注文確定、受注データの確認まで進む。
6. 注文番号と割引を含めない基準金額を [`docs/ENVIRONMENT_VERIFICATION.md`](docs/ENVIRONMENT_VERIFICATION.md) に記録する。

これは環境の基準経路を確認する操作であり、割引仕様や割引機能の受入れを行うものではありません。Welcart 内部のデータベースへ推測で直接書き込まず、管理画面と標準の注文経路を利用します。

## バージョン・ログ確認

```sh
./scripts/dev.sh recommended versions
./scripts/dev.sh latest versions
./scripts/dev.sh minimum versions

./scripts/dev.sh recommended logs
```

`versions` の結果には、WordPress、PHP、MySQL、Welcart、WP-CLI、テーマの実測版を残します。固定 image の初回取得時に観測した image digest（multi-arch index が存在する場合は index digest）も検証記録へ転記します。

WordPress の debug log、PHP error log、container log はローカルで読める状態にし、PHP の `sendmail_path` はローカル mail sink または決定論的な抑止経路へ向けます。外部 SMTP や外部メール配送は構成しません。

## 品質検査

品質検査は PHP 8.3.33 と Composer 2.10.2 を備えた専用コンテナから project-local 依存を使って実行します。

```sh
# Compose YAML の構文・展開検査
./scripts/dev.sh recommended config
./scripts/dev.sh latest config
./scripts/dev.sh minimum config

# Composer、PHP lint、PHPCS / WPCS、PHPUnit runner の入口
./scripts/dev.sh recommended quality

# WordPress 上で Plugin Check の入口を確認（bootstrap 後）
./scripts/dev.sh recommended wp plugin check --help

# WP-CLI で個別の状態を確認
./scripts/dev.sh recommended wp core version
./scripts/dev.sh recommended wp plugin list
./scripts/dev.sh recommended wp theme list
```

`quality` は `composer validate --strict`、lock からの依存導入、platform requirements、PHP lint、WPCS、PHPUnit runner の設定読込みをまとめて確認します。Plugin Check は WordPress 上の WP-CLI コマンドとして別に入口を確認します。環境準備では機能テストや割引処理を追加しません。

## 検証記録

21項目の受入記録は [`docs/ENVIRONMENT_VERIFICATION.md`](docs/ENVIRONMENT_VERIFICATION.md) を使います。初期状態では全項目が `NOT_YET_EXECUTED` です。各項目に次を実測して記入してください。

- 実行したコマンドまたはブラウザ操作
- 終了コード
- WordPress、PHP、MySQL、Welcart、テーマ、WP-CLI、Browser の実測版
- image digest と architecture
- `PASS` / `FAIL` / `BLOCKED` と理由
- 実測時刻、関連ログ、スクリーンショットまたは画面記録への参照

`recommended` の必須項目がすべて成立し、`latest` と `minimum` の試行結果、品質入口、Git 非汚染、文書整合を記録できた時点で、環境準備完了を判定します。課題実装の作業時間はその後に計測します。
