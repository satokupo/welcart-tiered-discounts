# 開発環境の受入検証記録

## 目的

FIX 済み作業フレームの受入条件 9-2-1 から 9-2-21 までについて、課題実装を始める前のローカル開発環境を実測した記録です。環境の固定値と完了条件は [`docs/SSoT/TECH_STACK_AND_OPERATIONS.md`](SSoT/TECH_STACK_AND_OPERATIONS.md)、再実行手順は [`README.md`](../README.md) を正本とします。

## 共通メタデータ

| 項目 | 記録 |
|---|---|
| 検証状態 | `PASS` |
| 作業ブランチ | `dev` |
| 検証対象 Git SHA | `96682a005b025903186903bad92f1e00221d9324` を基点とする作業ツリー。成果物 commit は commit 後の Issue 完了記録で補完する |
| ホスト OS / CPU architecture | macOS 26.6.2（build 25G83）/ arm64 |
| Docker Engine / Docker Compose | 29.4.2 / 5.1.3 |
| Git | 2.50.1（Apple Git-155） |
| Browser 製品 / 版 | Codex in-app Browser / Chrome 151.0.0.0（Apache access log の User-Agent で実測） |
| 検証開始時刻 | 2026-08-28 19:23 JST |
| 準備完了時刻 | 2026-08-28 20:11 JST |
| 課題実装の計測開始時刻 | `NOT_STARTED`（環境準備完了後にユーザーが開始する） |

## 固定 image の取得時 digest

| 用途 | image / build | digest |
|---|---|---|
| recommended WordPress | `wordpress:7.0.4-php8.3-apache` | `sha256:b427cec767f5de2aa649390cb8805aa1fe320e1e0d57fc1f467754edb6cc0a49` |
| latest WordPress | `wordpress:7.1-php8.5-apache` | `sha256:739299796a619be2b608f320808d88a44b256c94b5081fdf20f90b9848813beb` |
| recommended WP-CLI | `wordpress:cli-2.12.0-php8.3` | `sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586` |
| latest WP-CLI | `wordpress:cli-2.12.0-php8.5` | `sha256:c2685291859c333b38afdbf882c5b9abdc0423703f3a8c6539bfd5ee3e7e2656` |
| minimum PHP base | `php:7.4.33-apache` | `sha256:c9d7e608f73832673479770d66aacc8100011ec751d1905ff63fae3fe2e0ca6d` |
| recommended MySQL | `mysql:8.4.11` | `sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb` |
| latest MySQL | `mysql:26.7.0` | `sha256:66aec17cd21a956029b83f083b813073859e8355dc1a00e55df6ba02f0e32345` |
| minimum MySQL | `mysql:5.5.62`（linux/amd64） | `sha256:12da85ab88aedfdf39455872fb044f607c32fdc233cd59f1d26769fbf439b045` |
| Composer base | `composer:2.10.2` | `sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040` |
| quality PHP base | `php:8.3.33-cli` | `sha256:6cb44388b6ffc8c9a35b4cfdf518d0565e0cf9150e479add338b498874d7e971` |
| quality build | `welcart-tiered-discounts-quality` | `sha256:a05165f872785c7553abce087c9055c533e720adc92654165e29071aa41218d0` |
| minimum composite build | `welcart-tiered-discounts-minimum-wordpress` | `sha256:608a1beb29e68d90003f25c5ad7fa34ff5509bf561746005893766d540e4af28` |

Docker Hub の匿名 pull rate limit が発生したため、同一の固定タグを `mirror.gcr.io` から取得してローカルへ公式名で tag した。Compose の既定値は公式 image 名を維持し、非公式 image への置換はしていない。

## 受入項目

### 9-2-1 ホスト前提 — PASS

- 実行: `docker version`、`docker compose version`、`git --version`、`uname -m`、`sw_vers`
- 終了コード: すべて 0
- 結果: Docker Engine 29.4.2、Docker Compose 5.1.3、Git 2.50.1、arm64 を取得した。Browser は Chrome 151.0.0.0 を実測した。

### 9-2-2 Compose の分離 — PASS

- 実行: 各プロファイルで `./scripts/dev.sh <profile> config` および `docker compose -f <compose-file> config --quiet`
- 終了コード: すべて 0
- 結果: `recommended` / `latest` / `minimum` はそれぞれ独立 project、network、volume を使用し、Web は `127.0.0.1:8080` / `8081` / `8082` に限定した。DB port の host 公開はない。

