import React, { useCallback, useEffect, useMemo, useState } from 'react';
import axios, { AxiosError } from 'axios';
import {
  Box,
  Paper,
  Stack,
  Typography,
  Button,
  IconButton,
  Divider,
  CircularProgress,
  Alert,
  Snackbar,
  Table,
  TableHead,
  TableRow,
  TableCell,
  TableBody,
  Chip,
  Tooltip,
  TextField,
  TableContainer,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
} from '@mui/material';
import Grid from '@mui/material/Grid2';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { PickersDay, PickersDayProps } from '@mui/x-date-pickers/PickersDay';
import DeleteIcon from '@mui/icons-material/Delete';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import dayjs, { Dayjs } from 'dayjs';
import isSameOrAfter from 'dayjs/plugin/isSameOrAfter';
import isSameOrBefore from 'dayjs/plugin/isSameOrBefore';
import isBetween from 'dayjs/plugin/isBetween';
import 'dayjs/locale/ja';
import '../../sass/_paidlave.scss';
import updateLocale from 'dayjs/plugin/updateLocale';
import { MobileDatePicker } from '@mui/x-date-pickers/MobileDatePicker';
import { useTheme } from '@mui/material/styles';
import useMediaQuery from '@mui/material/useMediaQuery';

dayjs.extend(updateLocale);
dayjs.updateLocale('ja', { weekStart: 0 });
dayjs.extend(isSameOrAfter);
dayjs.extend(isSameOrBefore);
dayjs.extend(isBetween);

//　テスト用
//const TEST_TODAY: Dayjs | null = dayjs("2026-05-01"); 
const TEST_TODAY: Dayjs | null = null; // ← 本番はこちらにする

/**
 * API ベースURL
 * 環境変数が無ければ localhost/Report/backend を既定とする
 */
const BASE_ADDR =
  process.env.REACT_APP_API_BASE ||
  (typeof window !== 'undefined' && (
    window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1'
  ) ? 'http://localhost/Report/backend' : `${window.location.origin}/report/backend`);

type MeResponse = {
  id: number;
  name: string;
  is_authorized: boolean; // 管理者か
  paid_holidays_num?: number; // 付与済み有休日数（m_users）
  nationality_id: 1 | 2;      // 1=日本人, 2=外国人
};

type PaidLeave = {
  id: number;
  user_id: number;
  leave_date: string;      // YYYY-MM-DD
  created_at: string;
  updated_at: string;
};

type UserRow = {
  id: number;
  name: string;
  is_authorized: boolean;
  paid_holidays_num: number;
};

type CalendarRow = {
  the_date: string;     // 'YYYY-MM-DD'
  locale_type: 1 | 2;   // 1=日本人, 2=外国人
  status: 0 | 1 | 2;    // 0=出勤,1=社内休日,2=法定休日
  note?: string | null;
};

type WorkReportRow = {
  id: number;
  user_id: number;
  work_date: string; // 'YYYY-MM-DD'
};

// ===== 追加: サマリーJSONの型 =====
type PaidLeaveSummaryJSON = {
  success: boolean;
  ym: string;
  fiscal_year: { from: string; to: string };
  include_admin: boolean;
  month: { leave_date: string; user_id: number; name: string }[];
  remain: { id: number; name: string; granted: number; used: number; remain: number }[];
};

// /auth/user.php を呼ぶ
const fetchMe = async (): Promise<MeResponse> => {
  const url = `${BASE_ADDR}/auth/user.php?_t=${Date.now()}`;
  const { data } = await axios.get(url, { withCredentials: true });

  const u = data?.user ?? data?.data ?? data;
  const isAdmin = typeof u?.is_authorized === 'boolean'
    ? !!u.is_authorized
    : String(u?.role || '').toLowerCase() === 'admin';

  return {
    id: Number(u.id),
    name: String(u.name ?? ''),
    is_authorized: isAdmin,
    paid_holidays_num: Number(u.paid_holidays_num ?? 0),
    nationality_id: (Number(u.nationality_id) === 2 ? 2 : 1) as 1 | 2,
  };
};

