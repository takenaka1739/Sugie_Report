import React from 'react';
import {
  Paper,
  List,
  ListItemButton,
  ListItemText,
  Typography,
  Box,
} from '@mui/material';
import axios from 'axios';

/** ===== グローバル永続キャッシュ（モジュール間で共有・再マウントしても保持） ===== */
const persistentShiftLabelCache = new Map(); // key: userId (number) -> label (string)

/** スクロール位置の保存キー（同ページでユニークならOK） */
const SCROLL_KEY = 'report:userlist:scrollTop.v1';

/** API ベース（ReportPage と同じ規則） */
const API_BASE =
  process.env.REACT_APP_API_BASE ||
  ((typeof window !== 'undefined' &&
    (window.location.hostname === 'localhost' ||
      window.location.hostname === '127.0.0.1'))
    ? 'http://localhost/Report/backend'
    : '/Report/backend');

const hm = (v) => (v ? String(v).slice(0, 5) : '');

async function fetchShiftLabel(userId) {
  // 既に永続キャッシュにあれば即返す（ネットワーク不要）
  if (persistentShiftLabelCache.has(userId)) {
    return persistentShiftLabelCache.get(userId);
  }

  // 1) ユーザー -> shift_id
  const uRes = await axios.get(`${API_BASE}/m_users/select.php?id=${userId}`, {
    withCredentials: true,
  });
  const uRow = Array.isArray(uRes.data) ? uRes.data[0] : null;
  const sid = Number(uRow?.shift_id ?? 0) || 0;
  if (!sid) {
    persistentShiftLabelCache.set(userId, 'シフト未設定');
    return 'シフト未設定';
  }

  // 2) シフト -> 出社/退社
  const sRes = await axios.get(`${API_BASE}/m_shifts/select.php?id=${sid}`, {
    withCredentials: true,
  });
  const sRow = Array.isArray(sRes.data) ? sRes.data[0] : null;

  const start = hm(sRow?.regular_start);
  const finish =
    hm(sRow?.regular_finish ?? sRow?.regular_end ?? sRow?.regular_finish_time);
  const label =
    start || finish ? `出${start || '--:--'} 退${finish || '--:--'}` : 'シフト未設定';

  persistentShiftLabelCache.set(userId, label);
  return label;
}

/**
 * UserList
 * - 管理者：全ユーザー表示。サブ情報に「出社/退社（シフト）」を表示
 * - 取得済みはモジュールスコープに永続キャッシュし、以降はネットワーク不要
 * - 取得中は前の表示を維持（空/既存のまま）し、完了時だけ置き換えてチラつきを抑制
 * - 【追加】左リストのスクロール位置を sessionStorage に保存・復元
 */
