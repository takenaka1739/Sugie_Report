// frontend\src\app\Report\ReportPage.jsx

import React, { useEffect, useMemo, useState, useCallback, useRef, startTransition } from 'react';
import {
  Stack, Paper, Box, CircularProgress,
  Dialog, DialogTitle, DialogContent, DialogActions,
  Button, FormControl, FormControlLabel, RadioGroup, Radio,
  InputLabel, Select, MenuItem,
  Checkbox, FormGroup, Divider, Typography,
} from '@mui/material';
import Grid from '@mui/material/Grid2';
import axios from 'axios';
import dayjs from 'dayjs';
import 'dayjs/locale/ja';
import '../../sass/_report.scss';

import UserList from './components/UserList';
import EditDialog from './components/EditDialog';
import { buildShiftLabel } from './utils/workTimeCalc';
import ReportTable from './components/ReportTable';
import ReportToolbar from './components/ReportToolbar';

// 休日/有給フック
import useCalendars from './hooks/useCalendars';
//  ユーザー一覧キャッシュ
import useCachedUsers from './hooks/useCachedUsers';

const API_BASE =
  process.env.REACT_APP_API_BASE ||
  ((typeof window !== 'undefined' &&
    (window.location.hostname === 'localhost' ||
      window.location.hostname === '127.0.0.1'))
    ? 'http://localhost/Report/backend'
    : '/Report/backend');

const WEEK_JA = ['日','月','火','水','木','金','土'];

// 休暇種別
const WORK_TYPE = {
  0: '出勤',
  1: '雨天中止',
  2: '業務都合休暇',
  3: '自己都合休暇'
};

const pickDisplayName = (src) => {
  if (!src) return '';
  const direct =
    src.name ??
    src.full_name ??
    src.user_name ??
    src.username ??
    src.display_name ??
    '';
  if (direct) return String(direct);
  if (src.user) {
    const u = src.user;
    const nested =
      u.name ?? u.full_name ?? u.user_name ?? u.username ?? u.display_name ?? '';
    if (nested) return String(nested);
    if (u.last_name || u.first_name) return `${u.last_name ?? ''}${u.first_name ?? ''}`.trim();
  }
  if (Array.isArray(src) && src.length > 0) return pickDisplayName(src[0]);
  if (src.last_name || src.first_name) return `${src.last_name ?? ''}${src.first_name ?? ''}`.trim();
  return '';
};

const pickUserId = (src) => {
  if (!src) return 0;
  return Number(
    src.id ??
      src.user_id ??
      (src.user ? (src.user.id ?? src.user.user_id) : 0) ??
      (Array.isArray(src) && src[0] ? (src[0].id ?? src[0].user_id) : 0)
  );
};

const pickSecondSiteId = (src) => {
  if (!src) return null;
  const v =
    src.on_site_id2 ??
    src.on_site_id_2 ??
    src.on_site_id_second ??
    null;
  return (v === '' || v == null) ? null : Number(v);
};

// ===== 追加：有休メッセージ用ヘルパ =====
const toIntOrNull = (v) => {
  if (v == null || v === '') return null;
  const n = Number(v);
  return Number.isFinite(n) ? Math.trunc(n) : null;
};

const pickPaidLeaveGrantDays = (src) => {
  if (!src) return null;
  const candidates = [
    src.paid_holidays_num,
    src.paid_leave_grant_days,
    src.paid_leave_grant,
    src.paid_leave_total_days,
    src.paid_leave_total,
    src.paid_leave_days,
    src.annual_paid_leave_days,
    src.annual_leave_days,
    src.leave_grant_days,
    src.leave_total_days,
  ];
  for (const v of candidates) {
    const n = toIntOrNull(v);
    if (n != null) return n;
  }
  if (src.user) return pickPaidLeaveGrantDays(src.user);
  return null;
};

