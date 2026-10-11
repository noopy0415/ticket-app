@AGENTS.md

# まちチケ

主催者がイベントと券種を登録し、参加者が Stripe Checkout でチケットを買うアプリ

## このプロジェクトの決まり

- 画面は Livewire 4 の単一ファイルコンポーネント（`resources/views/pages/**/⚡*.blade.php`）で作る。Volt は使わない
- UI は Flux UI の Free 版のコンポーネントだけを使う（Pro 専用は使わない）。無いものは Tailwind で作る
- 文言は日本語。日付は `isoFormat('M月D日(ddd) HH:mm')`、金額は「3,000円」の形式
- 認可は `app/Policies/` のポリシーで行い、画面側では `$this->authorize()` を使う
- 外部サービス（Stripe など）に触るコードは `app/Services/` に閉じ込める。テストではそのクラスをモックする
- 機能を追加したら `tests/Feature/` に Pest テストを追加し、`/check` が通ることを確認する
- 開発コマンドは Sail 経由（`sail artisan ...`、`sail composer ...`、`sail npm ...`）。ホストの php や composer は使わない
- ビューを追加・変更したら `sail npm run build` を実行する（Tailwind がクラスを拾うため）

## 作業の進め方

- 変更の前に、何をどのファイルに書くかを短く説明してから実装する
- 1回の指示で扱う範囲は1機能まで。大きい依頼は分割案を先に出す
- 既存のファイルの書き方に合わせる（同じ階層のファイルを先に読む）
- `.env` の値、API キー、パスワードはコードやコミットに含めない
- 失敗したコマンドは、原因の推測と対処案を1つずつ示してから再実行する

## 設計

詳細は [doc/design/database.md](doc/design/database.md)（ER 図、カラム定義）と [doc/design/screens.md](doc/design/screens.md)（画面一覧、URL）を見る

### 用語

- イベント（`events`）: 主催者が登録する催し。`draft`（下書き）/ `published`（公開）/ `unpublished`（管理者による非公開）
- 券種（`ticket_types`）: イベントごとの価格・定員・販売期間の区分（一般、学生など）
- 注文（`orders`）: 1回の購入。1注文 = 1イベント。`pending`（支払い待ち）→ `paid`（支払い済み）
- 注文明細（`order_items`）: 注文に含まれる券種と枚数。単価は注文時点の値を保存する
- チケット（`tickets`）: `paid` になったときに枚数分発行する。1枚ずつ固有の `code` を持つ

### データの決まり

- 主催者は `events.organizer_id`、注文者は `orders.user_id`。どちらも `users.id` を参照する
- ロール・状態は DB の enum 型にせず、string ＋ PHP Enum（`UserRole`、`EventStatus`、`OrderStatus`）でキャストする
- 金額は円の整数（unsignedInteger）。明細の金額は `ticket_types.price` ではなく `order_items.unit_price` を使う
- 残り枚数はカラムに持たない。`capacity` −（`paid` の数量 ＋ 15分以内の `pending` の数量）で毎回計算する。集計は `withSum()` などで N+1 にしない
- 売上につながる外部キー（主催者、注文者、注文のイベント、明細の券種）は restrict にする。親の一部であるもの（券種、明細、チケット）は cascade にする
- `paid` への更新は「`pending` のときだけ更新する」条件付き更新にする（Webhook と完了ページの二重処理を防ぐ）

### 画面の決まり

- 公開一覧は `/`、詳細は `/events/{event}`。下書きは所有者以外には 404
- 主催者向けの画面は `/organizer/...` にまとめ、ミドルウェアでロールを制限する。所有者かどうかはポリシーで確認する
- 購入は独立した画面にせず、イベント詳細のフォームから処理する
- 注文とチケットは `/orders/...`。見られるのは本人と、そのイベントの主催者だけ
