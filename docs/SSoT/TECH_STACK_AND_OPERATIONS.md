# 技術構成と運用

## 適用範囲

Welcart 向け独立 WordPress プラグインをローカルで開発・検証するための実行環境、品質検査環境、バージョン方針の正本。逐次的なセットアップ手順、機能仕様、実装計画は扱わない。

ローカル並列開発での A〜E の割当、worktree との対応、環境の再利用、DB 更新の統合方針は [ローカル開発](LOCAL_DEVELOPMENT.md) を正本とする。A〜E は下記 `recommended` の環境インスタンスであり、新しいバージョン検証プロファイルではない。

## 実行環境

- コンテナ実行基盤: Docker
- 構成管理: Docker Compose の YAML
- Web 実行環境: WordPress 公式 Docker イメージ（`minimum` は公式アーカイブを公式 PHP イメージ上へ構成）
- データベース: MySQL 公式 Docker イメージ
- 管理補助: WP-CLI を各環境のコンテナから利用できる構成
- プラグインソース: リポジトリ内の `plugin/` を WordPress の `wp-content/plugins/welcart-tiered-discounts` へマウント
- WordPress 本体、Welcart 本体、テーマ、アップロード、データベース実体: Git 管理対象外の Docker ボリューム
- 主たる起動定義: Docker Compose。`wp-env` は主たる実行環境にしない

## 検証環境プロファイル

3 つの環境は、Compose のプロジェクト名、ポート、ネットワーク、ボリュームを分離する。構築時点で指定したタグを Compose 定義または Dockerfile に固定し、取得時に観測した image digest は [`docs/ENVIRONMENT_VERIFICATION.md`](../ENVIRONMENT_VERIFICATION.md) へ記録する。

| プロファイル | 用途 | WordPress | PHP | DB | Welcart | テーマ | 合否の扱い |
|---|---|---:|---:|---:|---:|---:|---|
| `recommended` | 主開発・主受入れ | 7.0.4 | 8.3 | MySQL 8.4.11 | 2.12.1 | WordPress 同梱テーマ | 起動・基準注文・必須検証を通す |
| `latest` | 前方互換性の観測 | 7.1 | 8.5 | MySQL 26.7.0 Innovation | 2.12.1 | WordPress 同梱テーマ | 起動・スモークと結果の記録を必須とし、成功は必須にしない |
| `minimum` | 公称下限の境界確認 | 5.6.19 | 7.4 | MySQL 5.5.62 | 2.12.1 | WordPress 同梱テーマ | 起動・スモークと結果の記録を必須とし、成功は必須にしない。DB は `linux/amd64` |

### `recommended`

主開発・主受入れ環境とする。WordPress 7.0.4、PHP 8.3、MySQL 8.4.11、Welcart 2.12.1、WordPress 同梱テーマを組み合わせ、管理画面から基準注文までを確認する。この環境の必須検証を完了できない場合、開発作業準備完了とは判定しない。

### `latest`

WordPress 7.1、PHP 8.5、MySQL 26.7.0 Innovation、Welcart 2.12.1、WordPress 同梱テーマで前方互換性を観測する。Welcart の公称対応範囲を超える可能性があるため、結果を `recommended` の必須検証の代替にしない。起動・スモークの試行と、成功または再現可能な停止理由を記録する。

### `minimum`

Welcart 2.12.1 が案内する下限境界として、WordPress 5.6.19、PHP 7.4、MySQL 5.5.62、WordPress 同梱テーマで確認する。MySQL 5.5.62 は公式イメージの `linux/amd64` 境界を使用し、Apple Silicon では Docker の amd64 emulation を利用する。非公式な arm64 イメージへ置き換えない。起動・スモークの試行と、成功または再現可能な停止理由を記録する。

## Compose の安全境界

- 公開ポートは `127.0.0.1` にのみバインドする
- 3 プロファイルのデータを同じボリュームへ混在させない
- Web ポートは各環境で分離し、DB ポートはホストへ公開しない
- 起動・停止と、ボリュームを削除する初期化操作を分ける
- 通常の停止操作でボリュームを削除しない
- 固定したバージョン、CPU architecture、取得時の image digest を検証記録から追跡できる状態にする
- WordPress、Welcart、テーマの自動更新を検証中は行わない
- マルチサイトは使用しない

## 依存関係と Git 管理