const defaultPaidLeaveDeadline = () => {
  const today = dayjs();
  const y = today.year();
  const thisMar31 = dayjs(`${y}-03-31`);
  if (today.isSame(thisMar31, 'day') || today.isBefore(thisMar31, 'day')) return thisMar31;
  return dayjs(`${y + 1}-03-31`);
};
const fiscalStartFromDeadline = (deadline) => dayjs(deadline).subtract(1, 'year').add(1, 'day');
const formatDeadlineJa = (d) => {
  if (!d || !dayjs(d).isValid()) return '';
  const dd = dayjs(d);
  return `${dd.month() + 1}月${dd.date()}日`;
};

const ReportPage = () => {
  const [isLoadingMe, setIsLoadingMe] = useState(true);
  const [isAdmin, setIsAdmin] = useState(false);
  const [meName, setMeName] = useState('');
  const [meShift, setMeShift] = useState(null);
  const [nationalityId, setNationalityId] = useState(1);

  const [paidLeaveNotice, setPaidLeaveNotice] = useState(null);

  const { users, loading: loadingUsers } = useCachedUsers(API_BASE, isAdmin);
  const [selectedUser, setSelectedUser] = useState(null);

  const [selectedShift, setSelectedShift] = useState(null);
  const [loadingSelectedShift, setLoadingSelectedShift] = useState(false);

  const [monthValue, setMonthValue] = useState(dayjs());
  const [rows, setRows] = useState([]);
  const [loadingRows, setLoadingRows] = useState(false);

  const [showLoading, setShowLoading] = useState(false);
  const loadingTimerRef = useRef(null);

  const [editOpen, setEditOpen] = useState(false);
  const [editTarget, setEditTarget] = useState(null);

  const [paymentMap, setPaymentMap] = useState({});
  const [siteMap, setSiteMap] = useState({});
  const [vehicleMap, setVehicleMap] = useState({});

  const [viewNationalityId, setViewNationalityId] = useState(1);

  // ===== 追加：管理者エクスポート選択ダイアログ =====
  const [exportDlgOpen, setExportDlgOpen] = useState(false);
  const [exportType, setExportType] = useState('date'); // 'date' | 'site' | 'all_users'
  const [exportSiteId, setExportSiteId] = useState('');

  //  出力条件（複合）: AND がデフォルト
  const [exportCondMode, setExportCondMode] = useState('and'); // 'and' | 'or'
  const [exportCond, setExportCond] = useState({
    overtime: false,     // 残業
    legalSun: false,     // 法定休日（日曜のみ） ※t_calendars.status=2 & 日曜
    company: false,      // 社内休日           ※t_calendars.status=1
    midnight: false,     // 深夜勤務のみ
  });

  const siteOptions = useMemo(() => {
    const arr = Object.entries(siteMap || {}).map(([id, name]) => ({
      id: String(id),
      name: String(name || ''),
    }));
    arr.sort((a, b) => Number(a.id) - Number(b.id));
    return arr;
  }, [siteMap]);

  // ===== 自分情報 =====
  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const res = await axios.get(`${API_BASE}/auth/user.php`, { withCredentials: true });
        const mine = res?.data?.user ?? res?.data ?? {};
        const name1 = pickDisplayName(mine);
        const id1 = pickUserId(mine);

        const admin =
          mine.is_admin === 1 ||
          mine.is_admin === true ||
          (mine.user && (mine.user.is_admin === 1 || mine.user.is_admin === true)) ||
          String(mine.role || mine.user?.role || '').toLowerCase() === 'admin' ||
          mine.is_authorized === 1 || mine.is_authorized === true;

        let finalName = name1;
        let shiftId = Number(mine?.shift_id ?? mine?.user?.shift_id ?? 0) || 0;
        let natId = Number(mine?.nationality_id ?? mine?.user?.nationality_id ?? 1) || 1;

        let userRow = null;
        if (id1 > 0) {
          try {
            const r2 = await axios.get(`${API_BASE}/m_users/select.php?id=${id1}`, { withCredentials: true });
            userRow = Array.isArray(r2.data) ? r2.data[0] : null;
            if (!finalName) finalName = pickDisplayName(userRow || {});
            if (!shiftId) shiftId = Number(userRow?.shift_id ?? 0) || 0;
            if (!natId) natId = Number(userRow?.nationality_id ?? 1) || 1;
          } catch (_) {}
        }

        let shiftRow = null;
        if (shiftId) {
          try {
            const r3 = await axios.get(`${API_BASE}/m_shifts/select.php?id=${shiftId}`, { withCredentials: true });
            shiftRow = Array.isArray(r3.data) ? r3.data[0] : null;
          } catch (_) {}
        }

        if (alive) {
          setMeName(finalName || '(名称未設定)');
          setIsAdmin(!!admin);
          setMeShift(shiftRow);
          setNationalityId(natId);
          setViewNationalityId(natId);
          if (!admin && id1 > 0) setSelectedUser({ id: id1, name: finalName || '' });
        }

        //  有休メッセージ（一般のみ）
        if (alive && !admin && id1 > 0) {
          try {
            const deadline = defaultPaidLeaveDeadline();
            const dateFrom = fiscalStartFromDeadline(deadline).format('YYYY-MM-DD');
            const dateTo = deadline.format('YYYY-MM-DD');

            const url = `${API_BASE}/t_paid_leaves/select.php?user_id=${id1}&date_from=${dateFrom}&date_to=${dateTo}&limit=1&offset=0&order=leave_date_desc`;
            const pr = await axios.get(url, { withCredentials: true });
            const takenDays = Number(pr?.data?.total ?? 0) || 0;
            console.log('[paidLeave-check]', {
              userId: id1,
              isAdmin: admin,
              dateFrom,
              dateTo,
              takenDays: Number(pr?.data?.total ?? 0) || 0,
              userRow_paid_holidays_num: userRow?.paid_holidays_num,
              mine_paid_holidays_num: mine?.paid_holidays_num,
              grantDays: pickPaidLeaveGrantDays(userRow || mine),
            });

            const grantDays = pickPaidLeaveGrantDays(userRow || mine);
            if (grantDays == null) { setPaidLeaveNotice(null); return; }

            const remainingDays = Math.max(0, grantDays - takenDays);
            const requiredDays = 5;
            const needTakeDays = Math.max(0, requiredDays - takenDays);

            if (remainingDays > 0 && needTakeDays > 0) {
              setPaidLeaveNotice({ remainingDays, needTakeDays, deadline, takenDays, grantDays });
            } else {
              setPaidLeaveNotice(null);
            }
          } catch {
            setPaidLeaveNotice(null);
          }
        } else if (alive) {
          setPaidLeaveNotice(null);
        }
      } finally {
        if (alive) setIsLoadingMe(false);
      }
    })();
    return () => { alive = false; };
  }, []);

  // ===== 辞書ロード =====
  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const [pRes, sRes, vRes] = await Promise.all([
          axios.get(`${API_BASE}/m_payments/select.php`, { withCredentials: true }),
          axios.get(`${API_BASE}/m_on_sites/select.php`, { withCredentials: true }),
          axios.get(`${API_BASE}/m_vehicles/select.php`, { withCredentials: true }),
        ]);

        const pMap = {};
        (Array.isArray(pRes?.data) ? pRes.data : []).forEach(x => {
          if (x?.id != null) pMap[Number(x.id)] = String(x.name ?? '');
        });

        const sMap = {};
        (Array.isArray(sRes?.data) ? sRes.data : []).forEach(x => {
          if (x?.id != null) sMap[Number(x.id)] = String(x.name ?? '');
        });

        const vMap = {};
        (Array.isArray(vRes?.data) ? vRes.data : []).forEach(x => {
          if (x?.id != null) vMap[Number(x.id)] = String(x.number ?? x.nuber ?? x.code ?? '');
        });

        if (alive) {
          setPaymentMap(pMap);
          setSiteMap(sMap);
          setVehicleMap(vMap);
        }
      } catch {
        if (alive) {
          setPaymentMap({});
          setSiteMap({});
          setVehicleMap({});
        }
      }
    })();
    return () => { alive = false; };
  }, []);

  // users が入ったら初期選択補完
  useEffect(() => {
    if (!isAdmin) return;
    setSelectedUser(prev => {
      if (prev && users.some(u => String(u.id) === String(prev.id))) return prev;
      return users[0] ? users[0] : prev;
    });
  }, [isAdmin, users]);

  // 表示対象ユーザーの区分 & シフトを取得（選択変更時）
  useEffect(() => {
    let alive = true;
    (async () => {
      if (!selectedUser?.id) {
        setViewNationalityId(nationalityId);
        setSelectedShift(null);
        return;
      }
      setLoadingSelectedShift(true);
      try {
        const uRes = await axios.get(`${API_BASE}/m_users/select.php?id=${selectedUser.id}`, { withCredentials: true });
        const u = Array.isArray(uRes.data) ? uRes.data[0] : null;

        if (u && !selectedUser.name) {
          setSelectedUser(prev => (prev ? { ...prev, name: pickDisplayName(u) } : prev));
        }

        const nat = Number(u?.nationality_id ?? u?.locale_type ?? 0) || 1;
        if (alive) setViewNationalityId(nat);

        const shiftId = Number(u?.shift_id ?? 0) || 0;
        if (!shiftId) { if (alive) setSelectedShift(null); return; }

        const sRes = await axios.get(`${API_BASE}/m_shifts/select.php?id=${shiftId}`, { withCredentials: true });
        const shiftRow = Array.isArray(sRes.data) ? sRes.data[0] : null;

        if (alive) setSelectedShift(shiftRow);
      } catch (_) {
        if (alive) {
          setSelectedShift(null);
          setViewNationalityId(nationalityId);
        }
      } finally {
        if (alive) setLoadingSelectedShift(false);
      }
    })();
    return () => { alive = false; };
  }, [selectedUser, nationalityId]);

  // 休日・有給（選択ユーザー基準）
  const { holidayMap, paidLeaveSet } = useCalendars({
    apiBase: API_BASE,
    monthValue,
    selectedUserId: selectedUser?.id,
    nationalityId: viewNationalityId,
  });

  // ===== レポート取得 =====
  const reportAbortRef = useRef(null);

  const fetchReports = useCallback(async () => {
    if (!selectedUser?.id || !monthValue) return;

    if (loadingTimerRef.current) clearTimeout(loadingTimerRef.current);
    loadingTimerRef.current = setTimeout(() => setShowLoading(true), 200);
    setLoadingRows(true);

    if (reportAbortRef.current) reportAbortRef.current.abort();
    reportAbortRef.current = new AbortController();

    try {
      const ym = monthValue.format('YYYY-MM');
      const url = `${API_BASE}/t_work_reports/select.php?user_id=${selectedUser.id}&month=${ym}`;
      const res = await axios.get(url, { withCredentials: true, signal: reportAbortRef.current.signal });
      const arr = Array.isArray(res?.data?.data) ? res.data.data : [];

      const y = monthValue.year(), m = monthValue.month(), days = monthValue.daysInMonth();
      const base = Array.from({ length: days }, (_, i) => {
        const d = i + 1;
        const dt = dayjs().year(y).month(m).date(d);
        const work_date = dt.format('YYYY-MM-DD');

        const hol = Number(holidayMap[work_date] ?? 0);
        const isPaidLeave = paidLeaveSet.has(work_date);

        return {
          id: null,
          date: d,
          day: WEEK_JA[dt.day()],
          work_date,
          start: '', leave: '',
          start2: '', leave2: '',
          early: '', overtime: '', midnight: '',
          site: '', work: '',
          work2: '',
          alcohol: '', condition: '',
          vehicle: '',
          reimburse1: '', amount1: '',
          reimburse2: '', amount2: '',
          reimburse3: '', amount3: '',
          reimburse4: '', amount4: '',
          reimburse5: '', amount5: '',

          on_site_id: null,
          // 第2現場
          on_site_id2: null,

          // 追加：夜勤フラグ
          is_night_shift: 0,

          vehicle_id: null,
          payment1_id: null, payment2_id: null, payment3_id: null, payment4_id: null, payment5_id: null,
          is_canceled: 0,

          holiday_status: hol,
          is_paid_leave: isPaidLeave,
        };
      });

      const map = new Map(base.map(r => [r.work_date, r]));
      arr.forEach(r => {
        const baseRow = map.get(r.work_date);
        if (!baseRow) return;

        baseRow.id = r.id;
        baseRow.start = r.start_time ? String(r.start_time).slice(0,5) : '';
        baseRow.leave = r.finish_time ? String(r.finish_time).slice(0,5) : '';
        baseRow.alcohol = Number(r.alcohol_checked) ? 'OK' : '';
        baseRow.condition = Number(r.condition_checked) ? 'OK' : '';
        baseRow.start2 = r.start_time2 ? String(r.start_time2).slice(0, 5) : '';
        baseRow.leave2 = r.finish_time2 ? String(r.finish_time2).slice(0, 5) : '';
        baseRow.work2  = r.work2 ?? '';

        baseRow.on_site_id  = r.on_site_id ?? null;
        baseRow.on_site_id2 = pickSecondSiteId(r);

        baseRow.is_night_shift = Number(r.is_night_shift ?? 0) ? 1 : 0;

        baseRow.vehicle_id  = r.vehicle_id ?? null;
        baseRow.payment1_id = r.payment1_id ?? null; baseRow.payment2_id = r.payment2_id ?? null;
        baseRow.payment3_id = r.payment3_id ?? null; baseRow.payment4_id = r.payment4_id ?? null; baseRow.payment5_id = r.payment5_id ?? null;

        // ===== 現場名（2段表示用に改行文字を入れる）=====
        const site1 = baseRow.on_site_id ? (siteMap[baseRow.on_site_id] ?? '') : '';
        const site2 = baseRow.on_site_id2 ? (siteMap[baseRow.on_site_id2] ?? '') : '';
        baseRow.site = (site1 && site2) ? `${site1}\n${site2}` : (site1 || site2 || '');

        baseRow.vehicle  = baseRow.vehicle_id ? (vehicleMap[baseRow.vehicle_id] ?? '') : '';
        baseRow.reimburse1 = baseRow.payment1_id ? (paymentMap[baseRow.payment1_id] ?? '') : '';
        baseRow.reimburse2 = baseRow.payment2_id ? (paymentMap[baseRow.payment2_id] ?? '') : '';
        baseRow.reimburse3 = baseRow.payment3_id ? (paymentMap[baseRow.payment3_id] ?? '') : '';
        baseRow.reimburse4 = baseRow.payment4_id ? (paymentMap[baseRow.payment4_id] ?? '') : '';
        baseRow.reimburse5 = baseRow.payment5_id ? (paymentMap[baseRow.payment5_id] ?? '') : '';

        baseRow.amount1  = (r.amount1 || r.amount1 === 0) ? Number(r.amount1).toLocaleString() : '';
        baseRow.amount2  = (r.amount2 || r.amount2 === 0) ? Number(r.amount2).toLocaleString() : '';
        baseRow.amount3  = (r.amount3 || r.amount3 === 0) ? Number(r.amount3).toLocaleString() : '';
        baseRow.amount4  = (r.amount4 || r.amount4 === 0) ? Number(r.amount4).toLocaleString() : '';
        baseRow.amount5  = (r.amount5 || r.amount5 === 0) ? Number(r.amount5).toLocaleString() : '';

        baseRow.work = r.work ?? '';
        baseRow.is_canceled = Number(r.is_canceled ?? 0);
      });

      startTransition(() => { setRows(Array.from(map.values())); });
    } catch (e) {
      if (axios.isCancel?.(e) || e.name === 'CanceledError' || e.name === 'AbortError') {
        // noop
      } else {
        console.error(e);
      }
    } finally {
      setLoadingRows(false);
      if (loadingTimerRef.current) {
        clearTimeout(loadingTimerRef.current);
        loadingTimerRef.current = null;
      }
      setShowLoading(false);
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedUser?.id, monthValue, holidayMap, paidLeaveSet, paymentMap, siteMap, vehicleMap, selectedShift]);

  useEffect(() => { fetchReports(); }, [fetchReports]);

  const handleEdit = (row) => { setEditTarget(row); setEditOpen(true); };

  const handleDelete = async (row) => {
    const workDate = row?.work_date;
    const targetUserId = Number(selectedUser?.id || 0);
    if (!workDate || !targetUserId) return;

    const ok = window.confirm(`${workDate} の日報を削除します。よろしいですか？`);
    if (!ok) return;

    const payload = row?.id
      ? { id: Number(row.id) }
      : { user_id: targetUserId, work_date: workDate };

    try {
      const res = await axios.post(`${API_BASE}/t_work_reports/delete.php`, payload, {
        withCredentials: true,
        headers: { 'Content-Type': 'application/json' },
      });
      if (res?.data?.success) {
        await fetchReports();
        alert('削除しました。');
      } else {
        alert('削除できませんでした。');
      }
    } catch (e) {
      console.error(e);
      alert('削除中にエラーが発生しました。');
    }
  };

  const chipLabel = useMemo(() => {
    if (isLoadingMe || loadingSelectedShift) return '読込中…';
    const name = selectedUser?.name || meName || '';
    const shiftText = buildShiftLabel(selectedShift);
    return shiftText ? `${name}｜${shiftText}` : name || '(名称未設定)';
  }, [isLoadingMe, loadingSelectedShift, selectedUser?.name, meName, selectedShift]);

  const paidLeaveMessage = useMemo(() => {
    if (isAdmin) return '';
    if (!paidLeaveNotice) return '';
    const deadlineText = formatDeadlineJa(paidLeaveNotice.deadline);
    const remaining = paidLeaveNotice.remainingDays ?? 0;
    const needTake = paidLeaveNotice.needTakeDays ?? 0;
    if (remaining <= 0 || needTake <= 0) return '';
    return `有休残日数　${remaining}日です。${deadlineText}までに、${needTake}日間取得してください。`;
  }, [isAdmin, paidLeaveNotice]);

  // ===== エクスポート =====
  const handleExportMyMonth = () => {
    if (!monthValue) return;
    const ym = monthValue.format('YYYY-MM');

    // 一般：従来通り（自分の月次）
    if (!isAdmin) {
      if (!selectedUser?.id) return;
      const url = `${API_BASE}/t_work_reports/export_month_xls.php?type=user&user_id=${selectedUser.id}&ym=${ym}`;
      window.open(url, '_blank', 'noopener,noreferrer');
      return;
    }

    // 管理者：選択ダイアログ
    setExportType('date');
    setExportSiteId(siteOptions[0]?.id ?? '');
    setExportCondMode('and');
    setExportCond({ overtime: false, legalSun: false, company: false, midnight: false });
    setExportDlgOpen(true);
  };

  const buildCondQuery = () => {
    const p = new URLSearchParams();
    p.set('cond_mode', exportCondMode || 'and');
    if (exportCond.overtime) p.set('cond_overtime', '1');
    if (exportCond.legalSun) p.set('cond_legal_sun', '1');
    if (exportCond.company)  p.set('cond_company', '1');
    if (exportCond.midnight) p.set('cond_midnight', '1');
    return p.toString();
  };

  const runAdminExport = () => {
    const ym = monthValue.format('YYYY-MM');
    const condQS = buildCondQuery();

    if (exportType === 'date') {
      const url = `${API_BASE}/t_work_reports/export_month_xls.php?type=date&ym=${ym}${condQS ? `&${condQS}` : ''}`;
      window.open(url, '_blank', 'noopener,noreferrer');
      setExportDlgOpen(false);
      return;
    }

    if (exportType === 'all_users') {
      const url = `${API_BASE}/t_work_reports/export_month_xls.php?type=all_users&ym=${ym}`;
      window.open(url, '_blank', 'noopener,noreferrer');
      setExportDlgOpen(false);
      return;
    }

    if (exportType === 'site') {
    if (!exportSiteId) { alert('現場名を選択してください'); return; }
      const url = `${API_BASE}/t_work_reports/export_month_xls.php?type=site&ym=${ym}&on_site_id=${exportSiteId}${condQS ? `&${condQS}` : ''}`;
      window.open(url, '_blank', 'noopener,noreferrer');
      setExportDlgOpen(false);
      return;
    }
  };

  const handleExportSummary = () => {
    if (!monthValue) return;
    const ym = monthValue.format('YYYY-MM');
    const url = `${API_BASE}/t_work_reports/export_summary_xls.php?ym=${ym}`;
    window.open(url, '_blank', 'noopener,noreferrer');
  };

  const handleExportAttendanceBook = () => {
    if (!monthValue) return;
    const ym = monthValue.format('YYYY-MM');
    const url = `${API_BASE}/t_work_reports/export_attendance_book_xls.php?ym=${ym}`;
    window.open(url, '_blank', 'noopener,noreferrer');
  };

  if (isLoadingMe) {
    return (
      <Box className="report-loading">
        <CircularProgress size={20} />
        <span>ユーザー情報を読込中…</span>
      </Box>
    );
  }

  // 修正：getRowMeta に shift を含める（ReportTable 側で都度計算できるようにする）
  const getRowMeta = (row) => {
    // 目立つ色に固定（「薄い」問題を潰す）
    const BG_RED  = '#e44646'; // 有給/法定/雨天：薄めの赤
    const BG_BLUE = '#3095ee'; // 社内休日：薄めの青

    // 一覧側の早出/残業/深夜の再計算に必要
    const shift = selectedShift || meShift || null;

    // 有給：赤＋「有給休暇」＋編集可（変更）
    if (row.is_paid_leave) {
      return { disabled: false, bgcolor: BG_RED, workLabel: '有給休暇', shift };
    }

    // 法定休日：赤＋「法定休日」＋編集可（変更）
    if (row.holiday_status === 2) {
      return { disabled: false, bgcolor: BG_RED, workLabel: '法定休日', shift };
    }

    // 社内休日：青＋「社内休日」＋編集可（変更）
    if (row.holiday_status === 1) {
      return { disabled: false, bgcolor: BG_BLUE, workLabel: '社内休日', shift };
    }

    // 休暇種別：雨天中止・業務都合休暇・自己都合休暇のいずれか
    // 赤文字 ＋ 休暇種別（編集 / 削除は現状維持 = 可）
    if (row.is_canceled) {
      const workLabel = WORK_TYPE[Number(row.is_canceled)] || '休暇';
      return { disabled: false, bgcolor: BG_RED, workLabel: workLabel, shift };
    }
    
    // 通常日
    return { disabled: false, bgcolor: undefined, workLabel: row.work || '', shift };
  };

  return (
    <>
      <Grid container spacing={{ xs: 0, sm: 3 }} className="report-layout">
        {isAdmin && (
          <Grid size={{ xs: 12, sm: 2 }} className="report-sidebar-grid">
            <Paper className="report-sidebar-paper">
              {loadingUsers ? (
                <Box className="report-sidebar-loading">
                  <CircularProgress size={20} />
                </Box>
              ) : (
                <UserList
                  isAdmin
                  users={users}
                  selectedUserId={selectedUser?.id}
                  onSelect={(u) => setSelectedUser(u)}
                />
              )}
            </Paper>
          </Grid>
        )}

        <Grid size={isAdmin ? 10 : 12} className="report-main-grid">
          <Paper className="report-main-paper" sx={{ position: 'relative' }}>
            <ReportToolbar
              monthValue={monthValue}
              onChangeMonth={setMonthValue}
              chipLabel={chipLabel}
              isAdmin={isAdmin}
              onExportMyMonth={handleExportMyMonth}
              onExportSummary={handleExportSummary}
              onExportAttendanceBook={handleExportAttendanceBook}
              disableExportMy={!selectedUser?.id && !isAdmin}
              loading={loadingSelectedShift}
              paidLeaveMessage={paidLeaveMessage}
            />

            <Box className="report-table-area" sx={{ position: 'relative' }}>
              {showLoading && (
                <Box
                  sx={{
                    position: 'absolute',
                    inset: 0,
                    display: 'grid',
                    placeItems: 'center',
                    background: 'rgba(255,255,255,0.35)',
                    backdropFilter: 'blur(1px)',
                    zIndex: 2,
                  }}
                >
                  <CircularProgress size={24} />
                </Box>
              )}

              <Stack spacing={2} className="report-table-stack" sx={{ opacity: showLoading ? 0.75 : 1, transition: 'opacity .18s ease' }}>
                <ReportTable
                  rows={rows}
                  loading={false}
                  isAdmin={isAdmin}
                  onEdit={handleEdit}
                  onDelete={handleDelete}
                  getRowMeta={getRowMeta}
                />
              </Stack>
            </Box>
          </Paper>
        </Grid>
      </Grid>

      <EditDialog
        open={editOpen}
        onClose={() => setEditOpen(false)}
        target={editTarget}
        apiBase={API_BASE}
        shift={selectedShift}
        workDateStr={editTarget?.work_date}
        userId={selectedUser?.id}
        onSaved={() => fetchReports()}
      />

      {/* ===== 管理者：出力選択ダイアログ ===== */}
      <Dialog open={exportDlgOpen} onClose={() => setExportDlgOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>エクセル出力（管理者）</DialogTitle>
        <DialogContent dividers>
          <FormControl component="fieldset" sx={{ width: '100%' }}>
            <RadioGroup
              value={exportType}
              onChange={(e) => setExportType(e.target.value)}
            >
              <FormControlLabel value="date" control={<Radio />} label="日付別（当月）" />
              <FormControlLabel value="site" control={<Radio />} label="現場名別（当月）" />
              <FormControlLabel value="all_users" control={<Radio />} label="全社員の月次日報" />
            </RadioGroup>

            {exportType === 'site' && (
              <Box sx={{ mt: 2 }}>
                <FormControl fullWidth size="small">
                  <InputLabel id="site-select-label">現場名</InputLabel>
                  <Select
                    labelId="site-select-label"
                    label="現場名"
                    value={exportSiteId}
                    onChange={(e) => setExportSiteId(String(e.target.value))}
                  >
                    {siteOptions.map(s => (
                      <MenuItem key={s.id} value={s.id}>{s.name}（ID:{s.id}）</MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Box>
            )}

            {exportType !== 'all_users' && (
              <>
                <Divider sx={{ my: 2 }} />

                <Typography variant="subtitle2" sx={{ mb: 1 }}>
                  出力条件（複合可）
                </Typography>

                {/* AND/OR（デフォルトAND） */}
                <FormControl component="fieldset">
                  <RadioGroup
                    row
                    value={exportCondMode}
                    onChange={(e) => setExportCondMode(e.target.value === 'or' ? 'or' : 'and')}
                  >
                    <FormControlLabel value="and" control={<Radio size="small" />} label="AND（全て満たす）" />
                    <FormControlLabel value="or"  control={<Radio size="small" />} label="OR（いずれか満たす）" />
                  </RadioGroup>
                </FormControl>

                <FormGroup sx={{ mt: 1 }}>
                  <FormControlLabel
                    control={
                      <Checkbox
                        checked={exportCond.overtime}
                        onChange={(e) => setExportCond((p) => ({ ...p, overtime: e.target.checked }))}
                      />
                    }
                    label="残業"
                  />
                  <FormControlLabel
                    control={
                      <Checkbox
                        checked={exportCond.legalSun}
                        onChange={(e) => setExportCond((p) => ({ ...p, legalSun: e.target.checked }))}
                      />
                    }
                    label="法定休日に出勤した日（日曜のみ）"
                  />
                  <FormControlLabel
                    control={
                      <Checkbox
                        checked={exportCond.company}
                        onChange={(e) => setExportCond((p) => ({ ...p, company: e.target.checked }))}
                      />
                    }
                    label="社内休日に出勤した日"
                  />
                  <FormControlLabel
                    control={
                      <Checkbox
                        checked={exportCond.midnight}
                        onChange={(e) => setExportCond((p) => ({ ...p, midnight: e.target.checked }))}
                      />
                    }
                    label="深夜勤務のみ"
                  />
                </FormGroup>
              </>
            )}
          </FormControl>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setExportDlgOpen(false)} variant="text">キャンセル</Button>
          <Button onClick={runAdminExport} variant="contained">ダウンロード</Button>
        </DialogActions>
      </Dialog>
    </>
  );
};

export default ReportPage;
