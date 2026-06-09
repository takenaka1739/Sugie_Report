import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';

/**
 * 現場・車両・立替金のマスタを取得し、ID→表示名のマップを返すフック
 * - siteMap:     { [id]: name }
 * - vehicleMap:  { [id]: number }
 * - paymentMap:  { [id]: name }
 */
export default function useMasters(apiBase) {
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

      const sMap = {};
      s.forEach(x => { if (x?.id != null) sMap[Number(x.id)] = String(x.name ?? ''); });

      const vMap = {};
      v.forEach(x => { if (x?.id != null) vMap[Number(x.id)] = String(x.number ?? x.nuber ?? x.code ?? ''); });

      const pMap = {};
      p.forEach(x => { if (x?.id != null) pMap[Number(x.id)] = String(x.name ?? ''); });

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