### 9-2-3 ソースマウント — PASS

- 実行: host と3つの WordPress コンテナで `plugin/index.php` の SHA-256 を比較
- 終了コード: すべて 0
- 結果: 4箇所すべて `88ba971b4024c9822d95d3c7ea6cb41c7166c491f6033e850319727a452c2454`。同一 slug `welcart-tiered-discounts` 配下へ plugin source だけが bind mount された。

### 9-2-4 所有者と書込み — PASS

- 実行: `stat` による `docker/local.env`、`plugin/index.php`、`vendor/composer/installed.json` の所有者確認、および project 内 root 所有ファイルの検索
- 終了コード: すべて 0
- 結果: `docker/local.env` は `0600 sakurai:staff`、plugin と Composer 生成物も `sakurai:staff`。root 所有の project ファイルは0件。

### 9-2-5 品質ツール runtime — PASS

- 実行: `./scripts/dev.sh recommended quality` と quality container 内の `php -v`、`php -m`、`composer --version`
- 終了コード: 0
- 結果: native arm64、PHP 8.3.33、Composer 2.10.2。DOM、mbstring、SimpleXML、tokenizer、XMLReader、XMLWriter、zip を含む必要拡張を確認した。

### 9-2-6 Composer 依存再現 — PASS

- 実行: quality 入口から `composer install --no-interaction`、`composer validate --strict`、`composer check-platform-reqs`
- 終了コード: すべて 0
- 結果: `composer.lock` から project-local `vendor/` を再構築でき、strict validation と platform requirements を満たした。

### 9-2-7 PHPCS / WPCS 依存 — PASS

- 実行: Composer lock と installed packages の確認
- 終了コード: 0
- 結果: `dealerdirect/phpcodesniffer-composer-installer` 1.2.1、PHPCS 3.13.6、WPCS 3.4.1、PHPUnit 9.6.36。Composer plugin の許可対象は installer のみ。

### 9-2-8 WPCS ruleset — PASS

- 実行: `./scripts/dev.sh recommended quality` 内の PHPCS、`phpcs -i`
- 終了コード: 0
- 結果: `phpcs.xml.dist` を読み、WordPress、WordPress-Core、WordPress-Docs、WordPress-Extra を登録した状態で `plugin/` の検査が通過した。

### 9-2-9 PHPUnit runner — PASS

- 実行: `./scripts/dev.sh recommended quality` 内の PHPUnit 設定読込みと `--list-tests`
- 終了コード: 0
- 結果: PHPUnit 9.6.36 が `phpunit.xml.dist` と `tests/bootstrap.php` を読み込んだ。機能テストは環境準備スコープ外のため0件で、runner のみ確認した。

### 9-2-10 lint / Plugin Check — PASS

- 実行: quality 入口の PHP lint、`./scripts/dev.sh recommended wp plugin check --help`
- 終了コード: 0
- 結果: `plugin/` の全 PHP ファイルが lint を通過し、Plugin Check 2.1.0 の WP-CLI 入口が起動した。

### 9-2-11 recommended runtime — PASS

- 実行: `./scripts/dev.sh recommended up`、`bootstrap`、`versions`、HTTP スモーク
- 終了コード: すべて 0、HTTP 200
- 実測: WordPress 7.0.4、PHP 8.3.33 arm64、MySQL 8.4.11 arm64、Welcart 2.12.1、Twenty Twenty-Five 1.5、Plugin Check 2.1.0、WP-CLI 2.12.0。

### 9-2-12 WP-CLI 接続 — PASS

- 実行: `./scripts/dev.sh recommended wp core version`、`wp plugin list`、`wp theme list`、`wp option get ...`
- 終了コード: すべて 0
- 結果: WordPress と同じ filesystem、network、DB を参照し、core、plugin、theme、option を取得した。

### 9-2-13 Welcart 初期状態 — PASS

- 実行: WP-CLI と標準管理画面で plugin、page、category、locale、timezone、通貨を確認
- 終了コード: CLI は0、画面操作は成功
- 結果: Welcart 2.12.1 が有効で、カート・会員ページと商品カテゴリを確認した。日本語、Asia/Tokyo、JPY、日本向けローカル設定を確認した。

