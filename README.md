# Report

工事日報・有給・年間カレンダー・各種マスタ管理を行う社内向け業務システムです。  
フロントエンドは React、バックエンドは PHP、データベースは MySQL を前提に構成されています。  
ローカル開発環境は XAMPP を使う前提で構築されています。

## システム概要

このシステムは、作業日報の入力と月次管理を中心に、以下の業務をまとめて扱います。

- ログイン / ログアウト / パスワード変更
- 日報入力・編集・削除
- 月次の日報一覧表示
- Excel 出力
- 有給申請と残日数確認
- 年間カレンダーの参照・管理
- 社員、支払区分、車両、現場、シフトの各種マスタ管理

管理者と一般ユーザーで表示される画面や操作範囲が一部異なります。

## 主な機能

### 1. 認証

- `backend/auth/login.php` でログイン
- `backend/auth/logout.php` でログアウト
- `backend/auth/user.php` でログイン中ユーザー情報を取得
- `backend/auth/change_password.php` でパスワード変更
- セッション Cookie 名は `REPORTSESSID`

### 2. 日報管理

- 画面: `/report/daily-report`
- 日別の作業時間、現場、作業内容、車両、各種手当・支払項目を入力
- 月単位で一覧表示
- 管理者はユーザー切り替えが可能
- 月次 Excel 出力、集計 Excel 出力に対応

### 3. 有給管理

- 画面: `/report/paidleave`
- 一般ユーザーは自分の有給申請・取消が可能
- 管理者は月別有給一覧、残日数一覧、Excel 出力が可能
- カレンダー休日や既存日報との整合も考慮している

### 4. 年間カレンダー管理

- 画面: `/report/calendar`
- 日本人 / 外国人の区分ごとにカレンダーを保持
- 稼働日、会社休日、法定休日を切り替え可能
- 年単位の Excel 出力に対応
- 管理者は編集可能、一般ユーザーは参照のみ

### 5. マスタ管理

以下の画面は主に管理者向けです。

- `/report/user` 社員マスタ
- `/report/reimburse` 支払区分マスタ
- `/report/vehicle` 車両マスタ
- `/report/onsite` 現場マスタ
- `/report/shift` シフトマスタ

いずれも一覧、追加、更新、削除、Excel 出力を備えています。

## 画面 / ルーティング

フロントエンドのルーティングは `frontend/src/routes/Routes.jsx` で定義されています。

- `/report/login` ログイン画面
- `/report/change-password` パスワード変更
- `/report/daily-report` 日報
- `/report/paidleave` 有給
- `/report/calendar` カレンダー
- `/report/user` 社員マスタ
- `/report/reimburse` 支払区分マスタ
- `/report/vehicle` 車両マスタ
- `/report/onsite` 現場マスタ
- `/report/shift` シフトマスタ

## 技術構成

### フロントエンド

- React 19
- TypeScript
- React Router
- Material UI
- MUI Data Grid
- Axios
- Day.js
- Sass

主なコード配置:

- `frontend/src/app/App` 認証・メイン画面
- `frontend/src/app/Report` 日報機能
- `frontend/src/app/PaidLeave` 有給機能
- `frontend/src/app/Calendar` カレンダー機能
- `frontend/src/app/User` など各マスタ画面
- `frontend/src/components` 共通 UI

### バックエンド

- PHP
- PDO
- MySQL
- Composer
- PhpSpreadsheet

主な API 配置:

- `backend/auth` 認証系
- `backend/common` CORS / DB 接続
- `backend/create` テーブル作成 / 初期データ
- `backend/m_*` 各種マスタ API
- `backend/t_*` 業務テーブル API

### データベース

`backend/create/create_table.php` から、主に以下のテーブルを利用します。

- `m_users` 社員
- `m_payments` 支払区分
- `m_vehicles` 車両
- `m_on_sites` 現場
- `m_shifts` シフト
- `t_calendars` カレンダー
- `t_paid_leaves` 有給
- `t_work_reports` 日報

## ディレクトリ構成

