import { useEffect } from 'react';
import { Header } from '../../components';
import { Outlet } from 'react-router-dom';
import { Box } from '@mui/material';
import { useNavigate } from 'react-router-dom';

const MainPage = () => {
  const navigate = useNavigate();

  // 起動直後の最初の1回だけ、必ずログイン画面へリダイレクト
  useEffect(() => {
    try {
      const KEY = 'boot_redirect_done';
      if (!sessionStorage.getItem(KEY)) {
        sessionStorage.setItem(KEY, '1');
        // MainPage は /report 配下でのみレンダリングされる想定なので、
        // /report/* で起動した場合は必ず /report/login へ飛ばす
        navigate('/report/login', { replace: true });
      }
    } catch {
      // sessionStorage が使えない環境でも落ちないように握りつぶす
      navigate('/report/login', { replace: true });
    }
  }, [navigate]);

  return (
    <>
      <Header />
      <Box id="main">
        <Outlet />
      </Box>
    </>
  );
}

export default MainPage;
