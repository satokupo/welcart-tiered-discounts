# AGENTS.md

このファイルはリポジトリの運用ルールの正本（SSoT）。CLAUDE.md は `@AGENTS.md` で本ファイルを読み込むアダプタであり、内容は常にここへ書く（CLAUDE.md へは複製しない）。

## リポジトリ概要

Welcart に商品代金に応じた段階式の自動割引機能を追加する、独立した WordPress プラグインの開発リポジトリ。Welcart 本体・WordPress テーマのファイルを改変せず、公開された拡張経路を利用する。

## プロジェクトルート

リポジトリ直下を、プラグインのソース、Docker による検証環境、品質検査、関連文書を管理する単位とする。WordPress 本体、Welcart 本体、テーマ、アップロード、データベース実体は Docker のランタイム側で管理する。

## 主要ディレクトリ

- `plugin/`: 開発対象プラグインのソース
- `tests/`: プロジェクトローカルのテスト入口
- `docker/`: Docker イメージ補助ファイルとローカル環境サンプル
- `scripts/`: 開発環境の標準操作入口
- `docs/SSoT/`: 課題条件、技術構成、設計判断の正本
- `docs/work/`: 作業の進捗と記録
- `docs/visdoc/`: 作業フレームなどの Markdown-first 資料

## プロジェクト正本への入口

このプロジェクト固有の仕様・技術構成・設計判断を確認する場合は、まず [`docs/SSoT/README.md`](docs/SSoT/README.md) を参照する。環境の実測結果は [`docs/ENVIRONMENT_VERIFICATION.md`](docs/ENVIRONMENT_VERIFICATION.md) に記録する。

## 外部技術知識の参照先

- WordPress プラグインの設計・実装・レビューでは、WordPress 公式の `wp-plugin-development` Skill を第一参照先として使用する。Skill の内容をこのリポジトリの文書へ複製しない
- Skill だけで判断できない、または WordPress の仕様・APIについて正確な確認が必要な場合は、WordPress 公式ドキュメントを参照する
- WPCS 準拠の最終判定は推測で行わず、このリポジトリに設定された PHPCS / WordPressCS を実行して確認する

## 運用ルール

- **着手前に SSoT**: 仕様確認・設計・実装・相談の前に `docs/SSoT/README.md`（仕様・設計の正本の索引）を開き、読むべき文書を判定する
- **条件付きRuleは分散配置**: 指示であって資料でないもの（読むとAIの次の行動が変わるもの）は `.agents/conditional-rules/` へ置き、下記routerへ1行追加する。本文をこのファイルなど常時読み込まれる場所へ複製しない

- **仕様の境界**: 課題提供者の指定は `docs/SSoT/ASSIGNMENT_REQUIREMENTS.md`、今回の採用範囲は `docs/SSoT/MVP_SCOPE.md` を正本とする。未決・提案は `docs/SSoT/OPEN_QUESTIONS.md`、後続候補と順序は `docs/work/` へ分け、MVP の要求へ混ぜない
- **実測と採用の区別**: 公開リファレンスの候補を採用済みと扱わない。環境準備の検証結果を割引機能の受入れ結果へ読み替えない

## 条件付きRule router

次のpathはリポジトリルートからの相対path。発火条件に一致する文書だけを読み、無関係な文書は読まない。

| 発火条件 | 1行概要 | path |
|---|---|---|
| 割引プラグインの設計・実装・レビュー・検証を行うとき | TDD、連携根拠、UI 確認、提出用の検証を揃える。 | `.agents/conditional-rules/plugin-development.md` |