### 9-2-14 基準注文 — PASS

- 操作: 標準管理画面でダミー店舗、`ローカル送料無料`、`標準配送`、`代金引換`、商品 `基準注文商品 10,000円`（code / SKU `baseline-order-10000`）を設定し、ショップ画面から注文確定まで実行
- Browser: Codex in-app Browser / Chrome 151.0.0.0、`http://127.0.0.1:8080`
- 結果: カート 10,000円、購入確認 10,000円、受注番号 `00001000`（order ID 1000）の商品計・合計 10,000円が整合した。割引機能は未実装である。

### 9-2-15 debug / mail 境界 — PASS

- 実行: 3環境で WordPress debug 定数を取得、recommended で `error_log()` marker を発生、PHP `mail()` を `.invalid` 宛てに試行、container log を確認
- 終了コード: すべて 0、`mail()` はローカル sink 受付として true
- 結果: 3環境とも `WP_DEBUG=true`、`WP_DEBUG_LOG=true`、`WP_DEBUG_DISPLAY=false`、`WP_ENVIRONMENT_TYPE=local`。`wp-content/debug.log` に marker、`/tmp/dev-mail.log` に `mail_suppressed_by_local_development_environment` を確認した。外部 SMTP・外部配送はない。

### 9-2-16 minimum runtime — PASS

- 実行: `./scripts/dev.sh minimum up`、`bootstrap`、`versions`、HTTP スモーク
- 終了コード: すべて 0、HTTP 200
- 実測: WordPress 5.6.19、PHP 7.4.33 x86_64、MySQL 5.5.62 x86_64、Welcart 2.12.1。Apple Silicon 上で `linux/amd64` emulation を使用した。

### 9-2-17 latest runtime — PASS（観測完了）

- 実行: `./scripts/dev.sh latest up`、`bootstrap`、`versions`、HTTP スモーク
- 終了コード: 起動・初期化・HTTP は0 / 200
- 実測: WordPress 7.1、PHP 8.5.9 arm64、MySQL 26.7.0 Innovation、Welcart 2.12.1。
- 備考: Welcart の公称範囲外で、PHP 8.5 により Welcart の `Non-canonical cast (double)` deprecation と WP-CLI 依存の null array offset 警告を観測した。前方互換性の警告として記録し、動作保証とは扱わない。

### 9-2-18 SSoT 整合 — PASS

- 実行: `docs/SSoT/README.md`、課題条件、技術構成、判断ログ、索引の突合、および MVP / 機能実装語の検索
- 終了コード: 0
- 結果: 3環境と品質ツールの固定値が作業フレームに整合した。既存の将来文書プレースホルダーは `未着手` のままで、MVP、割引機能仕様、機能実装計画、機能コードを追加していない。

### 9-2-19 README / AGENTS — PASS

- 実行: README のコマンドを用いて init、config、up、bootstrap、versions、logs、quality、down を実行し、AGENTS.md の概要を確認
- 終了コード: すべて 0
- 結果: ホストに PHP / Composer を導入せず、README から依存導入、3環境、source mount、debug、基準注文、品質検査を再実行できる。AGENTS.md は独立 WordPress plugin 開発リポジトリとしての最小概要を持つ。

### 9-2-20 Git 非汚染 — PASS

- 実行: `git status --short`、`git check-ignore -v`、root 所有・秘密値候補・runtime 生成物の検索
- 終了コード: すべて 0
- 結果: `vendor/`、`.phpunit.result.cache`、debug log、`docker/local.env`、WordPress / Welcart / DB / volume 実体は ignore 対象で、Git 差分へ混入しない。`docs/work/log/2026/08.md` は今回の実装前から存在する未追跡作業記録として commit 対象から除外する。

### 9-2-21 完了記録 — PASS

- 実行: 9-2-1 から 9-2-20 の一次結果、固定 image digest、時刻、Browser 版を再照合
- 終了コード: 0
- 結果: `recommended` の必須受入は全件 PASS。`minimum` と `latest` は起動・スモーク・結果記録まで完了した。課題実装の計測はまだ開始していない。

## 最終判定

| 判定項目 | 状態 |
|---|---|
| `recommended` の必須受入 | `PASS` |
| `latest` の実行・記録 | `PASS`（公称範囲外の警告を記録） |
| `minimum` の実行・記録 | `PASS`（linux/amd64 emulation） |
| 品質検査入口 | `PASS` |
| 文書・Git 非汚染 | `PASS` |
| 開発作業準備 | `PASS` |
| 課題実装の計測開始 | `NOT_STARTED` |