const UserList = ({
  isAdmin,
  users = [],
  onSelect,
  selectedUserId,
}) => {
  // ローカル状態にもコピー（描画のため）。初期値は永続キャッシュから生成
  const [secondaryMap, setSecondaryMap] = React.useState(() => {
    const obj = {};
    users.forEach(u => {
      const id = Number(u?.id || 0);
      if (id > 0 && persistentShiftLabelCache.has(id)) {
        obj[id] = persistentShiftLabelCache.get(id);
      }
    });
    return obj;
  });

  const fetchingRef = React.useRef(new Set()); // 同時多発防止
  const aliveRef = React.useRef(true);
  React.useEffect(() => {
    aliveRef.current = true;
    return () => { aliveRef.current = false; };
  }, []);

  // ===== ここから：スクロール位置の保存・復元（最小追加） =====
  const listRef = React.useRef(null);

  // 初回＆ユーザー数の変化後に、保存済み scrollTop を復元
  React.useLayoutEffect(() => {
    const el = listRef.current;
    if (!el) return;
    try {
      const saved = Number(sessionStorage.getItem(SCROLL_KEY) || 0);
      if (saved > 0) {
        // 描画が安定してから反映（2フレーム待ち）
        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            el.scrollTop = saved;
          });
        });
      }
    } catch {}
  }, [users.length]);

  // スクロールするたびに現在位置を保存（rAFで軽量化）
  React.useEffect(() => {
    const el = listRef.current;
    if (!el) return;
    let ticking = false;
    const onScroll = () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        try {
          sessionStorage.setItem(SCROLL_KEY, String(el.scrollTop || 0));
        } catch {}
        ticking = false;
      });
    };
    el.addEventListener('scroll', onScroll, { passive: true });
    return () => el.removeEventListener('scroll', onScroll);
  }, []);

  // クリック直前の位置を保存してから onSelect を呼ぶ（右側再描画で位置が飛ばないように）
  const handleClick = (u) => {
    const el = listRef.current;
    if (el) {
      try { sessionStorage.setItem(SCROLL_KEY, String(el.scrollTop || 0)); } catch {}
    }
    onSelect?.(u);
  };
  // ===== ここまで：スクロール保持処理 =====

  // ラベルを取得してローカル状態＆永続キャッシュに反映
  const ensureLabel = React.useCallback(async (userId) => {
    if (!userId || fetchingRef.current.has(userId)) return;

    // 既に永続キャッシュがあればローカルに反映して終了（描画だけ合わせる）
    if (persistentShiftLabelCache.has(userId)) {
      const cached = persistentShiftLabelCache.get(userId);
      setSecondaryMap(prev => (prev[userId] === cached ? prev : { ...prev, [userId]: cached }));
      return;
    }

    try {
      fetchingRef.current.add(userId);
      const label = await fetchShiftLabel(userId);
      if (!aliveRef.current) return;
      // 変更があるときのみ setState（無駄な再レンダを防ぐ）
      setSecondaryMap(prev => (prev[userId] === label ? prev : { ...prev, [userId]: label }));
    } catch {
      if (!aliveRef.current) return;
      setSecondaryMap(prev => (prev[userId] ? prev : { ...prev, [userId]: '取得エラー' }));
    } finally {
      fetchingRef.current.delete(userId);
    }
  }, []);

  // 選択中ユーザーは最優先で確保（即座に前回値を見せ、無ければ取得）
  React.useEffect(() => {
    const id = Number(selectedUserId || 0);
    if (id) ensureLabel(id);
  }, [selectedUserId, ensureLabel]);

  // 初期レンダ/ユーザー一覧更新時：未取得のものを穏やかに逐次ロード
  React.useEffect(() => {
    let cancelled = false;
    const ids = users
      .map(u => Number(u?.id || 0))
      .filter(Boolean);

    (async () => {
      for (const id of ids) {
        if (cancelled || !aliveRef.current) break;
        if (persistentShiftLabelCache.has(id) || fetchingRef.current.has(id)) {
          // ローカルに反映だけ合わせる
          const cached = persistentShiftLabelCache.get(id);
          if (cached) {
            setSecondaryMap(prev => (prev[id] === cached ? prev : { ...prev, [id]: cached }));
          }
          continue;
        }
        await ensureLabel(id);
        await new Promise(r => setTimeout(r, 60)); // ほんの少し間隔
      }
    })();

    return () => { cancelled = true; };
  }, [users, ensureLabel]);

  if (!isAdmin) {
    return (
      <Paper sx={{ height: '90vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <Box sx={{ p: 2, textAlign: 'center' }}>
          <Typography variant="body2" color="text.secondary">
            このリストは管理者のみ表示されます
          </Typography>
        </Box>
      </Paper>
    );
  }

  return (
    <Paper sx={{ height: '90vh' }} className="report-userlist">
      <List
        sx={{ height: '85vh', maxHeight: '85vh', overflow: 'auto' }}
        ref={listRef} // ← スクロール位置保持のための参照
      >
        {users.map((u) => {
          const id = Number(u?.id || 0);
          // 優先：props.sub > 永続キャッシュ > ローカル > 空
          const cached = persistentShiftLabelCache.get(id);
          const sec = u.sub ?? cached ?? secondaryMap[id] ?? '';
          return (
            <ListItemButton
              key={id}
              selected={id === Number(selectedUserId || 0)}
              onClick={() => handleClick(u)} // ← 位置保存してから選択
            >
              <ListItemText
                primary={u.name}
                secondary={sec}
                primaryTypographyProps={{ noWrap: true }}
                secondaryTypographyProps={{ noWrap: true }}
              />
            </ListItemButton>
          );
        })}
      </List>
    </Paper>
  );
};

export default UserList;
