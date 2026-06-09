import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';

/**
 * 現場・車両・立替金のマスタを取得して ID→名称（number/name）に変換して返すフック
 * - 取得失敗時は空マップ（{}）
 * - API は withCredentials 付き
 *
 * @param {string} apiBase e.g. /Report/backend
 * @returns { siteMap, vehicleMap, paymentMap, loading, refresh }
 */
export default function useMasterMaps(apiBase) {
  const [siteMap, setSiteMap] = useState({});
  const [vehicleMap, setVehicleMap] = useState({});
  const [paymentMap, setPaymentMap] = useState({});
  const [loading, setLoading] = useState(false);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const [sRes, vRes, pRes] = await Promise.all([
        axios.get(`${apiBase}/m_on_sites/select.php`, { withCredentials: true }),
        axios.get(`${apiBase}/m_vehicles/select.php`, { withCredentials: true }),
        axios.get(`${apiBase}/m_payments/select.php`, { withCredentials: true }),
      ]);

      const s = Array.isArray(sRes?.data) ? sRes.data : [];
      const v = Array.isArray(vRes?.data) ? vRes.data : [];
      const p = Array.isArray(pRes?.data) ? pRes.data : [];

      // 現場: id → name
      const sMap = {};
      for (const x of s) {
        if (x?.id != null) sMap[Number(x.id)] = String(x.name ?? '');
      }

      // 車両: id → number（nuber 誤記対応・code フォールバック）
      const vMap = {};
      for (const x of v) {
        if (x?.id != null) vMap[Number(x.id)] = String(x.number ?? x.nuber ?? x.code ?? '');
      }

      // 立替金: id → name
      const pMap = {};
      for (const x of p) {
        if (x?.id != null) pMap[Number(x.id)] = String(x.name ?? '');
      }

      setSiteMap(sMap);
      setVehicleMap(vMap);
      setPaymentMap(pMap);
    } catch {
      setSiteMap({});
      setVehicleMap({});
      setPaymentMap({});
    } finally {
      setLoading(false);
    }
  }, [apiBase]);

  useEffect(() => { refresh(); }, [refresh]);

  return { siteMap, vehicleMap, paymentMap, loading, refresh };
}
