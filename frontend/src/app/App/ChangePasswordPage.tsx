import React, { useCallback, useState } from 'react';
import {
  Avatar,
  Box,
  Button,
  Link,
  Paper,
  TextField,
  Typography,
  Alert,
  Snackbar,
  Container,
} from '@mui/material';
import Grid from '@mui/material/Grid2';
import LockOutlinedIcon from "@mui/icons-material/LockOutlined";
import axios from 'axios';

// API ベースURL
const BASE_ADDR =
  (import.meta as any)?.env?.VITE_API_BASE ||
  (process.env as any)?.REACT_APP_API_BASE ||
  ((typeof window !== 'undefined' && (
    window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1'
  )) ? 'http://localhost/Report/backend' : '/Report/backend');

const UNIFIED_AUTH_ERROR = 'IDもしくはパスワードが一致しません';

const ChangePasswordPage: React.FC = () => {
  const [target, setTarget] = useState<string>(''); // ID or 名前
  const [oldPassword, setOldPassword] = useState<string>('');
  const [newPassword, setNewPassword] = useState<string>('');
  const [confirmPassword, setConfirmPassword] = useState<string>('');

  const [loading, setLoading] = useState<boolean>(false);

  // タイトル下に表示するエラーメッセージ
  const [errorMsg, setErrorMsg] = useState<string>('');

  // サーバーや通信系の通知
  const [snackOpen, setSnackOpen] = useState<boolean>(false);
  const [snackMsg, setSnackMsg] = useState<string>('');
  const [snackSeverity, setSnackSeverity] = useState<'success'|'error'|'info'|'warning'>('success');

  const handleCloseSnack = useCallback(() => setSnackOpen(false), []);

  // 入力変更時に画面エラーを消す
  const onChangeTarget = (v: string) => { setTarget(v); if (errorMsg) setErrorMsg(''); };
  const onChangeOld = (v: string) => { setOldPassword(v); if (errorMsg) setErrorMsg(''); };
  const onChangeNew = (v: string) => { setNewPassword(v); if (errorMsg) setErrorMsg(''); };
  const onChangeConfirm = (v: string) => { setConfirmPassword(v); if (errorMsg) setErrorMsg(''); };

  // 認証不一致（ユーザー列挙を避けるための統一扱い）かどうかを判定
  const isUnifiedAuthMismatch = (status?: number, data?: any, message?: string) => {
    const code = (data?.code || '').toString().toLowerCase();
    const msg  = (data?.message || message || '').toString();

    if (status === 401 || status === 404) return true;
    if (status && status >= 400 && status < 500) {
      if (
        msg.includes('一致しません') ||
        msg.includes('not match') ||
        msg.includes('invalid') ||
        msg.includes('unauthorized') ||
        msg.includes('not found') ||
        msg.includes('存在しない') ||
        code === 'user_not_found' ||
        code === 'not_found' ||
        code === 'invalid_credentials'
      ) {
        return true;
      }
    }
    if (status === 200 && data && data.ok === false) {
      if (
        code === 'user_not_found' ||
        code === 'not_found' ||
        code === 'invalid_credentials' ||
        (msg && (
          msg.includes('一致しません') ||
          msg.includes('not match') ||
          msg.includes('invalid') ||
          msg.includes('unauthorized') ||
          msg.includes('not found') ||
          msg.includes('存在しない')
        ))
      ) {
        return true;
      }
    }
    return false;
  };

  const handleSubmit = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();

    // クライアント側の入力チェック（送信時だけ表示）
    if (!target.trim()) {
      setErrorMsg('ID（ユーザー名 または 数値ID）を入力してください。');
      return;
    }
    if (!oldPassword || !newPassword || !confirmPassword) {
      setErrorMsg('旧パスワード／新パスワード／確認をすべて入力してください。');
      return;
    }
    if (newPassword !== confirmPassword) {
      setErrorMsg('新パスワードと確認が一致しません。');
      return;
    }
    // ▼ 追加：旧パスワードと同一は禁止
    if (oldPassword === newPassword) {
      setErrorMsg('新パスワードは旧パスワードと異なる必要があります。');
      return;
    }

    try {
      setLoading(true);
      const url = `${BASE_ADDR}/auth/change_password.php`;
      const payload = {
        target: target.trim(),
        old_password: oldPassword,
        new_password: newPassword,
      };

      const res = await axios.post(url, payload);

      if (res?.data?.ok) {
        alert('パスワードを変更しました。');
        window.location.href = '/report/login';
        return;
      }

      if (isUnifiedAuthMismatch(res?.status, res?.data)) {
        setErrorMsg(UNIFIED_AUTH_ERROR);
      } else {
        const message = res?.data?.message || 'パスワード変更に失敗しました。';
        setErrorMsg(message);
      }

    } catch (err: any) {
      const status = err?.response?.status as number | undefined;
      const data   = err?.response?.data;
      const msg    = err?.message as string | undefined;

      if (isUnifiedAuthMismatch(status, data, msg)) {
        setErrorMsg(UNIFIED_AUTH_ERROR);
      } else {
        setSnackSeverity('error');
        setSnackMsg((data?.message as string) || msg || 'サーバーエラーが発生しました。');
        setSnackOpen(true);
      }
    } finally {
      setLoading(false);
    }
  }, [target, oldPassword, newPassword, confirmPassword, errorMsg]);

  return (
    <>
      <Box sx={{ py: { xs: 2, sm: 3 }, px: { xs: 1.5, sm: 2 }, width: '100%', boxSizing: 'border-box' }}>
        <Container maxWidth="xs" disableGutters>
          <Paper
            component="form"
            onSubmit={handleSubmit}
            elevation={1}
            autoComplete="off"
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
                Change Password
              </Typography>
            </Grid>

            {errorMsg && (
              <Alert severity="error" sx={{ mb: 2, wordBreak: 'break-word' }}>
                {errorMsg}
              </Alert>
            )}

            <TextField
              type="text"
              label="ID（ユーザー名）"
              variant="standard"
              fullWidth
              required
              value={target}
              onChange={(e) => onChangeTarget(e.target.value)}
              sx={{ mb: 2 }}
              autoComplete="off"
              name="no-autofill-user"
              id="change-password-user-id"
              inputProps={{
                autoComplete: 'off',
                'data-form-type': 'other',
                autoCorrect: 'off',
                autoCapitalize: 'none',
                spellCheck: 'false',
                enterKeyHint: 'next',
              }}
            />

            <TextField
              type="password"
              label="旧パスワード"
              variant="standard"
              fullWidth
              required
              value={oldPassword}
              onChange={(e) => onChangeOld(e.target.value)}
              sx={{ mb: 2 }}
              autoComplete="current-password"
            />

            <TextField
              type="password"
              label="新パスワード"
              variant="standard"
              fullWidth
              required
              value={newPassword}
              onChange={(e) => onChangeNew(e.target.value)}
              sx={{ mb: 1.5 }}
              autoComplete="new-password"
            />
            <TextField
              type="password"
              label="新パスワード（確認）"
              variant="standard"
              fullWidth
              required
              value={confirmPassword}
              onChange={(e) => onChangeConfirm(e.target.value)}
              sx={{ mb: 2.5 }}
              autoComplete="new-password"
            />

            <Box mt={3}>
              <Button
                type="submit"
                variant="contained"
                fullWidth
                disabled={loading}
                sx={{ mb: 1.5 }}
              >
                {loading ? '変更中…' : '変更'}
              </Button>
              <Link href="/report/login" underline="hover" sx={{ display: 'block', textAlign: 'center' }}>
                ログインへ戻る
              </Link>
            </Box>
          </Paper>
        </Container>
      </Box>

      <Snackbar
        open={snackOpen}
        autoHideDuration={4000}
        onClose={handleCloseSnack}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert elevation={6} variant="filled" onClose={handleCloseSnack} severity={snackSeverity} sx={{ width: '100%' }}>
          {snackMsg}
        </Alert>
      </Snackbar>
    </>
  );
};

export default ChangePasswordPage;