const api = {
  selectLeaves: async (params: Record<string, any>) => {
    const url = `${BASE_ADDR}/t_paid_leaves/select.php`;
    const { data } = await axios.get(url, { params, withCredentials: true });
    return data as { success: boolean; total: number; count: number; data: PaidLeave[] };
  },
  insertLeave: async (payload: { user_id: number; leave_date: string; }) => {
    const url = `${BASE_ADDR}/t_paid_leaves/insert.php`;
    const { data } = await axios.post(url, payload, { withCredentials: true });
    return data as { success: boolean; data: PaidLeave; message?: string };
  },
  deleteLeaveById: async (id: number) => {
    const url = `${BASE_ADDR}/t_paid_leaves/delete.php`;
    const { data } = await axios.post(url, { id }, { withCredentials: true });
    return data as { success: boolean; deleted: number };
  },
  // 管理者用：ユーザー一覧（PaidLeave用は ?mode=basic を利用）
  selectUsers: async (): Promise<UserRow[]> => {
    const url = `${BASE_ADDR}/m_users/select.php?mode=basic`;
    const { data } = await axios.get(url, { withCredentials: true });
    const rows: any[] = data?.data || data;
    return rows.map((r: any) => ({
      id: Number(r.id),
      name: String(r.name),
      is_authorized: !!r.is_authorized,
      paid_holidays_num: Number(r.paid_holidays_num ?? 0),
    }));
  },

  selectHolidays: async (from: string, to: string, localeType: 1 | 2 = 1): Promise<Set<string>> => {
    const url = `${BASE_ADDR}/t_calendars/select.php`;
    const params = { date_from: from, date_to: to, locale_type: localeType, holiday_only: 1 };
    const { data } = await axios.get(url, { params, withCredentials: true });
    // 返却は配列 [{ the_date, status, ... }]
    const set = new Set<string>();
    (data as CalendarRow[]).forEach(r => set.add(r.the_date));
    return set;
  },
  // ===== 追加: 有給サマリー(JSON) =====
  paidLeaveSummary: async (ym: string, includeAdmin = false): Promise<PaidLeaveSummaryJSON> => {
    const url = `${BASE_ADDR}/t_paid_leaves/export_xls.php`;
    const params = { ym, include_admin: includeAdmin ? 1 : 0, format: 'json', _t: Date.now() };
    const { data } = await axios.get(url, { params, withCredentials: true });
    return data as PaidLeaveSummaryJSON;
  },

    workReportsOnDate: async (userId: number, date: string): Promise<WorkReportRow[]> => {
    const url = `${BASE_ADDR}/t_work_reports/select.php`;
    const params = { user_id: userId, date_from: date, date_to: date, limit: 100 };
    const { data } = await axios.get(url, { params, withCredentials: true });
    // 返却の形を data.data or data に両対応
    const rows: any[] = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
    return rows.map((r) => ({
      id: Number(r.id),
      user_id: Number(r.user_id),
      work_date: String(r.work_date),
    }));
  },

  // 日報をID指定で削除
  deleteWorkReportById: async (id: number): Promise<boolean> => {
    const url = `${BASE_ADDR}/t_work_reports/delete.php`;
    const { data } = await axios.post(url, { id }, { withCredentials: true });
    // { success: boolean, deleted?: number } のどちらでもOK判定
    return !!(data?.success ?? (data?.deleted > 0));
  },

};

const fmt = (d?: string | Dayjs | null) => d ? dayjs(d).format('YYYY-MM-DD') : '';


/**
 * 休日ハイライト付きの Day コンポーネント
 */
function HolidayDay(props: PickersDayProps<Dayjs> & { holidays: Set<string> }) {
  const { day, outsideCurrentMonth, holidays, ...other } = props;
  const isHoliday = holidays.has(day.format('YYYY-MM-DD'));

  return (
    <Tooltip title={isHoliday ? '休日' : ''}>
      <span>
        <PickersDay
          {...other}
          day={day}
          outsideCurrentMonth={outsideCurrentMonth}
          sx={isHoliday ? { bgcolor: 'rgba(244,67,54,0.12)' } : undefined}
        />
      </span>
    </Tooltip>
  );
}

/**
 * 一般ユーザー向けビュー
 */
