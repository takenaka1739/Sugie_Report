import { useCallback, useEffect, useState } from 'react';
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

/**
 * 管理者用：m_users?mode=basic でユーザー一覧を取得するフック
 * @param {string} apiBase - 例: '/Report/backend'
 * @param {boolean} enabled - 管理者のとき true（true のときのみ取得）
 * @returns { users, loading, error, refresh }
 *   users: [{ id:number, name:string }]
 */
export default function useUsersBasic(apiBase, enabled) {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setErr] = useState(null);

  const refresh = useCallback(async () => {
    if (!enabled) {
      setUsers([]);
      setLoading(false);
      setErr(null);
      return;
    }
    setLoading(true);
    setErr(null);
    try {
      const res = await axios.get(`${apiBase}/m_users/select.php?mode=basic`, {
        withCredentials: true,
      });
      const list = Array.isArray(res?.data)
        ? res.data
            .filter(
              (r) =>
                r &&
                (r.name ||
                  r.full_name ||
                  r.user_name ||
                  r.username ||
                  r.display_name)
            )
            .map((r) => ({
              id: Number(r.id ?? r.user_id ?? 0),
              name: pickDisplayName(r),
            }))
        : [];
      setUsers(list);
    } catch (e) {
      setUsers([]);
      setErr(e);
    } finally {
      setLoading(false);
    }
  }, [apiBase, enabled]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  return { users, loading, error, refresh };
}
