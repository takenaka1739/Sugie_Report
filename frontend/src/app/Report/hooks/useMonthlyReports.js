import { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import dayjs from 'dayjs';
import 'dayjs/locale/ja';

const WEEK_JA = ['日', '月', '火', '水', '木', '金', '土'];

/**
 * 月内の 1日〜末日のベース行を生成し、t_work_reports の結果をマージして返すフック
 *
 * @param {object} params
 * @param {string} params.apiBase         - 例: '/Report/backend'
 * @param {number} params.userId          - 対象ユーザーID
 * @param {dayjs.Dayjs} params.monthValue - 表示月（Dayjs）
 * @param {Object} params.siteMap         - { [id]: name }
 * @param {Object} params.vehicleMap      - { [id]: number }
 * @param {Object} params.paymentMap      - { [id]: name }
 *
 * @returns { rows, loading, error, refresh }
 */
export default function useMonthlyReports({
  apiBase,
  userId,
  monthValue,
  siteMap = {},
  vehicleMap = {},
  paymentMap = {},
}) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState(null);

  // 指定月の 1..末日 ベースを生成
  const baseRows = useMemo(() => {
    if (!monthValue || !dayjs.isDayjs(monthValue)) return [];
    const y = monthValue.year();
    const m = monthValue.month();
    const days = monthValue.daysInMonth();
    return Array.from({ length: days }, (_, i) => {
      const d = i + 1;
      const dt = dayjs().year(y).month(m).date(d);
      return {
        id: null,
        date: d,
        day: WEEK_JA[dt.day()],
        work_date: dt.format('YYYY-MM-DD'),

        // 表示列（空で初期化）
        start: '',        // 出社
        leave: '',        // 退社
        early: '',        // 早出（表示のみ：自動計算はダイアログ側）
        overtime: '',     // 残業（表示のみ）
        midnight: '',     // 深夜（表示のみ）
        site: '',         // 現場名（ID->name）
        work: '',         // 作業
        alcohol: '',      // 'OK' or ''
        condition: '',    // 'OK' or ''
        vehicle: '',      // 車両 number
        reimburse1: '', amount1: '',
        reimburse2: '', amount2: '',
        reimburse3: '', amount3: '',
        reimburse4: '', amount4: '',
        reimburse5: '', amount5: '',

        // 内部保持（ID類）
        on_site_id: null,
        vehicle_id: null,
        payment1_id: null,
        payment2_id: null,
        payment3_id: null,
        payment4_id: null,
        payment5_id: null,

        // フラグ
        is_canceled: 0,
      };
    });
  }, [monthValue]);

  const refresh = useCallback(async () => {
    if (!apiBase || !userId || !monthValue) {
      setRows(baseRows);
      return;
    }
    setLoading(true);
    setErr(null);
    try {
      const ym = monthValue.format('YYYY-MM');
      const url = `${apiBase}/t_work_reports/select.php?user_id=${userId}&month=${ym}`;
      const res = await axios.get(url, { withCredentials: true });
      const arr = Array.isArray(res?.data?.data) ? res.data.data : [];

      const map = new Map(baseRows.map((r) => [r.work_date, { ...r }]));

      arr.forEach((r) => {
        const row = map.get(r.work_date);
        if (!row) return;

        row.id = r.id ?? row.id;

        // 時刻（HH:MM）
        row.start = r.start_time ? String(r.start_time).slice(0, 5) : '';
        row.leave = r.finish_time ? String(r.finish_time).slice(0, 5) : '';

        // OK/空白
        row.alcohol = Number(r.alcohol_checked) ? 'OK' : '';
        row.condition = Number(r.condition_checked) ? 'OK' : '';

        // IDs
        row.on_site_id = r.on_site_id ?? null;
        row.vehicle_id = r.vehicle_id ?? null;
        row.payment1_id = r.payment1_id ?? null;
        row.payment2_id = r.payment2_id ?? null;
        row.payment3_id = r.payment3_id ?? null;
        row.payment4_id = r.payment4_id ?? null;
        row.payment5_id = r.payment5_id ?? null;

        // 名称に変換（表示列）
        row.site = r.on_site_id ? String(siteMap[r.on_site_id] ?? '') : '';
        row.vehicle = r.vehicle_id ? String(vehicleMap[r.vehicle_id] ?? '') : '';
        row.reimburse1 = r.payment1_id ? String(paymentMap[r.payment1_id] ?? '') : '';
        row.reimburse2 = r.payment2_id ? String(paymentMap[r.payment2_id] ?? '') : '';
        row.reimburse3 = r.payment3_id ? String(paymentMap[r.payment3_id] ?? '') : '';
        row.reimburse4 = r.payment4_id ? String(paymentMap[r.payment4_id] ?? '') : '';
        row.reimburse5 = r.payment5_id ? String(paymentMap[r.payment5_id] ?? '') : '';

        // 金額はローカライズ（, 区切り）
        const fmt = (v) =>
          v || v === 0 ? Number(v).toLocaleString() : '';
        row.amount1 = fmt(r.amount1);
        row.amount2 = fmt(r.amount2);
        row.amount3 = fmt(r.amount3);
        row.amount4 = fmt(r.amount4);
        row.amount5 = fmt(r.amount5);

        // 作業・フラグ
        row.work = r.work ?? '';
        row.is_canceled = Number(r.is_canceled ?? 0);
      });

      setRows(Array.from(map.values()));
    } catch (e) {
      setErr(e);
      setRows(baseRows);
    } finally {
      setLoading(false);
    }
  }, [apiBase, userId, monthValue, baseRows, siteMap, vehicleMap, paymentMap]);

  // 初回＆依存の変化で再取得
  useEffect(() => {
    refresh();
  }, [refresh]);

  return { rows, loading, error: err, refresh };
}