const UserSection: React.FC<{ me: MeResponse }> = ({ me }) => {
  const [loading, setLoading] = useState(true);
  const [leaves, setLeaves] = useState<PaidLeave[]>([]);
  const [selectedDate, setSelectedDate] = useState<Dayjs | null>(dayjs());
  const [holidays, setHolidays] = useState<Set<string>>(new Set());

  // ===== ダイアログ類 =====
  // 確認（OK/キャンセル）
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [confirmInfo, setConfirmInfo] = useState<{ date: string; rows: WorkReportRow[] }>({ date: '', rows: [] });
  const [confirmBusy, setConfirmBusy] = useState(false);
  // 結果通知（OKのみ）
  const [notice, setNotice] = useState<{ open: boolean; title?: string; msg: string }>({
    open: false,
    title: '通知',
    msg: '',
  });

  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('sm'));

  const today = (TEST_TODAY || dayjs()).startOf('day');

  // 表示中の月レンジ
  const monthRange = useMemo(() => {
    const base = selectedDate || dayjs();
    return {
      from: base.startOf('month').format('YYYY-MM-DD'),
      to: base.endOf('month').format('YYYY-MM-DD'),
    };
  }, [selectedDate]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      // 今日基準で年度範囲(4/1〜翌3/31)
      const now = (TEST_TODAY || dayjs());
      const y = now.year();
      const fyStart = now.month() < 3 ? dayjs(`${y - 1}-04-01`) : dayjs(`${y}-04-01`);
      const fyEnd = fyStart.add(1, 'year').subtract(1, 'day');

      const res = await api.selectLeaves({
        user_id: me.id,
        date_from: fyStart.format('YYYY-MM-DD'),
        date_to: fyEnd.format('YYYY-MM-DD'),
        limit: 500,
        order: 'leave_date_desc',
      });
      setLeaves(res.data || []);
    } finally {
      setLoading(false);
    }
  }, [me.id]);

  useEffect(() => { load(); }, [load]);

  // 休日（表示月）
  const loadHolidays = useCallback(async () => {
    try {
      const localeType: 1 | 2 = (me.nationality_id === 2 ? 2 : 1);
      const set = await api.selectHolidays(monthRange.from, monthRange.to, localeType);
      setHolidays(set);
    } catch { /* noop */ }
  }, [monthRange.from, monthRange.to, me.nationality_id]);

  useEffect(() => { loadHolidays(); }, [loadHolidays]);

  const usedCount = leaves.length;
  const granted = Number(me.paid_holidays_num ?? 0);
  const remaining = Math.max(0, granted - usedCount);
  // ===== 確認「はい」→ 日報削除 → 有給登録 =====
  const handleConfirmProceed = useCallback(async () => {
    const { date, rows } = confirmInfo;
    try {
      setConfirmBusy(true);

      for (const r of rows) {
        const ok = await api.deleteWorkReportById(r.id);
        if (!ok) {
          setConfirmBusy(false);
          setConfirmOpen(false);
          setNotice({ open: true, title: 'エラー', msg: '日報の削除に失敗しました。処理を中断します。' });
          return;
        }
      }

      await api.insertLeave({ user_id: me.id, leave_date: date });

      setConfirmBusy(false);
      setConfirmOpen(false);
      //setNotice({ open: true, title: '完了', msg: '有給を登録しました。' });
      await load();
    } catch {
      setConfirmBusy(false);
      setConfirmOpen(false);
      setNotice({ open: true, title: 'エラー', msg: '登録に失敗しました。' });
    }
  }, [confirmInfo, me.id, load]);

  const onInsert = async () => {
    if (!selectedDate) return;
    const dateStr = fmt(selectedDate);

    // ▼ バリデーションはすべて通知ダイアログで
    if (holidays.has(dateStr)) {
      setNotice({ open: true, title: '注意', msg: '選択した日は休日として登録されています。申請できません。' });
      return;
    }
    if (leaves.some(x => x.leave_date === dateStr)) {
      setNotice({ open: true, title: '注意', msg: '同じ日が既に登録されています。' });
      return;
    }
    if (remaining <= 0) {
      setNotice({ open: true, title: 'エラー', msg: '残有給が0日のため登録できません。' });
      return;
    }

    try {
      // ① 該当日の日報を確認
      const existReports = await api.workReportsOnDate(me.id, dateStr);

      if (existReports.length > 0) {
        // window.confirm は使わず、MUIの確認ダイアログで
        setConfirmInfo({ date: dateStr, rows: existReports });
        setConfirmOpen(true);
        return;
      }

      // ② そのまま登録
      await api.insertLeave({ user_id: me.id, leave_date: dateStr });
      //setNotice({ open: true, title: '完了', msg: '有給を登録しました。' });
      await load();
    } catch (e) {
      const err = e as AxiosError<any>;
      if (err.response?.status === 409) {
        setNotice({ open: true, title: '注意', msg: err.response?.data?.message || '同一日が既に登録されています。' });
      } else {
        setNotice({ open: true, title: 'エラー', msg: '登録に失敗しました。' });
      }
    }
  };

  const canDelete = (leave: PaidLeave) => dayjs(leave.leave_date).isSameOrAfter(today);

  const onDelete = async (id: number) => {
    try {
      await api.deleteLeaveById(id);
      //setNotice({ open: true, title: '完了', msg: '削除しました。' });
      await load();
    } catch {
      setNotice({ open: true, title: 'エラー', msg: '削除に失敗しました。' });
    }
  };

  return (
    <Stack spacing={2}>
      {/* 上段（概要＋日付選択） */}
      <Paper>
        <Stack
          direction={{ xs: 'column', sm: 'row' }}
          alignItems={{ xs: 'stretch', sm: 'center' }}
          spacing={2}
          flexWrap="wrap"
          useFlexGap
        >
          <Typography variant="h6">有給（一般）</Typography>
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
            <Chip label={`残日数: ${remaining} 日`} color={remaining > 0 ? 'primary' : 'default'} />
            <Chip label={`使用済: ${usedCount} 日`} />
            <Chip label={`付与: ${granted} 日`} />
          </Stack>
        </Stack>
        <Divider />
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 6, md: 4 }}>
            <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale="ja">
              {isMobile ? (
                <MobileDatePicker
                  label="有給取得日"
                  value={selectedDate}
                  onChange={(v) => { setSelectedDate(v); }}
                  format="YYYY-MM-DD"
                  slotProps={{
                    toolbar: { toolbarFormat: 'M月 D日' },
                    textField: {
                      fullWidth: true,
                      size: 'small',
                    },
                  }}
                  slots={{ day: (p) => <HolidayDay {...p} holidays={holidays} /> }}
                />
              ) : (
                <DatePicker
                  label="有給取得日"
                  value={selectedDate}
                  onChange={(v) => { setSelectedDate(v); }}
                  format="YYYY-MM-DD"
                  slotProps={{
                    toolbar: { toolbarFormat: 'M月 D日' },
                    textField: {
                      fullWidth: true,
                      size: 'small',
                    },
                  }}
                  slots={{ day: (p) => <HolidayDay {...p} holidays={holidays} /> }}
                />
              )}
            </LocalizationProvider>
          </Grid>

          <Grid size={{ xs: 12, sm: 6, md: 4 }} display="flex" alignItems="center">
            <Button variant="contained" onClick={onInsert} disabled={!selectedDate || remaining <= 0}>
              有給申請（登録）
            </Button>
          </Grid>
        </Grid>
      </Paper>

      {/* 登録済み一覧 */}
      <Paper>
        <Typography variant="subtitle1" gutterBottom>登録済みの有給一覧</Typography>
        {loading ? (
          <Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>
        ) : (
          <TableContainer
            /* 念のため iOS での慣性スクロールを有効化 */
            sx={{ WebkitOverflowScrolling: 'touch' }}
          >
            <Table size="small" stickyHeader>
              {/* ▼▼▼ ここを追加（ヘッダーを常に前面＆不透明に） ▼▼▼ */}
              <TableHead
                sx={{
                  '& .MuiTableCell-head': {
                    position: 'sticky',
                    top: 0,
                    zIndex: 5, // 本文セルより前面に
                    bgcolor: (theme) => theme.palette.background.paper, // 透過させない
                  },
                }}
              >
                <TableRow>
                  <TableCell>取得日</TableCell>
                  <TableCell>登録日時</TableCell>
                  <TableCell align="center">操作</TableCell>
                </TableRow>
              </TableHead>
              {/* ▲▲▲ 追加ここまで ▲▲▲ */}
              <TableBody>
                {leaves.map(row => {
                  const future = canDelete(row);
                  return (
                    <TableRow key={row.id} sx={!future ? { opacity: 0.5 } : undefined}>
                      <TableCell>{row.leave_date}</TableCell>
                      <TableCell>{row.created_at}</TableCell>
                      <TableCell align="center">
                        {future ? (
                          <Tooltip title="削除">
                            <IconButton onClick={() => onDelete(row.id)} size="small">
                              <DeleteIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                        ) : (
                          <Chip size="small" label="過去日" />
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
                {leaves.length === 0 && (
                  <TableRow><TableCell colSpan={4} align="center">データがありません</TableCell></TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>
        )}
      </Paper>


      {/* ====== 結果通知（OKのみ） ====== */}
      <Dialog
        open={notice.open}
        onClose={() => setNotice(s => ({ ...s, open: false }))}
        aria-labelledby="notice-title"
      >
        <DialogTitle id="notice-title">{notice.title ?? '通知'}</DialogTitle>
        <DialogContent dividers>
          <Typography>{notice.msg}</Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setNotice(s => ({ ...s, open: false }))} autoFocus>
            OK
          </Button>
        </DialogActions>
      </Dialog>

      {/* ====== 確認（OK/キャンセル） ====== */}
      <Dialog open={confirmOpen} onClose={() => (confirmBusy ? null : setConfirmOpen(false))}>
        <DialogTitle>確認</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" sx={{ whiteSpace: 'pre-line' }}>
            {`選択日の勤怠データが ${confirmInfo.rows.length} 件あります。\n削除して有給を登録してもよろしいですか？`}
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmOpen(false)} disabled={confirmBusy} variant="text">
            キャンセル
          </Button>
          <Button onClick={handleConfirmProceed} disabled={confirmBusy} variant="contained" color="primary">
            {confirmBusy ? '処理中…' : 'OK'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
};

/**
 * 管理者向けビュー
 * ─ 「選択月の有給取得者一覧」と「各ユーザーの年度残日数」を
 *    backend/t_paid_leaves/export_xls.php?format=json から取得して表示
 */
const AdminSection: React.FC = () => {
  const [selectedDate, setSelectedDate] = useState<Dayjs>((TEST_TODAY || dayjs()));
  const [loading, setLoading] = useState(true);
  const [nameFilter, setNameFilter] = useState('');

  const [monthRows, setMonthRows] = useState<PaidLeaveSummaryJSON['month']>([]);
  const [remainRows, setRemainRows] = useState<PaidLeaveSummaryJSON['remain']>([]);
  const [fyInfo, setFyInfo] = useState<{ from: string; to: string } | null>(null);

  const ym = selectedDate.format('YYYY-MM');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const json = await api.paidLeaveSummary(ym, false);
      setMonthRows(json.month || []);
      setRemainRows((json.remain || []).sort((a, b) => a.id - b.id));
      setFyInfo(json.fiscal_year);
    } finally {
      setLoading(false);
    }
  }, [ym]);

  useEffect(() => { load(); }, [load]);

  const filteredRemain = useMemo(() => {
    const key = nameFilter.trim();
    if (!key) return remainRows;
    return remainRows.filter(r => r.name.includes(key));
  }, [remainRows, nameFilter]);

  return (
    <Stack spacing={2}>
      <Paper>
        <Stack
          direction={{ xs: 'column', sm: 'row' }}
          alignItems={{ xs: 'stretch', sm: 'center' }}
          spacing={2}
          flexWrap="wrap"
          useFlexGap
        >
          <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale="ja">
            <DatePicker
              label="対象月"
              value={selectedDate}
              onChange={(v) => { if (v) setSelectedDate(v.startOf('month')); }}
              views={['year', 'month']}
              openTo="month"
              format="YYYY-MM"
              slotProps={{ textField: { size: 'small', fullWidth: true } }}
            />
          </LocalizationProvider>
          <Button variant="outlined" onClick={load} sx={{ width: { xs: '100%', sm: 'auto' } }}>
            再読込
          </Button>
          <Box flex={1} />
          <Button
            size="small"
            variant="contained"
            startIcon={<FileDownloadIcon />}
            onClick={() => window.open(`${BASE_ADDR}/t_paid_leaves/export_xls.php?ym=${ym}`, '_blank', 'noopener,noreferrer')}
            sx={{ width: { xs: '100%', sm: 'auto' } }}
          >
            エクセル出力
          </Button>
        </Stack>
      </Paper>

      <Grid container spacing={2}>
        {/* 左：当月の有給取得者一覧 */}
        <Grid size={{ xs: 12, md: 6 }}>
          <Paper>
            <Typography variant="subtitle1" gutterBottom>
              当月の有給取得者一覧（{ym}）
            </Typography>
            {loading ? (
              <Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>
            ) : (
              // 管理画面のテーブル高さは従来通り（個別指定を維持）
              <TableContainer sx={{ maxHeight: { xs: 300, md: 420 }, overflow: 'auto', WebkitOverflowScrolling: 'touch' }}>
                <Table size="small" stickyHeader>
                  <TableHead>
                    <TableRow>
                      <TableCell sx={{ whiteSpace: 'nowrap' }}>取得日</TableCell>
                      <TableCell>氏名</TableCell>
                      <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>ユーザーID</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {monthRows.map((x, i) => (
                      <TableRow key={`${x.user_id}-${x.leave_date}-${i}`}>
                        <TableCell>{x.leave_date}</TableCell>
                        <TableCell sx={{ minWidth: 140 }}>{x.name}</TableCell>
                        <TableCell align="right">{x.user_id}</TableCell>
                      </TableRow>
                    ))}
                    {monthRows.length === 0 && (
                      <TableRow>
                        <TableCell colSpan={3} align="center">データがありません</TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
          </Paper>
        </Grid>

        {/* 右：社員ごとの年度残日数 */}
        <Grid size={{ xs: 12, md: 6 }}>
        <Paper>
          <Stack direction={{ xs: 'column', sm: 'row' }} alignItems="center" justifyContent="space-between" mb={1} gap={1}>
            <Typography variant="subtitle1">社員一覧と残有給（年度単位）</Typography>
          </Stack>
          {loading ? (
            <Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>
          ) : (
            <TableContainer sx={{ maxHeight: { xs: 300, md: 420 }, overflow: 'auto', WebkitOverflowScrolling: 'touch' }}>
              <Table size="small" stickyHeader>
                <TableHead>
                  <TableRow>
                    <TableCell>ID</TableCell>
                    <TableCell>氏名</TableCell>
                    <TableCell align="right">付与</TableCell>
                    <TableCell align="right">使用済</TableCell>
                    <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>残（日）</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {remainRows
                    .filter(r => !nameFilter || r.name.includes(nameFilter))
                    .map(r => (
                      <TableRow key={r.id}>
                        <TableCell>{r.id}</TableCell>
                        <TableCell sx={{ minWidth: 140 }}>{r.name}</TableCell>
                        <TableCell align="right">{r.granted}</TableCell>
                        <TableCell align="right">{r.used}</TableCell>
                        {/* ▼ 残（日）：Chip→通常数字表示へ */}
                        <TableCell align="right">{r.remain}</TableCell>
                      </TableRow>
                    ))}
                  {remainRows.length === 0 && (
                    <TableRow><TableCell colSpan={5} align="center">該当なし</TableCell></TableRow>
                  )}
                </TableBody>
              </Table>
            </TableContainer>
          )}
        </Paper>
      </Grid>
      </Grid>
    </Stack>
  );
};

const PaidLeavePage: React.FC = () => {
  const [me, setMe] = useState<MeResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState<string>('');

  useEffect(() => {
    (async () => {
      try {
        const m = await fetchMe();
        setMe(m);
      } catch (e) {
        setErrorMsg('ログイン情報の取得に失敗しました');
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  if (loading) {
    return (
      <Box p={3} display="flex" justifyContent="center"><CircularProgress /></Box>
    );
  }
  if (errorMsg) {
    return (
      <Box p={3}><Alert severity="error">{errorMsg}</Alert></Box>
    );
  }
  if (!me) return null;

  return (
    <Box p={2} className="paidleave">
      {me.is_authorized ? <AdminSection /> : <UserSection me={me} />}
    </Box>
  );
};

export default PaidLeavePage;
