// frontend\src\app\Calendar\CalendarPage.jsx

import React from 'react';
import {
  Stack,
  Paper,
  Select,
  MenuItem,
  CircularProgress,
  Typography,
  Box,
  Divider,
  Chip,
  Button,
} from '@mui/material';
import Grid from '@mui/material/Grid2';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import MonthCalendar from '../../components/MonthCalendar';
import dayjs from 'dayjs';
import '../../sass/_calendar.scss';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import EditIcon from '@mui/icons-material/Edit';

/** ▼ BASE_ADDR を常に「絶対URL」に正規化する */
const RAW_BASE_ADDR = (
  process.env.REACT_APP_API_BASE ||
  (typeof window !== 'undefined' && (
    window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1'
  )
    ? 'http://localhost/Report/backend'
    : `${window.location.origin}/Report/backend`) // ← 本番は /Report/backend（大文字R）に合わせる
);

/** 末尾スラッシュを剥がしつつ、相対なら origin を前置して絶対化 */
const BASE_ADDR = (() => {
  const b = String(RAW_BASE_ADDR || '').replace(/\/+$/, '');
  if (/^https?:\/\//i.test(b)) return b; // 既に絶対URL
  if (typeof window !== 'undefined') {
    // 先頭が / のときはそのまま結合、無ければ / を挿入
    const path = b.startsWith('/') ? b : `/${b}`;
    return `${window.location.origin}${path}`;
  }
  return b;
})();

const LOCALE_OPTIONS = [
  { value: 1, label: '日本人' },
  { value: 2, label: '外国人' },
];

const STATUS = { WORK: 0, COMPANY_HOLIDAY: 1, LEGAL_HOLIDAY: 2 };

// 祝日API（表示用：日付を赤にしたい等のため）
// ※ ステータス合成はバックエンドに一本化するので、ここでは “表示用” に取得するだけ
const HOLIDAYS_JP_ALL_URL = 'https://holidays-jp.github.io/api/v1/date.json';

// 年ごとの祝日キャッシュ
const holidayCache = new Map(); // year:number -> Set<string(YYYY-MM-DD)>

// ユーザー情報からロケール区分を推定（データの型揺れに強く）
function detectLocaleTypeFromUser(user) {
  const raw = user?.nationality_id;
  const n = typeof raw === 'string' ? Number(raw) : (typeof raw === 'number' ? raw : null);
  if (n === 1 || n === 2) return n;

  const s = String(user?.nationality || user?.locale || '').toLowerCase();
  if (['2', 'foreign', 'foreigner', 'intl', 'visitor'].includes(s)) return 2;
  return 1; // 既定は日本人
}

/** 指定年の祝日Set(YYYY-MM-DD) を取得（失敗時は空Set） */
async function fetchJapanHolidaysSet(year) {
  if (holidayCache.has(year)) return holidayCache.get(year);

  try {
    const res = await fetch(HOLIDAYS_JP_ALL_URL, { cache: 'force-cache' });
    if (!res.ok) throw new Error(`holiday api failed: ${res.status}`);
    const json = await res.json(); // { "YYYY-MM-DD": "祝日名", ... }

    const set = new Set(
      Object.keys(json || {}).filter(k => typeof k === 'string' && k.startsWith(`${year}-`))
    );
    holidayCache.set(year, set);
    return set;
  } catch (e) {
    console.warn('[Calendar] holiday fetch failed (display only)', e);
    const set = new Set();
    holidayCache.set(year, set);
    return set;
  }
}

const CalendarPage = () => {
  const [year, setYear] = React.useState(dayjs().startOf('year'));
  const [localeType, setLocaleType] = React.useState(1); // 初期は日本人
  const didInitLocaleRef = React.useRef(false);
  const [isAdmin, setIsAdmin] = React.useState(false);

  const [loading, setLoading] = React.useState(true);
  const [items, setItems] = React.useState({});
  const [error, setError] = React.useState('');
  const [editMode, setEditMode] = React.useState(false);
  const abortRef = React.useRef(null);

  // ▼ 祝日（表示用）
  const [holidaySet, setHolidaySet] = React.useState(() => new Set());
  const holidayAbortRef = React.useRef(null);

  // 権限・ユーザー情報取得（初回のみ）
  React.useEffect(() => {
    (async () => {
      try {
        const res = await fetch(`${BASE_ADDR}/auth/user.php`, {
          method: 'GET',
          credentials: 'include',
          cache: 'no-store',
        });
        const j = res.ok ? await res.json() : null;

        const role = String(j?.user?.role || '').toLowerCase();
        setIsAdmin(role === 'admin');

        if (!didInitLocaleRef.current) {
          const lt = detectLocaleTypeFromUser(j?.user);
          setLocaleType(lt);
          didInitLocaleRef.current = true;
        }
      } catch {
        setIsAdmin(false);
        didInitLocaleRef.current = true;
      }
    })();
  }, []);

  // 年間カレンダー取得（バックエンドの結果をそのまま採用：初期休日生成は backend に一本化）
  const fetchCalendar = React.useCallback(async (y, lt) => {
    if (abortRef.current) abortRef.current.abort();
    abortRef.current = new AbortController();

    setLoading(true);
    setError('');
    try {
      const url = `${BASE_ADDR}/t_calendars/select.php?year=${encodeURIComponent(String(y))}&locale_type=${encodeURIComponent(String(lt))}`;

      const res = await fetch(url, {
        signal: abortRef.current.signal,
        credentials: 'include',
        cache: 'no-store',
      });
      if (!res.ok) throw new Error(`GET failed: ${res.status}`);
      const data = await res.json();
      if (!data?.ok) throw new Error(data?.message || 'unknown error');

      // ▼ ここは “サーバのitems” をそのまま表示
      setItems(data.items || {});
    } catch (e) {
      if (e.name !== 'AbortError') {
        console.error(e);
        setError(e.message || 'Failed to fetch');
        setItems({});
      }
    } finally {
      setLoading(false);
    }
  }, []);

  React.useEffect(() => {
    fetchCalendar(year.year(), localeType);
  }, [year, localeType, fetchCalendar]);

  // ▼ 祝日取得（表示用）。year が変わった時だけ取る
  React.useEffect(() => {
    const y = year.year();

    // AbortController（fetch用）※古いブラウザ考慮なら削ってもOK
    if (holidayAbortRef.current) holidayAbortRef.current.abort();
    holidayAbortRef.current = new AbortController();
    const signal = holidayAbortRef.current.signal;

    (async () => {
      const set = await fetchJapanHolidaysSet(y);
      if (!signal.aborted) setHolidaySet(set);
    })();

    return () => {
      if (holidayAbortRef.current) holidayAbortRef.current.abort();
    };
  }, [year]);

  // 日セルクリック（管理者かつ編集モード時のみ動作）
  const handleCellClick = React.useCallback(async (dateStr) => {
    if (!(isAdmin && editMode)) return;
    const before = items[dateStr] ?? STATUS.WORK;
    const next = (before + 1) % 3;

    // 楽観的更新
    setItems(prev => ({ ...prev, [dateStr]: next }));
    try {
      const res = await fetch(`${BASE_ADDR}/t_calendars/update.php`, {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ the_date: dateStr, locale_type: localeType, status: next }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.message || `toggle failed ${res.status}`);
      const fixed = Number(data.status);
      setItems(prev => ({ ...prev, [dateStr]: fixed }));
    } catch {
      setItems(prev => ({ ...prev, [dateStr]: before }));
      setError('更新に失敗しました。再読込してください。');
    }
  }, [items, localeType, isAdmin, editMode]);

  const months = React.useMemo(() => Array.from({ length: 12 }, (_, i) => i + 1), []);
  const handleExportYear = () => {
    const y = year.year();
    const url = `${BASE_ADDR}/t_calendars/export_year_xls.php?year=${y}`;
    window.open(url, '_blank', 'noopener,noreferrer');
  };

  return (
    <Box id="calendar-page">
      <Paper id="calendar-paper">
        <Stack spacing={2}>
          <Stack direction="row" alignItems="center" spacing={1} flexWrap="wrap" useFlexGap>
            {!isAdmin && <Chip size="small" label="一般ユーザーは編集できません" />}
            <Box flex={1} />
            <Box
              sx={{
                display: { xs: 'block', sm: 'none' },
                bgcolor: '#f5f5f5',
                borderRadius: 1,
                p: 0.5,
                px: 1,
                border: '1px solid #ddd',
                textAlign: 'center',
                whiteSpace: 'nowrap',
                overflow: 'hidden',
              }}
            >
              <Typography variant="caption" sx={{ fontSize: '0.75rem', color: '#555', whiteSpace: 'nowrap' }}>
                <strong>凡例：</strong> 社＝社内休日　法＝法定休日　祝＝祝日（表示）
              </Typography>
            </Box>
          </Stack>

          {/* PC操作行 */}
          <Stack
            direction="row"
            spacing={2}
            alignItems="center"
            flexWrap="wrap"
            sx={{ display: { xs: 'none', sm: 'flex' } }}
          >
            <LocalizationProvider dateAdapter={AdapterDayjs}>
              <DatePicker
                label="年"
                views={['year']}
                value={year}
                onChange={(v) => v && setYear(v.startOf('year'))}
                slotProps={{ textField: { size: 'small' } }}
                disabled={loading}
              />
            </LocalizationProvider>

            <Select
              value={localeType}
              onChange={(e) => setLocaleType(Number(e.target.value))}
              size="small"
              disabled={loading}
            >
              {LOCALE_OPTIONS.map(o => (
                <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>
              ))}
            </Select>

            {isAdmin && (
              <Button
                size="small"
                startIcon={<EditIcon />}
                variant={editMode ? 'contained' : 'outlined'}
                color={editMode ? 'warning' : 'primary'}
                onClick={() => setEditMode(v => !v)}
                disabled={loading}
              >
                {editMode ? '閲覧に戻す' : '編集'}
              </Button>
            )}

            <Box flex={1} />

            <Button
              size="small"
              variant="contained"
              startIcon={<FileDownloadIcon />}
              onClick={handleExportYear}
              disabled={loading}
            >
              エクセル出力
            </Button>
          </Stack>

          {/* スマホ操作行 */}
          <Stack direction="column" spacing={1.2} sx={{ display: { xs: 'flex', sm: 'none' } }}>
            <LocalizationProvider dateAdapter={AdapterDayjs}>
              <DatePicker
                label="年"
                views={['year']}
                value={year}
                onChange={(v) => v && setYear(v.startOf('year'))}
                slotProps={{ textField: { size: 'small', fullWidth: true } }}
                disabled={loading}
              />
            </LocalizationProvider>

            <Select
              value={localeType}
              onChange={(e) => setLocaleType(Number(e.target.value))}
              size="small"
              disabled={loading}
              sx={{ width: '100%' }}
            >
              {LOCALE_OPTIONS.map(o => (
                <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>
              ))}
            </Select>

            {isAdmin && (
              <Button
                size="small"
                startIcon={<EditIcon />}
                variant={editMode ? 'contained' : 'outlined'}
                color={editMode ? 'warning' : 'primary'}
                onClick={() => setEditMode(v => !v)}
                disabled={loading}
                sx={{ width: '100%' }}
              >
                {editMode ? '閲覧に戻す' : '編集'}
              </Button>
            )}

            <Button
              size="small"
              variant="contained"
              startIcon={<FileDownloadIcon />}
              onClick={handleExportYear}
              disabled={loading}
              sx={{ width: '100%' }}
            >
              エクセル出力
            </Button>
          </Stack>

          <Divider />

          {loading ? (
            <Stack alignItems="center" justifyContent="center" style={{ minHeight: 240 }}>
              <CircularProgress size={28} />
            </Stack>
          ) : error ? (
            <Typography color="error" style={{ padding: 8 }}>{error}</Typography>
          ) : (
            <Grid container spacing={2} columns={12}>
              {months.map((m) => (
                <Grid key={m} size={{ xs: 12, sm: 6, md: 4 }}>
                  <MonthCalendar
                    year={year.year()}
                    month={m}
                    items={items}
                    isAdmin={isAdmin && editMode}
                    onCellClick={handleCellClick}
                    className="month-calendar-root"
                    // ▼ 祝日（表示用）を渡す：MonthCalendar 側で日付文字色を赤にするなどに使う
                    holidaySet={holidaySet}
                  />
                </Grid>
              ))}
            </Grid>
          )}
        </Stack>
      </Paper>
    </Box>
  );
};

export default CalendarPage;
