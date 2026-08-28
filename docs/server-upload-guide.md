# サーバーアップロード手順

この資料は、Report システムを本番サーバーへアップロードする時の手順です。
本番公開 URL は `http://www.sugie-k.com/report/` を前提にしています。

## 前提

- ローカル作業場所: `C:\xampp81\htdocs\Report`
- 本番の公開先: `/report/`
- 本番 API の公開先: `/report/backend/`
- フロントエンド本番 API 設定: `frontend/.env.production`

`frontend/.env.production` は以下の設定です。

```env
REACT_APP_API_BASE=/report/backend
```

`frontend/package.json` の `homepage` は以下の設定です。

```json
"homepage": "http://www.sugie-k.com/report/"
```

## アップロード前の確認

作業前に、現在の変更内容を確認します。

```powershell
git status --short --untracked-files=all
```

フロントエンドを変更した場合は、ビルドします。

```powershell
cd C:\xampp81\htdocs\Report\frontend
npm run build
```

PHP ファイルを変更した場合は、構文チェックします。

```powershell
php -l backend\対象ファイル.php
```

複数ファイルを変更した場合は、変更した PHP ファイルごとに確認してください。

## アップロード対象

### フロントエンドを変更した場合

`frontend/build` の中身を、本番サーバーの `/report/` 直下へアップロードします。

例:

```text
ローカル: C:\xampp81\htdocs\Report\frontend\build\*
本番:    /report/
```

`build` フォルダそのものを `/report/build/` に置くのではなく、`build` の中身を `/report/` に配置します。

主なアップロード対象:

- `index.html`
- `asset-manifest.json`
- `static/`
- その他 `frontend/build` に生成されたファイル

### バックエンドを変更した場合

`backend` 配下の変更ファイルを、本番サーバーの `/report/backend/` 配下へ同じ階層でアップロードします。

例:

```text
ローカル: C:\xampp81\htdocs\Report\backend\t_work_reports\export_month_xls.php
本番:    /report/backend/t_work_reports/export_month_xls.php
```

### Composer 依存関係を変更した場合

`composer.json` または `composer.lock` を変更した場合は、サーバー側にも `vendor` が必要です。

サーバーで Composer が使える場合:

```bash
composer install --no-dev
```

サーバーで Composer が使えない場合は、ローカルの `vendor` フォルダを本番へアップロードします。

```text
ローカル: C:\xampp81\htdocs\Report\vendor\
本番:    /report/vendor/
```

Excel 出力では `phpoffice/phpspreadsheet` を使用しているため、`vendor` が不足すると Excel 出力でエラーになります。

## アップロードしないもの

通常、以下は本番へアップロード不要です。

- `frontend/src/`
- `frontend/node_modules/`
- `frontend/public/`
- `frontend/package-lock.json`
- `.git/`
- `.agents/`
- `.codex/`
- `outputs/`

ただし、バックアップ目的で丸ごと配置する運用の場合は、公開不要なファイルが Web から参照できないよう注意してください。

## 作業パターン別

### PHP のみ変更した場合

1. 変更した PHP ファイルを `php -l` で確認
2. 対象ファイルだけを `/report/backend/` にアップロード
3. 該当画面・API を本番で確認

### React 画面を変更した場合

1. `frontend` で `npm run build`
2. `frontend/build` の中身を `/report/` にアップロード
3. ブラウザで `http://www.sugie-k.com/report/` を開く
4. 必要に応じてキャッシュ削除、またはスーパーリロードして確認

### PHP と React の両方を変更した場合

1. PHP の構文チェック
2. `frontend` で `npm run build`
3. `backend` の変更ファイルを `/report/backend/` にアップロード
4. `frontend/build` の中身を `/report/` にアップロード
5. 本番画面で一連の動作確認

## 動作確認

アップロード後、以下を確認します。

```text
http://www.sugie-k.com/report/
```

確認項目:

- ログインできる
- 日報画面が開く
- 今回変更した画面・ボタンが表示される
- 登録・更新・削除など、変更対象の操作ができる
- Excel 出力を変更した場合は、実際にダウンロードできる

エラーが出た場合は、サーバー側のログを確認します。

主なログ:

- `backend/logs/auth.log`
- `backend/logs/php_error.log`
- `backend/_logs/api_YYYYMMDD.log`

## 注意点

- フロントエンドを変更しただけでは、本番には反映されません。必ず `npm run build` 後の `frontend/build` をアップロードします。
- `frontend/build` の一部だけをアップロードすると、古い JS/CSS と混ざることがあります。画面変更時は `build` の中身をまとめてアップロードしてください。
- バックエンドの DB 接続先は `backend/common/db_manager.php` で `HTTP_HOST` に `sugie-k.com` を含む場合に本番扱いになります。
- アップロード後に画面が古い場合は、ブラウザキャッシュの影響が考えられます。
- 直接 `http://www.sugie-k.com/report/daily-report` などを開いた時に 404 になる場合は、SPA のリライト設定が必要になる可能性があります。

## 失敗時の戻し方

アップロード前に、変更対象ファイルの本番バックアップを取っておくと安全です。

例:

```text
export_month_xls.php
export_month_xls.php.bak_20260825
```

問題が出た場合は、バックアップファイルを元の名前に戻して再確認します。