## 2026-09-07 Issue #4 実装時の環境確認

上記の環境準備時の PASS と、今回の機能受入れを区別する。実装・HTTP 金額検証は独立した C（8280）へ `codex/issue4-implementation` のコードを接続して実施した。A と B の使用環境は切り替えていない。

| 対象 | 実測と結果 |
|---|---|
| C / recommended | WordPress 7.0.4、Welcart ヘッダー 2.12.1、PHP 8.3.33、MySQL 8.4.11。設定・購入・ポイント・受注編集を実 WordPress で検証 |
| minimum | WordPress 5.6.19、Welcart 2.12.1、PHP 7.4.33、MySQL 5.5.62。起動・版確認・有効化 exit 0。固定額計算および native カート→確認ゲート→注文保存の試行 exit 0（専用注文 1、商品 10,000／割引 500／請求 9,500） |
| latest | WordPress 7.1、PHP 8.5.9、DB image `mysql:26.7.0`。起動・版確認 exit 0。ただし既存 DB volume の認証不一致（mysqli 1045）で有効化と購入経路の前提を満たせず exit 1。Welcart 版未取得 |
| 補助環境の保持 | 通常 down を実行して停止（exit 0）、volume を保持。認証変更・reset・再初期化は行わない |
| Plugin Check 2.1.0 | 実行 exit 0、出力内の ERROR は `outdated_tested_upto_header` 1 件。`Tested up to: 7.0` を実測範囲に留めるため、WordPress.org 掲載審査の合格とは扱わない |

minimum の native 保存成功は、HTTP ブラウザ・メール本文や主環境の全試験成功を示すものではない。latest の DB 接続失敗を製品互換性の成功に読み替えない。操作・終了コード・金額と Red/Green の詳細、画面証拠は [実装・検証レポート](visdoc/briefing/0907_Welcart割引実装/実装・検証レポート.md) に集約する。作業時間を計測・算入して MVP 完成を判定しない。

## 2026-09-08 dev版の未実施試験を補完

対象はdev `5b35fd08cb979b0b48a6d740ed3be7f264625686` のプラグインを含む調整版参考ZIP。プライマリのdevで実行し、新しいworktreeとサブエージェントは使用していない。main `b0c0e1a1462d86d6cb1d25ce9f5452c748320958`、プラグイン本体、既存の正式提出用・参考用ZIPは変更していない。

### 環境と新規導入

| 環境 | WordPress / Welcart | PHP / MySQL | テーマ | 結果 |
|---|---|---|---|---|
| latest（8081） | 7.1 / 2.12.1 | 8.5.9 / 26.7.0 | Twenty Twenty-Five 1.5 | 新規WordPressへZIPを導入・有効化、exit 0 |
| minimum（8082） | 5.6.19 / 2.12.1 | 7.4.33 / 5.5.62 | Twenty Twenty-One 1.1 | 新規WordPressへZIPを導入・有効化、exit 0 |

既存DBの認証不一致とDockerの自動アドレス割当枯渇を避けるため、未使用を確認した `10.254.80.0/24`・`10.254.81.0/24` と、末尾が `-dev-zip-20260908` の新しい専用volumeを使用した。旧volume・networkを削除せず、A〜EのWeb/DB構成は変更していない。実行用Composeはセッションの一時ディレクトリに置き、リポジトリの標準Composeは変更していない。

プラグインのbind mountを外し、`wp plugin install /artifacts/welcart-tiered-discounts-1.0.0-dev-adjusted-reference-5b35fd0.zip --activate` で両環境へ導入した（各exit 0）。導入後、全10ファイルの実バイト列がZIPと一致することをPythonで検査した（各10/10、exit 0）。ZIPのSHA-256は `ea953c29d3d972838d8c5bdb714c8ff02068fe8f3ee66b514e2f7bab7e8cbb44`。

### 実行結果

