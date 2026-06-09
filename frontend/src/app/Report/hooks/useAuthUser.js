import { useEffect, useState } from 'react';
import axios from 'axios';

/** name をいい感じに拾う（ネスト/配列/別名対応） */
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
    if (u.last_name || u.first_name)
      return `${u.last_name ?? ''}${u.first_name ?? ''}`.trim();
  }
  if (Array.isArray(src) && src.length > 0) return pickDisplayName(src[0]);
  if (src.last_name || src.first_name)
    return `${src.last_name ?? ''}${src.first_name ?? ''}`.trim();
  return '';
};
/** id / user_id を頑健に拾う */
const pickUserId = (src) => {
  if (!src) return 0;
  return Number(
    src.id ??
      src.user_id ??
      (src.user ? (src.user.id ?? src.user.user_id) : 0) ??
      (Array.isArray(src) && src[0] ? (src[0].id ?? src[0].user_id) : 0)
  );
};

/**
 * 認証ユーザー取得＋不足分の補完（m_users, m_shifts）
 * @returns { isLoadingMe, isAdmin, meName, meShift, meId }
 */
export default function useAuthUser(apiBase) {
  const [isLoadingMe, setIsLoadingMe] = useState(true);
  const [isAdmin, setIsAdmin] = useState(false);
  const [meName, setMeName] = useState('');
  const [meShift, setMeShift] = useState(null);
  const [meId, setMeId] = useState(0);

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const r1 = await axios.get(`${apiBase}/auth/user.php`, { withCredentials: true });
        const mine = r1?.data ?? {};
        const id1 = pickUserId(mine);
        const name1 = pickDisplayName(mine);

        const admin =
          mine.is_admin === 1 ||
          mine.is_admin === true ||
          (mine.user && (mine.user.is_admin === 1 || mine.user.is_admin === true)) ||
          String(mine.role || mine.user?.role || '').toLowerCase() === 'admin';

        let finalName = name1;
        let shiftId = Number(mine?.shift_id ?? mine?.user?.shift_id ?? 0) || 0;

        // m_users で不足補完
        if ((!finalName || !shiftId) && id1 > 0) {
          try {
            const r2 = await axios.get(`${apiBase}/m_users/select.php?id=${id1}`, { withCredentials: true });
            const u = Array.isArray(r2.data) ? r2.data[0] : null;
            if (!finalName) finalName = pickDisplayName(u || {});
            if (!shiftId) shiftId = Number(u?.shift_id ?? 0) || 0;
          } catch (_) {}
        }

        // シフト1件
        let shiftRow = null;
        if (shiftId) {
          try {
            const r3 = await axios.get(`${apiBase}/m_shifts/select.php?id=${shiftId}`, { withCredentials: true });
            shiftRow = Array.isArray(r3.data) ? r3.data[0] : null;
          } catch (_) {}
        }

        if (!alive) return;
        setMeId(id1 || 0);
        setMeName(finalName || '(名称未設定)');
        setIsAdmin(!!admin);
        setMeShift(shiftRow);
      } finally {
        if (alive) setIsLoadingMe(false);
      }
    })();
    return () => { alive = false; };
  }, [apiBase]);

  return { isLoadingMe, isAdmin, meName, meShift, meId };
}