```text
Report/
├─ backend/
│  ├─ auth/              認証 API
│  ├─ common/            DB 接続、CORS、共通処理
│  ├─ create/            テーブル作成、初期ユーザー投入
│  ├─ m_users/           社員マスタ API
│  ├─ m_payments/        支払区分マスタ API
│  ├─ m_vehicles/        車両マスタ API
│  ├─ m_on_sites/        現場マスタ API
│  ├─ m_shifts/          シフトマスタ API
│  ├─ t_calendars/       カレンダー API
│  ├─ t_paid_leaves/     有給 API
│  ├─ t_work_reports/    日報 API
│  ├─ logs/              認証ログなど
│  └─ _logs/             API ログ
├─ frontend/
│  ├─ public/
│  ├─ src/
│  │  ├─ app/
│  │  ├─ components/
│  │  ├─ routes/
│  │  ├─ sass/
│  │  └─ types/
│  ├─ build/
│  └─ package.json
├─ vendor/
├─ composer.json
└─ README.md
```

## セットアップ手順

### 前提

- XAMPP
- Composer
- Node.js / npm
- MySQL

### XAMPP 前提のローカル構成

このプロジェクトは `C:\xampp81\htdocs\Report` 配下に配置し、Apache と MySQL は XAMPP から起動する想定です。

- ドキュメントルート配下: `C:\xampp81\htdocs\Report`
- フロントエンド API 接続先: `http://localhost/Report/backend`
- PHP 実行基盤: XAMPP の Apache
- MySQL: XAMPP 同梱の MySQL

### 1. 依存関係のインストール

ルートディレクトリで:

```powershell
composer install
```

フロントエンドで:

```powershell
cd frontend
npm install
```

### 2. XAMPP の起動

XAMPP Control Panel から以下を起動します。

- Apache
- MySQL

Apache が起動した状態で、`http://localhost/Report/` 配下の PHP にアクセスできるようになります。

### 3. データベース作成

ローカルでは `backend/common/db_manager.php` の設定から、以下が前提です。

- DB ホスト: `127.0.0.1`
- ポート: `3306`
- DB 名: `sugie_report`
- ユーザー: `root`
- パスワード: 空

まず XAMPP の phpMyAdmin などから、MySQL に `sugie_report` データベースを作成してください。

### 4. テーブル作成

ブラウザまたは CLI で以下を実行します。

```text
http://localhost/Report/backend/create/create_table.php
```

### 5. 初期ユーザー投入

以下を実行します。

```text
http://localhost/Report/backend/create/seed_users.php
```

現状のシードでは管理者ユーザーが 1 件作成されます。

- ユーザー名: `admin`
- 初期パスワード: `sugie`

初回利用後はパスワード変更を推奨します。

### 6. フロントエンド環境変数

開発用の API 接続先は `frontend/.env.development` に設定されています。

```env
REACT_APP_API_BASE=http://localhost/Report/backend
```

本番用は `frontend/.env.production` に設定されています。

```env
REACT_APP_API_BASE=/report/backend
```

## 実行方法

### 開発時

フロントエンド開発サーバーを起動します。

```powershell
cd frontend
npm start
```

標準では `http://localhost:3000` で起動します。  
API は `http://localhost/Report/backend` に向く前提です。

React 開発サーバーを使わず、XAMPP 配下でビルド済みファイルを配信する運用も可能です。その場合は `frontend/build` を利用します。

### 本番想定

フロントエンドをビルドします。

```powershell
cd frontend
npm run build
```

ビルド成果物は `frontend/build` に出力されます。  
`package.json` の `homepage` は `http://www.sugie-k.com/report/` になっています。

## DB 接続 / 環境切替

`backend/common/db_manager.php` では以下の条件でローカル / 本番を切り替えています。

- 環境変数 `REPORT_ENV=local|prod`
- または `HTTP_HOST` に `sugie-k.com` を含むかどうか

ローカルでは `127.0.0.1` の MySQL を使用します。  
本番接続情報も同ファイル内に直接定義されています。

## ログ

主なログ出力先:

- `backend/logs/auth.log` 認証ログ
- `backend/logs/php_error.log` 認証まわりの PHP エラー
- `backend/_logs/api_YYYYMMDD.log` API 共通ログ

不具合時はこの 3 つを先に確認すると原因を追いやすいです。

## 補足

- API の CORS は `backend/common/cors.php` で制御しています
- 認証系はセッションベースです
- 一部 Excel 出力は `phpoffice/phpspreadsheet` を利用しています
- フロントエンドには `build/` と `node_modules/` が含まれていますが、通常の開発では再生成可能です

## 今後の改善候補

- DB 接続情報や管理用 API キーの環境変数化
- 文字コードやメッセージの整理
- README に画面キャプチャ追加
- セットアップを CLI ベースに統一
- テスト手順の明文化