| 検査・操作 | 結果 |
|---|---|
| latest: `docker exec -w /workspace welcart-tiered-discounts-latest-wordpress-1 php vendor/bin/phpunit --group integration` | exit 0、68 tests / 1,042 assertions、14.989秒 |
| minimum: 上記コンテナ名を `welcart-tiered-discounts-minimum-wordpress-1` へ変更 | exit 0、68 tests / 1,042 assertions、42.144秒 |
| `./scripts/dev.sh recommended quality` | exit 0。Composer検査・PHP lint・PHPCS/WPCS・単体68 tests / 96 assertions |
| ブラウザー実操作（両環境） | 商品→カート→購入者情報→配送・ローカル振込→内容確認→注文完了。商品10,000円、割引500円、内消費税863円、請求9,500円 |
| 最新環境の管理画面 | ステップ割引設定を保存し、保存成功表示と保存後の値を確認 |
| ブラウザー注文のDB・メール本文読戻し | 両環境とも専用注文ID 1032。受注・snapshotの9,500円、捕捉メールの割引500円・内税863円・合計9,500円が一致（`wp eval-file .../verify-ui.php`、各exit 0） |
| 通常構成のPlugin Check 2.1.0 | `docker exec welcart-tiered-discounts-latest-cli-1 wp plugin check welcart-tiered-discounts` はexit 0。ただし出力に `outdated_tested_upto_header` ERROR 1件 |

連携試験には設定入力、HTTP購入とメール本文、税・ポイント、受注編集・追加削除・保存保護、SQL失敗時のロールバック、同時保存、native関数への置換比較を含む。画面はCodexのブラウザー実操作・アクセシビリティツリーとスクリーンショットで確認した。メールはローカルで捕捉し、実配送と外部決済の実取引は行っていない。

### 初回失敗とテスト補助コードの修正

- latest初回は68 tests / 1,035 assertions、failure 1・risky 3、exit 1。新規環境の `WP_DEBUG_DISPLAY=true` により、意図したSQL失敗の出力が409応答を妨げていたため、標準開発構成と同じ `WP_DEBUG_DISPLAY=false`・`WP_DEBUG_LOG=true` に設定した。PHP 8.5で非推奨となる無効な後始末呼出しは、cURLがresourceのときだけcloseし、Reflectionのアクセス切替はPHP 8.1未満だけに限定した。
- minimum初回は68 tests / 929 assertions、failure 5、exit 1。WordPress 5.6には `pre_wp_mail` がないため、捕捉を `wp_mail` フィルターへ移した。5.7以降の送信抑止は維持し、5.6では既存のローカルsendmail sinkが実配送を破棄する。native比較テストのnull offset警告文はPHP 7.4と8以降それぞれの実メッセージで照合する。
- 変更は `tests/OrderFailureHttpTest.php`、`tests/OrderHookReplacementTest.php`、`tests/fixtures/HttpClient.php`、`tests/fixtures/wtd-test.php` のみ。金額・DB保存・ロールバックの期待値を緩めず、修正後に両環境の全連携試験を再実行して上表の成功を確認した。

検証後は両環境とも試験用MUプラグインのmountを外し、`WTD_TEST_MODE=0` の通常構成へ戻した（各exit 0、mountと環境変数の読戻し済み）。新しい検証用volumeは保持している。

`Tested up to: 7.0` はZIP同一性を維持するため変更していない。今回の7.1機能試験成功と、WordPress.org掲載用Plugin Checkの未解消指摘は別々に扱う。この追試をmain版の受入れや時間制限内実装の判定へ読み替えない。

生ログ、終了コード・所要時間のJSON、実行用Compose、導入ファイル検証は `temp/01a07d90-54ba-7562-8926-f3290d1ab20f_20260908-102101_dev-remaining-tests/` に保存した。

### 追試後の検証済み版表記の更新

ユーザーの指示により、devの `plugin/readme.txt` の `Tested up to` を7.0から7.1へ更新した。READMEには、mainが主たる提出用、devが時間超過後に調整した参考版であることを明記した。mainの表記・コード、既存ZIPは変更していない。

最新環境の導入済み `readme.txt` だけを更新し、通常構成で `docker exec welcart-tiered-discounts-latest-cli-1 wp plugin check welcart-tiered-discounts` を再実行した。終了コード0、`Success: Checks complete. No errors found.` を確認し、上記の `outdated_tested_upto_header` はdevで解消した。ログは同じ一時ディレクトリの `latest-plugin-check-readme71.log`。先のZIP全ファイル一致は、この表記更新前の検証結果である。
