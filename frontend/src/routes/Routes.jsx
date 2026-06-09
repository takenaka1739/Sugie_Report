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

const Routes = createBrowserRouter([
  // ▼ 起動時は必ずログインへ
  { path: '/', element: <Navigate to="/report/login" replace /> },

  { path: '/report/login', element: <LoginPage /> },
  { path: '/report/change-password', element: <ChangePasswordPage /> },

  {
    path: '/report',
    element: <MainPage />,
    children: [
      // ▼ /report 直叩きもログインへ
      { index: true, element: <Navigate to="/report/login" replace /> },

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