- PHP の開発依存は Composer で宣言し、`composer.lock` を Git 管理する
- WordPress Coding Standards、PHP_CodeSniffer、PHPUnit などの開発ツールはホストへグローバル導入せず、PHP 8.3.33 の専用品質コンテナから実行する
- Composer は 2.10.2 を使用し、依存関係は lock から非対話で再導入できるようにする
- `vendor/`、WordPress 本体、Welcart 本体、テーマ、生成されたアップロード、データベース、Docker volume、キャッシュ、ログ、秘密値を Git 管理しない

## 品質検査の入口

開発作業開始時点で、標準操作入口から少なくとも次を実行または起動確認できる構成にする。

- PHP 構文検査
- PHPCS と WPCS 3.4 系列による静的規約検査
- PHPUnit 9.6 系列によるテスト runner
- WordPress Plugin Check 2.1.0 によるプラグイン検査
- 3 つの Compose YAML の構文・展開検査

品質ツールの依存は project-local とし、`dealerdirect/phpcodesniffer-composer-installer` のみ Composer plugin として明示許可する。環境準備では機能テストを作成せず、PHPUnit runner の設定読込みと起動可能性を確認する。

## ソースとデバッグ

- `plugin/` だけを各 WordPress コンテナの同一プラグイン slug へ bind mount する
- WordPress 本体、Welcart 本体、テーマをリポジトリへコピーまたは改変しない
- `WP_ENVIRONMENT_TYPE=local` と WordPress debug log、PHP error log、container log を有効にする
- PHP の `sendmail_path` はローカルの mail sink または決定論的な抑止経路へ向け、外部 SMTP・外部メール配送を構成しない
- ログと生成物は Git 管理外とし、検証時の非機密な警告と読取経路だけを記録する

## 認証と秘密情報

- WordPress 管理者認証とデータベース認証情報はローカル検証専用のダミー値を使う
- 個人サービス、本番、外部決済、外部 SMTP の認証情報を流用しない
- 秘密値の実物を SSoT、README、Compose YAML、Git 履歴へ記録しない
- コミット可能なサンプルと Git 管理外の `docker/local.env` を分離する

## 検証の役割分担

- 主検証: `recommended` で管理画面、ショップ画面、カート、購入確認、確定後の受注データ、および品質検査を確認する
- 補助検証: `latest` で前方互換性、`minimum` で公称下限の境界挙動を確認する
- `latest` と `minimum` は実行・記録を必須とし、環境起因の失敗は理由を残す。成功は主検証の代替にならず、主検証の合格を自動的に取り消さない
- 実際に検証した WordPress、Welcart、PHP、DB、Browser の各バージョンと image digest を検証記録へ追記する

## 開発作業準備の完了条件

次をすべて満たした時点で、開発作業準備を完了とする。

1. 3 プロファイルの Compose YAML が構文・展開検査を通る
2. `recommended` が起動し、WordPress、PHP、DB、Welcart、テーマの実バージョンを取得できる
3. `recommended` の WordPress 管理画面とショップ画面へアクセスできる
4. 検証用商品を用いて、カートから購入確認画面まで到達できる
5. `latest` と `minimum` を起動・スモークし、成功または再現可能な停止理由を記録できる
6. 品質検査の各入口が存在し、実行可否を確認できる
7. 起動、停止、再構築、バージョン確認の操作手順を [ローカル開発](LOCAL_DEVELOPMENT.md) からたどれる
8. 環境を起動・停止しても、意図しない生成物が Git の変更として残らない

課題の想定作業時間の計測は、この完了条件をすべて満たした後に開始する。環境準備中は MVP、機能設計、実装計画、割引プラグインの実装を確定しない。

## 公式情報源

- [Welcart のシステム要件](https://www.welcart.com/documents/)
- [Welcart e-Commerce の WordPress.org 配布ページ](https://wordpress.org/plugins/usc-e-shop/)
- [WordPress のリリース一覧](https://wordpress.org/download/releases/)
- [WordPress の要件](https://wordpress.org/about/requirements/)
- [WordPress Hosting Handbook のサーバー環境](https://make.wordpress.org/hosting/handbook/server-environment/)
- [WordPress 公式 Docker イメージ](https://hub.docker.com/_/wordpress)
- [PHP 公式 Docker イメージ](https://hub.docker.com/_/php)
- [Composer 公式配布](https://getcomposer.org/download/)
- [MySQL 公式 Docker イメージ](https://hub.docker.com/_/mysql)
- [WordPress Plugin Check](https://wordpress.org/plugins/plugin-check/)
