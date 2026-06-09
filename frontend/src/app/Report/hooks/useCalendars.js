import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';

/**
 * 休日（t_calendars）と有給（t_paid_leaves）をまとめて管理するフック
 *
 * 使用例:
 * const { holidayMap, paidLeaveSet, loading } = useCalendars({
 *   apiBase: API_BASE,
 *   monthValue,            // dayjs インスタンス
 *   selectedUserId,        // 数値
 *   nationalityId,         // 1=日本人, その他=外国人
 * });
 *
 * 返却:
 * - holidayMap: { 'YYYY-MM-DD': 0|1|2 }  // 0=出勤, 1=社内休日, 2=法定休日
 * - paidLeaveSet: Set<'YYYY-MM-DD'>
 * - loading: boolean
 */
export default function useCalendars({ apiBase, monthValue, selectedUserId, nationalityId }) {
  const [holidayMap, setHolidayMap] = useState({});
  const [paidLeaveSet, setPaidLeaveSet] = useState(new Set());
  const [loading, setLoading] = useState(false);

  // 休日取得
  const fetchHolidays = useCallback(async () => {
    if (!monthValue) return {};
    const from = monthValue.startOf('month').format('YYYY-MM-DD');
    const to   = monthValue.endOf('month').format('YYYY-MM-DD');
    const locale_type = Number(nationalityId) === 1 ? 1 : 2;

    try {
      const url = `${apiBase}/t_calendars/select.php?date_from=${from}&date_to=${to}&locale_type=${locale_type}`;
      const r = await axios.get(url, { withCredentials: true });
      const arr = Array.isArray(r?.data) ? r.data : [];

      const map = {};
      arr.forEach(row => {
        const d = row?.the_date;
        const s = Number(row?.status ?? 0);
        if (d) map[d] = s; // 0=出勤,1=社内,2=法定
      });
      return map;
    } catch {
      return {};
    }
  }, [apiBase, monthValue, nationalityId]);

  // 有給取得
  const fetchPaidLeaves = useCallback(async () => {
    if (!selectedUserId || !monthValue) return new Set();
    const from = monthValue.startOf('month').format('YYYY-MM-DD');
    const to   = monthValue.endOf('month').format('YYYY-MM-DD');

    try {
      const url = `${apiBase}/t_paid_leaves/select.php?user_id=${selectedUserId}&date_from=${from}&date_to=${to}`;
      const r = await axios.get(url, { withCredentials: true });
      const arr = Array.isArray(r?.data?.data) ? r.data.data : (Array.isArray(r?.data) ? r.data : []);

      const set = new Set();
      arr.forEach(row => {
        const d = String(row?.leave_date ?? row?.date ?? '').slice(0, 10);
        if (d) set.add(d);
      });
      return set;
    } catch {
      return new Set();
    }
  }, [apiBase, monthValue, selectedUserId]);

  // 同期実行
  useEffect(() => {
    let alive = true;
    (async () => {
      setLoading(true);
      try {
        const [hol, pl] = await Promise.all([fetchHolidays(), fetchPaidLeaves()]);
        if (!alive) return;
        setHolidayMap(hol);
        setPaidLeaveSet(pl);
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => { alive = false; };
  }, [fetchHolidays, fetchPaidLeaves]);

  return { holidayMap, paidLeaveSet, loading };
}
