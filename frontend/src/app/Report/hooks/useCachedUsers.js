import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';

/**
 * useCachedUsers
 * - 管理者用ユーザー一覧を sessionStorage にキャッシュして、ページ移動→戻ってきても再取得を避ける
 * - 既存の UserList はそのまま（users 配列の供給元だけ差し替える想定）
 *
 * 返却:
 *   { users, loading, refresh, setUsers }
 *     - users: Array<{ id:number, name:string }>
 *     - loading: boolean
 *     - refresh(): 明示的にサーバー再取得（キャッシュ更新）
 *     - setUsers(): 手動で配列を書き換えたいとき用（オプション）
 *
 * 使い方（ReportPageでの例・次ターンで差分を出します）:
 *   const { users, loading, refresh } = useCachedUsers(API_BASE, isAdmin);
 *   // users をそのまま UserList に渡す
 */

const KEY_USERS = 'report:users:cache.v1';
const KEY_TIME  = 'report:users:cacheTime.v1';
// TTL は “ログアウトまで” が理想だがブラウザからは検知しにくいので、実運用に近い 12 時間に設定
const CACHE_TTL_MS = 1000 * 60 * 60 * 12;

function normalizeBase(apiBase) {
  const raw = String(apiBase || '').replace(/\/+$/, '');
  if (/^https?:\/\//i.test(raw)) return raw;
  if (typeof window !== 'undefined') {
    const path = raw.startsWith('/') ? raw : `/${raw}`;
    return `${window.location.origin}${path}`;
  }
  return raw;
}

function shapeUsers(list) {
  if (!Array.isArray(list)) return [];
  return list
    .filter(r => r && (r.name || r.full_name || r.user_name || r.username || r.display_name))
    .map(r => ({
      id: Number(r.id ?? r.user_id ?? 0),
      name: String(r.name ?? r.full_name ?? r.user_name ?? r.username ?? r.display_name ?? ''),
    }))
    .filter(u => u.id && u.name);
}

export default function useCachedUsers(apiBase, isAdmin) {
  const BASE = useMemo(() => normalizeBase(apiBase), [apiBase]);
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(false);
  const mountedRef = useRef(false);

  const loadFromCache = useCallback(() => {
    try {
      const txt = sessionStorage.getItem(KEY_USERS);
      const ts  = Number(sessionStorage.getItem(KEY_TIME) || 0);
      if (!txt) return null;
      if (ts && (Date.now() - ts) > CACHE_TTL_MS) {
        sessionStorage.removeItem(KEY_USERS);
        sessionStorage.removeItem(KEY_TIME);
        return null;
      }
      const parsed = JSON.parse(txt);
      return Array.isArray(parsed) ? parsed : null;
    } catch {
      return null;
    }
  }, []);

  const saveToCache = useCallback((arr) => {
    try {
      sessionStorage.setItem(KEY_USERS, JSON.stringify(arr));
      sessionStorage.setItem(KEY_TIME, String(Date.now()));
    } catch {}
  }, []);

  const fetchUsers = useCallback(async () => {
    setLoading(true);
    try {
      const res = await axios.get(`${BASE}/m_users/select.php?mode=basic`, { withCredentials: true });
      const shaped = shapeUsers(res?.data);
      setUsers(shaped);
      saveToCache(shaped);
    } catch {
      // 失敗時はキャッシュがあればそれを維持、なければ空配列
      const cached = loadFromCache();
      setUsers(cached || []);
    } finally {
      setLoading(false);
    }
  }, [BASE, saveToCache, loadFromCache]);

  // 初期化：キャッシュ優先、無ければ取得。isAdmin=false なら空で終了（一般ユーザーはリスト非表示の前提）
  useEffect(() => {
    if (!isAdmin) {
      setUsers([]);
      setLoading(false);
      return;
    }
    mountedRef.current = true;

    const cached = loadFromCache();
    if (cached) setUsers(cached);
    if (!cached) fetchUsers();

    return () => { mountedRef.current = false; };
  }, [isAdmin, loadFromCache, fetchUsers]);

  // 公開 API
  const refresh = useCallback(() => {
    if (!isAdmin) return;
    fetchUsers();
  }, [isAdmin, fetchUsers]);

  return { users, loading, refresh, setUsers };
}
