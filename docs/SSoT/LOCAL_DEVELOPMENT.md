# ローカル開発の運用方針と操作手順

## 目的と正本の境界

プライマリ worktree は dev を使用し、dev を開発の統合・検証先、main を提出可能な安定版とする。作業用 worktree で並列に開発する。コードだけでなく開発途中の DB 変更も他の作業から分離し、タスクごとの WordPress 再インストールを避ける。

本書は、ブランチ・worktree・開発環境の対応、データ更新方針、ローカル環境の初回設定・起動・停止・確認手順の正本。ソフトウェアの版、検証プロファイル、品質検査の基準、安全境界は [技術構成と運用](TECH_STACK_AND_OPERATIONS.md)、製品データの意味は [データモデル](DATA_MODEL.md) が所有する。操作手順は本書へ集約し、root README は本書への案内だけを持つ。実装状況と残作業は `docs/work/` で管理する。

## 操作手順への入口

- 初めて使う: [前提](#前提) → [初回のローカル設定](#初回のローカル設定) → [初回のセットアップ](#初回のセットアップ)
- 普段使う: [日常の起動とブラウザ表示](#日常の起動とブラウザ表示)
- 開けない: [接続できない場合](#接続できない場合)
- 終了する: [停止・リセット・補助環境](#停止リセット補助環境)

本書のコマンドは、特記がない限り対象リポジトリのルートで実行する。

## ブランチ・worktree・環境の関係

| 対象 | 役割 | 寿命 |
|---|---|---|
| dev とプライマリ worktree | 作業ブランチの統合・検証先 | 継続して使用 |
| main | dev で受入確認を終えた変更を PR で取り込む安定版 | 継続して使用 |
| 作業ブランチと worktree | dev から分岐した変更を開発・検証するコードの作業場所 | 作業単位 |
| 開発環境 A〜E | worktree のコードを接続して動かす、独立した WordPress と DB | 作業終了後も保持し再利用 |

1 つの環境を、同時に複数の作業へ割り当てない。main の検証にも空いている環境を使用し、使用中の環境へ別の worktree を接続しない。環境の識別子とブランチ名は別物であり、A〜E は固定ブランチではない。

作業ブランチへ dev の更新を取り込む場合は、その worktree で競合を解消して検証する。dev への統合対象はコード・テスト・設定定義・DB 更新処理・文書であり、開発用 DB の実体は含めない。Git の commit・merge・push の承認境界は既存の `git-commit-rules`、worktree の作成・配置・着地・削除は `worktree-ops` に従う。本書は自動 commit・merge・push・削除の許可を追加しない。

開発の流れは `dev → 作業ブランチ／worktree → dev → PR → main` とする。作業ブランチを検証して dev へ統合し、dev 上で統合後の動作・受入条件を確認する。完成した範囲を dev から main への PR として提示し、その PR を通じて反映する。通常の開発変更を main へ直接 commit・merge・push しない。

main に採用された変更や PR の統合結果が dev にない場合は、dev へ取り込み直し、次の作業の基点を揃える。既存の作業用 worktree は自動でブランチを切り替えず、それぞれの作業状況に応じて dev を取り込む。

## ポートと環境の分離

A〜E は、いずれも `recommended` プロファイルの開発環境とする。

| 環境 | WordPress のホスト側ポート | 接続先 |
|---|---:|---|
| A | 8080 | `http://127.0.0.1:8080` |
| B | 8180 | `http://127.0.0.1:8180` |
| C | 8280 | `http://127.0.0.1:8280` |
| D | 8380 | `http://127.0.0.1:8380` |
| E | 8480 | `http://127.0.0.1:8480` |

ショップと管理画面は同じポートを使う。DB は各環境の Docker ネットワーク内で接続し、ホスト側へ公開しない。WP-CLI・品質検査・既存のメール抑止処理に追加の公開ポートは設けない。

Compose のプロジェクト、ネットワーク、WordPress 保存領域、DB コンテナと DB 保存領域を環境ごとに分離する。Docker イメージは共用できるが、書込み先のボリュームは共用しない。WordPress のサイト URL は割当ポートと一致させる。

`latest` と `minimum` は統合後の互換性確認用としてそれぞれ 1 環境を持ち、A〜E ごとには複製しない。既存の公開ポート 8081／8082 を使用し、主検証・補助検証の合否基準は変更しない。

## 環境の再利用

各環境は最初の利用時に初期化し、WordPress・Welcart・ローカル設定・検証商品を保存する。必要な環境だけ起動し、通常の作業終了では停止して保存領域を保持する。5 環境の常時起動やタスクごとの再インストールは行わない。

空いている環境を再利用するときは、対象 worktree の `plugin/` を接続する。マウント元の切替にコンテナの再作成が必要でも、永続ボリュームは保持できる。作業中の環境識別子、ブランチまたはコミット、DB 更新状態は作業記録に残し、使用中の環境を識別可能にする。

接続に必要な worktree の実際の絶対パスは、Git 管理外のローカル専用記録で扱う。SSoT、`docs/work/`、Issue、提出資料では環境識別子、ブランチ・コミット、リポジトリ相対表現を使用し、ローカル絶対パスを転記しない。

再利用前に、残っている DB と接続するコードが整合することを確認する。前の作業の未マージ変更が DB に残っている場合、そのまま別ブランチへ使い回さない。互換性を確認するか、その環境を保持して別の空き環境を使用する。復元・初期化が必要な場合は対象データを確認し、既存の削除・破壊的操作の承認境界に従う。自動的に DB を巻き戻したり消去したりしない。

## DB変更をdev・mainと各環境へ反映する方法

DB 構造や既存データの形式を変更する場合、その更新処理をプラグインのコードとして管理する。管理画面や SQL での手作業だけを、他環境への反映方法にしない。WordPress の option に保存する配列構造の変更も、この対象となる。

1. 作業ブランチでコードと DB 更新処理を組にし、その作業環境だけで検証する。
2. 検証した変更を dev へ統合する。DB の実体をマージする操作はない。
3. 各 worktree が新しい dev を取り込んだ後、そのコードに対応する DB 更新処理を割当環境へ適用する。
4. 停止中の環境は、次回利用時にコードと DB 更新状態を照合して必要な更新を行う。
5. dev で受入確認したコードと DB 更新処理を PR で main へ反映する。main を使用する環境も、対応コードを取り込んだ時に必要な DB 更新を適用する。

採用された変更は、この流れで各環境へ揃える。開発途中の変更を他環境へ一斉適用したり、古いコードの環境へ DB 更新だけを先行配布したりしない。全ブランチを勝手に書き換える同期処理も設けない。

更新処理は適用済み状態を識別でき、再実行で二重変更やデータ損失を起こさないことを確認する。成功を確認してから適用済みとして記録する。並行ブランチが同じデータ構造や更新順序を変更した場合、dev への統合時に競合を解消し、統合前の状態から更新できることを検証する。

Git で古いコードへ戻しても DB は自動では元に戻らない。コードと DB が不整合な状態では検証結果を採用せず、互換性のあるコードを使用するか、承認された復元方法を選ぶ。

## 開発データの扱い

各環境のテスト注文や作業途中の設定を、他の DB へマージする必要はない。共通して必要な初期設定・検証商品は、再現可能な投入手順またはデータとして管理する。環境を揃えるために毎回 DB 全体を上書きする方式は採らない。

製品の動作に必要なデータ形式変更と、検証用データの投入を区別する。前者はコードと共に配布する更新処理、後者はローカル開発用の準備として扱う。

## デモ商品の投入

商品構成・価格の理由・検証例は [デモンストレーション用の商品](DEMO_PRODUCTS.md) を参照する。投入用の [商品 CSV](../../docker/demo-products.csv) はその定義に対応するローカル開発用データである。UTF-8・全 37 列・カテゴリ slug `item` を使用する。Welcart のシステム設定で CSV 文字コードを UTF-8、カテゴリ形式を slug に合わせる。

1. 割当環境の管理画面で、Welcart が有効、通貨が JPY、販売価格の扱いが税抜であることを確認する。
2. 商品マスターで同じ商品コードを検索する。既にある商品は価格・SKU・公開状態・在庫を照合し、投入用コピーから該当行を除く。元の CSV は変更しない。
3. カテゴリ slug `item` と配送方法 ID `0` が対象環境に存在することを確認する。CSV は各商品を在庫 99・在庫状態「在庫有り」・単位「個」・標準税率で投入する。環境依存の値は投入用コピーで合わせる。
4. 商品マスターの「操作フィールド表示」→「商品一括登録」で、全項目（All columns）の CSV を選択し、「データチェック」を実行する。
5. エラーがないことを確認して「登録開始」を実行する。新規登録用の Post ID は空欄とし、既存の商品 ID を他環境へ持ち込まない。
6. 商品マスターとショップ画面で 4 種類のコード・価格・1 SKU・公開状態を確認する。カートへ入れ、数量変更で狙った商品小計を作れることを確認する。

商品を登録しただけで割引検証済みとは扱わない。割引機能のケースを実行した場合は、設定・操作・期待値・実測値を作業記録に残す。再投入のために DB 全体を上書きしない。

### デモ商品画像の投入

画像原本と商品コードの対応は [商品画像](DEMO_PRODUCTS.md#商品画像) を参照する。商品CSVの登録とは別に、各環境のWordPressメディアへ画像を登録する。

1. メディアライブラリで同じ商品コードの画像があれば、内容を確認して再利用する。なければ対応するPNGをアップロードし、メディアのタイトルを商品コード、代替テキストを画像内の番号・色・雑貨の説明にする。
2. Welcartの商品編集画面で、対応する画像を「メディアを選択」から選び、適用（Apply）する。新商品画像登録が有効な環境では、メディアのタイトルや添付先だけでは商品画像に紐付かない。
3. 各商品に対応する1枚を主画像として設定する。商品マスター・商品詳細・カートで、画像と商品コード・価格の対応を確認する。

WordPressメディアへの投入は `wp media import --title=<商品コード> --post_id=<その環境の商品ID> --alt=<説明>` でも行える。Welcartの商品画像への紐付けは別途必要である。環境ごとの商品ID・添付IDを共通データへ固定せず、画像ファイルの配置だけで反映済みと扱わない。

## 前提

ホスト側に次のものを用意してください。

- Docker Desktop または Docker Engine
- Docker Compose v2（`docker compose` サブコマンド）
- Git
- WordPress 管理画面・ショップ画面を確認するブラウザ

PHP、Composer、PHP_CodeSniffer、WPCS、PHPUnit、WP-CLI はホストへグローバル導入せず、Docker の品質ツールまたは各 WordPress コンテナから実行します。Apple Silicon では品質ツールと `recommended` / `latest` は native arm64、`minimum` の MySQL 5.5.62 だけは `linux/amd64` emulation を使用します。

環境プロファイルの固定版と検証基準は [技術構成と運用](TECH_STACK_AND_OPERATIONS.md#検証環境プロファイル) を参照する。

## 初回のローカル設定

ローカル専用の認証情報は `docker/local.env` に置きます。初回だけ次を実行してください。

```sh
./scripts/dev.sh recommended init
```

このコマンドは、コミット可能な [`docker/local.env.example`](../../docker/local.env.example) から `docker/local.env` を生成します。`docker/local.env` は `.gitignore` 対象です。実用の認証情報、本番・個人サービスの値、外部 SMTP の認証情報は設定しないでください。サンプルや文書へ秘密値を転記しないでください。

## 初回のセットアップ

初回のローカル設定を作成した後、対象リポジトリのルートで次を実行する。

```sh
./scripts/dev.sh recommended config
./scripts/dev.sh recommended up db wordpress
./scripts/dev.sh recommended bootstrap
```

`bootstrap` は WordPress・Welcart とローカル設定をセットアップする。日常の起動では再実行しない。WordPress 本体・Welcart 本体・テーマは Docker volume 内へ展開し、リポジトリへ取り込まない。

## 日常の起動とブラウザ表示

1. Docker Desktop を起動し、Docker Engine が利用できる状態にする。
2. 起動するコードを持つ対象リポジトリのルートをターミナルで開き、以降のコマンドをそこで実行する。
3. A 環境（8080）の WordPress と DB を起動する。

   ```sh
   ./scripts/dev.sh recommended up db wordpress
   ```

4. DB の起動確認を待って WordPress が起動したら、[ショップ](http://127.0.0.1:8080) または [管理画面](http://127.0.0.1:8080/wp-admin/) を外部ブラウザで開く。接続エラーのタブを開いている場合は再読み込みする。

この起動は保存済みの WordPress と DB を使用し、初期化・データ削除を行わない。ブラウザ表示だけなら WP-CLI・品質ツールの常駐コンテナは不要である。

`recommended` は `compose.yaml` の固定ポート 8080 を使用する。`dev.sh` の環境引数は `recommended`・`latest`・`minimum` であり、B〜E の選択には使わない。別 worktree から同じコマンドを実行しても独立環境にはならないため、[環境の再利用](#環境の再利用)に従って接続するコードと保存領域の割当を確認する。

### 接続できない場合

`ERR_CONNECTION_REFUSED` は、その URL でサーバーに接続できていない状態である。A 環境の URL と Docker Engine の起動を確認し、次で対象コンテナの状態を調べる。

```sh
docker ps -a --filter label=com.docker.compose.project=welcart-tiered-discounts-recommended --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
```

WordPress または DB が停止していたら、上の起動手順を実行する。起動に失敗する場合は、次のログで原因を確認する。

```sh
./scripts/dev.sh recommended logs --tail=100 db wordpress
```

`docker/local.env is missing` と表示された場合は、[初回のローカル設定](#初回のローカル設定)を行う。接続確認には次を使用できる。

```sh
curl --max-time 10 --silent --show-error --output /dev/null --write-out 'HTTP %{http_code}\n' http://127.0.0.1:8080
```

接続拒否を直す目的で `reset` や再インストールを行わない。

## 停止・リセット・補助環境

各プロファイルは別の Compose project、network、volume、loopback port を使う。通常の停止では volume を削除しない。

```sh
# 通常停止（volume は保持）
./scripts/dev.sh recommended down
```

`reset` は対象環境のデータを削除する操作である。必要な検証記録を保存し、削除対象を確認して明示的に初期化するときだけ実行する。

```sh
./scripts/dev.sh recommended reset
```

補助環境を試行するときは対象を明示する。`bootstrap` は各環境の初回セットアップ時に実行する。

```sh
./scripts/dev.sh latest up
./scripts/dev.sh latest bootstrap
./scripts/dev.sh minimum up
./scripts/dev.sh minimum bootstrap
```

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
6. 注文番号と割引を含めない基準金額を [環境の実測記録](../ENVIRONMENT_VERIFICATION.md) に記録する。

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

21項目の受入記録は [環境の実測記録](../ENVIRONMENT_VERIFICATION.md) を使います。各項目に次を実測して記入してください。

- 実行したコマンドまたはブラウザ操作
- 終了コード
- WordPress、PHP、MySQL、Welcart、テーマ、WP-CLI、Browser の実測版
- image digest と architecture
- `PASS` / `FAIL` / `BLOCKED` と理由
- 実測時刻、関連ログ、スクリーンショットまたは画面記録への参照

`recommended` の必須項目がすべて成立し、`latest` と `minimum` の試行結果、品質入口、Git 非汚染、文書整合を記録できた時点で、環境準備完了を判定します。課題実装の作業時間はその後に計測します。
