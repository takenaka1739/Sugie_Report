import { useEffect, useState } from "react";
import { createBrowserRouter, Navigate } from "react-router-dom";
import LoginPage from '../app/App/LoginPage';
import ChangePasswordPage from '../app/App/ChangePasswordPage';
import MainPage from '../app/App/MainPage';
import ReportPage from '../app/Report/ReportPage';
import PaidLeavePage from '../app/PaidLeave/PaidLeavePage';
import CalendarPage from '../app/Calendar/CalendarPage';
import UserPage from '../app/User/UserPage';
import ReimbursePage from '../app/Reimburse/ReimbursePage';
import VehiclePage from '../app/Vehicle/VehiclePage';
import OnSitePage from '../app/OnSite/OnSitePage';
import ShiftPage from '../app/Shift/ShiftPage';
import MailSettingPage from '../app/MailSetting/MailSettingPage';

const API_BASE =
  process.env.REACT_APP_API_BASE ||
  ((window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1')
    ? 'http://localhost/Report/backend'
    : `${window.location.origin}/report/backend`);

const ReportIndexRedirect = () => {
  const [to, setTo] = useState(null);

  useEffect(() => {
    let alive = true;

    (async () => {
      try {
        const res = await fetch(`${API_BASE}/auth/user.php`, {
          method: 'GET',
          credentials: 'include',
          cache: 'no-store',
        });
        if (alive) setTo(res.ok ? '/report/daily-report' : '/report/login');
      } catch {
        if (alive) setTo('/report/login');
      }
    })();

    return () => {
      alive = false;
    };
  }, []);

  if (!to) return null;
  return <Navigate to={to} replace />;
};

const Routes = createBrowserRouter([
  // ▼ 起動時は /report でログイン状態を確認する
  { path: '/', element: <Navigate to="/report" replace /> },

  { path: '/report/login', element: <LoginPage /> },
  { path: '/report/change-password', element: <ChangePasswordPage /> },

  {
    path: '/report',
    element: <MainPage />,
    children: [
      // ▼ /report 直叩きはログイン済みなら日報、未ログインならログインへ
      { index: true, element: <ReportIndexRedirect /> },

      { path: 'daily-report', element: <ReportPage /> },
      { path: 'paidleave', element: <PaidLeavePage /> },
      { path: 'calendar', element: <CalendarPage /> },
      { path: 'user', element: <UserPage /> },
      { path: 'reimburse', element: <ReimbursePage /> },
      { path: 'vehicle', element: <VehiclePage /> },
      { path: 'onsite', element: <OnSitePage /> },
      { path: 'shift', element: <ShiftPage /> },
      { path: 'mail-setting', element: <MailSettingPage /> },
    ]
  },
  { path: '*', element: <Navigate to="/report/login" replace /> },
]);

export default Routes;
