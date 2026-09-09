# 提出レポートサイト

白地と濃紺を基調にした、4タブの静的サイトです。初期表示はプラグインの提出版main。プラグイン内でmain/devの説明とGitHubへのリンクを切り替えます。READMEは各ブランチのGitHubで読みます。本文は通常のページスクロールで読み、モーダルや枠内スクロールは使いません。

## ファイル

- `content/`: サイト用の原稿。`overview.md`は提出物と時間配分、ほかは設計メモ・AI活用レポート・動作確認の記録。旧サイト用READMEの`main.md` / `dev.md`は保持していますが、ビルド対象には含めません。
- `template.html`: ページ構造。
- `public/`: 配信用のHTML・CSS・JavaScript・画像・動画。`index.html`は生成物。
- `build.py`: インストール済みPandocで原稿をHTMLへ変換。
- `check.mjs`: 文書構造とタブ操作の依存追加なしの回帰チェック。
- `public/ui-comparison.html`: 開発中のUI比較資料の最終版v6。CSS・JavaScript・比較画像8枚を埋め込んだ単一HTML。画像リンクも同じHTMLの別タブで原寸表示します。外部フォントは読み込まず、元のCSSにあるシステムフォントを使います。
- `export-ui.py`: 元のVisDoc HTMLの絶対パスを引数に渡して、上記の単一HTMLを再生成するツール。通常のサイトビルドでは再生成しません。
- `public/implementation-review/`: 実装・自己レビュー工程で作成された「実装・検証レポート」のHTMLと、参照されている検証ログ21件・画像1枚。本文内の画面3枚とスタイル・スクリプトは元HTMLへの埋め込みを保持しています。
- `export-review.py`: `python3 docs/report/export-review.py`で、`docs/visdoc/briefing/0907_Welcart割引実装/`の元資料から上記を再生成します。本文は保持し、機能仕様・連携一覧・READMEへの相対リンク3件だけを提出版のGitHubリンクへ変換します。通常のサイトビルドでは再生成しません。
- `wrangler.jsonc`: ブラッシュアップ後のCloudflare Static Assets配信用設定。

## ローカル確認

リポジトリrootで実行します。Python 3、Pandoc、Node.jsが必要です。ブラウザーには追加のライブラリや外部APIは不要です。

```sh
python3 docs/report/build.py
node docs/report/check.mjs
python3 -m http.server 8765 --bind 127.0.0.1 --directory docs/report/public
```

`http://127.0.0.1:8765/`で開きます。原稿を変更したら再ビルドします。外部へのHTTP(S)リンクはビルド時にすべて別タブ表示へ揃えます。CSSとJavaScriptは直接再読み込みで反映されます。

## 参照版と公開前の残件

- main: `9056929086d8b9e9db7379e48bcd75fd4ea457f1`
- dev: `334907ec022fd7f3babac1e450b43bb77dabbf1e`
- READMEはサイトに転載せず、各ブランチのGitHubへ案内します。
- 実操作動画2本を掲載しています。注文は`public/media/buy3.mp4`（約38秒）、注文後の編集は`public/media/edit2.mp4`（約1分11秒）。ユーザーが撮影・編集したMP4を再圧縮せずに収録し、各動画の前に操作と確認ポイントを掲載しています。
- GitHubへの提出先アクセスは別途確保します。サイトの公開だけでは非公開リポジトリの閲覧権限は付与されません。
- まずローカルでブラッシュアップし、Cloudflareへの公開はその後に行います。

## 挿絵

`public/assets/tableware.png`は内蔵imagegenによる画像です。既存の4枚の商品画像を参照し、白背景・濃紺の影・ローポリの食器を指定しました。赤マグを後左、緑のボウルを後右、紫のカトラリーを前左、黄色のカップとソーサーを前右に置き、文字やUIは含めない指示です。
