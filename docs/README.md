# docs/ の役割分担

- **仕様・設計の正本**は `docs/SSoT/`（索引は `docs/SSoT/README.md`）。**確定した内容だけ**を置く
- **環境検証の実測記録**は [`ENVIRONMENT_VERIFICATION.md`](ENVIRONMENT_VERIFICATION.md)。21項目の受入結果、コマンド、終了コード、実測版、イメージdigestを記録する
- **段取りと作業の記録**は `docs/work/`（索引は `docs/work/INDEX.md`）。書き換わり続けるもの。**変更履歴もここ**（`docs/work/log/`）で、`CHANGELOG.md` は別に持たない
- AI の運用ナレッジ（特定の状況で読ませたい指示）は `.agents/conditional-rules/`（router はルート `AGENTS.md`）
- チャットログ・生素材は `_chatlog/`（git 追跡外。生素材は save-chatlog 時に併置可）

`docs/work/` の更新は `work-record` スキルが行う（`save-chatlog` から自動で呼ばれる）。記録の5分類（何をどこへ書くか）は `~/dotfiles/agents/conditional-rules/record-routing.md` が正本。

置き場に迷ったら「**読んだ AI の次の行動が変わるか**」で判定する。変わるなら `.agents/conditional-rules/`（指示・知識）、事実を参照するだけなら `docs/`（資料）。

`docs/visdoc/items/` は、計画書や報告書などの人間向け視覚資料を置く場所で、Git 管理外とする。
