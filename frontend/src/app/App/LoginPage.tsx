import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Avatar,
  Box,
  Button,
  Link,
  Paper,
  TextField,
  Typography,
  Alert,
  Container,
} from '@mui/material';
import Grid from '@mui/material/Grid2';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';

// --- 修正ポイント: isLocal を先に判定し、三項は () で囲って || の右側に置く ---
const isLocal =
  typeof window !== 'undefined' &&
  (window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1');

// .env が最優先（存在すればそれを使う）。無ければローカル/本番を自動切替
const API_BASE =
  (process.env.REACT_APP_API_BASE as string) ||
  (isLocal ? 'http://localhost/Report/backend' : '/report/backend');

type AuthUser = { id: number; name: string; role: 'admin' | 'user' | string };

const LoginPage: React.FC = () => {
  const [user, setUser] = useState<string>('');
  const [password, setPassword] = useState<string>('');
  const [loading, setLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string>('');
  const navigate = useNavigate();

  // ログイン実行：login.php → 成功したら user.php でセッション検証 → 日報へ
  const handleLogin = async (e?: React.FormEvent) => {
    e?.preventDefault();
    setErrorMsg('');
    setLoading(true);
    try {
      console.debug('[LoginPage] API_BASE =', API_BASE); // デバッグ表示

      const res = await fetch(`${API_BASE}/auth/login.php`, {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: user, password }),
      });

      const loginText = await res.text();
      let loginJson: any = null;
      try { loginJson = loginText ? JSON.parse(loginText) : null; } catch {}
      console.debug('login.php response', { status: res.status, body: loginText });

      if (!res.ok || !loginJson?.ok) {
        setErrorMsg(
          loginJson?.message ||
            `ユーザー名またはパスワードが間違っています（HTTP ${res.status}）`
        );
        return;
      }

      // セッション確認
      const verifyRes = await fetch(`${API_BASE}/auth/user.php`, {
        method: 'GET',
        credentials: 'include',
        cache: 'no-store',
      });

      const verifyText = await verifyRes.text();
      let me: any = null;
      try { me = verifyText ? JSON.parse(verifyText) : null; } catch {}
      console.debug('user.php after login', { status: verifyRes.status, body: verifyText });

      if (!verifyRes.ok || !me?.ok || !me?.user) {
        setErrorMsg('セッション確立に失敗しました。Cookie/SameSite/Path を確認してください。');
        return;
      }

      const authUser = me.user as AuthUser;
      sessionStorage.setItem('auth_user', JSON.stringify(authUser));
      navigate('/report/daily-report', { replace: true });
    } catch {
      setErrorMsg('ネットワークエラーが発生しました');
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      {/* 画面左右に余白を持たせ、横スクロールを抑止 */}
      <Box sx={{ py: { xs: 2, sm: 3 }, px: { xs: 1.5, sm: 2 }, width: '100%', boxSizing: 'border-box' }}>
        {/* コンテンツは常に中央。xs幅では全幅、sm以上は最大420pxに制限 */}
        <Container maxWidth="xs" disableGutters>
          <Paper
            component="form"
            onSubmit={handleLogin}
            elevation={1}
            sx={{
              width: '100%',
              maxWidth: { xs: '100%', sm: 420 },
              mx: 'auto',
              p: { xs: 3, sm: 4 },
              boxSizing: 'border-box',
              overflow: 'hidden',
            }}
          >
            <Grid container direction="column" alignItems="center">
              <Avatar sx={{ bgcolor: '#666' }}>
                <LockOutlinedIcon />
              </Avatar>
              <Typography variant="h5" sx={{ my: 3 }}>
                Sign In
              </Typography>
            </Grid>

            {errorMsg && (
              <Alert severity="error" sx={{ mb: 2, wordBreak: 'break-word' }}>
                {errorMsg}
              </Alert>
            )}

            <TextField
              label="ユーザー名"
              variant="standard"
              value={user}
              onChange={(e) => setUser(e.target.value)}
              autoComplete="username"
              autoFocus
              fullWidth
              required
              sx={{ mb: 1.5 }}
            />
            <TextField
              type="password"
              label="パスワード"
              variant="standard"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password"
              fullWidth
              required
              sx={{ mb: 2.5 }}
            />

            <Box mt={3}>
              <Button
                type="submit"
                variant="contained"
                fullWidth
                disabled={loading}
                sx={{ mb: 2 }}
              >
                {loading ? '処理中…' : 'ログイン'}
              </Button>
              <Typography sx={{ textAlign: 'center' }}>
                <Link href="/report/change-password">パスワード変更</Link>
              </Typography>
            </Box>
          </Paper>
        </Container>
      </Box>
    </>
  );
};

export default LoginPage;
