import { useEffect, useState } from 'react';
import {
  Stack,
  Button,
  IconButton,
  Drawer,
  Divider,
  Box,
} from '@mui/material';
import MenuIcon from '@mui/icons-material/Menu';

const API_BASE =
  process.env.REACT_APP_API_BASE ||
  ((window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1')
    ? 'http://localhost/Report/backend'
    : `${window.location.origin}/report/backend`);

const commonLinks = [
  { href: '/report/daily-report', label: '\u65e5\u5831' },
  { href: '/report/paidleave', label: '\u6709\u7d66\u7533\u8acb' },
  { href: '/report/calendar', label: '\u30ab\u30ec\u30f3\u30c0\u30fc' },
];

const adminLinks = [
  { href: '/report/user', label: '\u793e\u54e1\u30de\u30b9\u30bf' },
  { href: '/report/reimburse', label: '\u7acb\u66ff\u91d1\u30de\u30b9\u30bf' },
  { href: '/report/vehicle', label: '\u8eca\u4e21\u30de\u30b9\u30bf' },
  { href: '/report/onsite', label: '\u73fe\u5834\u540d\u30de\u30b9\u30bf' },
  { href: '/report/shift', label: '\u30b7\u30d5\u30c8\u30de\u30b9\u30bf' },
  { href: '/report/mail-setting', label: '\u30e1\u30fc\u30eb\u8a2d\u5b9a' },
];

const logoutLabel = '\u30ed\u30b0\u30a2\u30a6\u30c8';

const Header = () => {
  const [authorized, setAuthorized] = useState(true);
  const [open, setOpen] = useState(false);

  const handleLogout = async (e) => {
    try {
      if (e && typeof e.preventDefault === 'function') e.preventDefault();
      await fetch(`${API_BASE}/auth/logout.php`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
      });
    } catch (err) {
      console.warn('logout fetch error', err);
    } finally {
      try {
        sessionStorage.removeItem('auth_user');
      } catch {}
      window.location.href = '/report/login';
    }
  };

  useEffect(() => {
    (async () => {
      try {
        const res = await fetch(`${API_BASE}/auth/user.php`, {
          method: 'GET',
          credentials: 'include',
          cache: 'no-store',
        });
        if (!res.ok) {
          setAuthorized(false);
          return;
        }
        const text = await res.text();
        let json = null;
        try {
          json = text ? JSON.parse(text) : null;
        } catch {}
        if (json?.ok && json?.user) {
          setAuthorized(String(json.user.role || '').toLowerCase() === 'admin');
        } else {
          setAuthorized(false);
        }
      } catch {
        setAuthorized(false);
      }
    })();
  }, []);

  const toggleDrawer = (newOpen) => () => {
    setOpen(newOpen);
  };

  const navLinks = authorized ? [...commonLinks, ...adminLinks] : commonLinks;

  const DrawerList = (
    <Stack className="drawer-item">
      {navLinks.map((link) => (
        <Box key={link.href}>
          <Button variant="text" href={link.href} fullWidth>{link.label}</Button>
          <Divider />
        </Box>
      ))}
      <Button variant="text" href="/report/login" onClick={handleLogout} fullWidth>{logoutLabel}</Button>
    </Stack>
  );

  return (
    <>
      <header id="header">
        <Stack id="g-nav" direction="row" spacing={2}>
          {navLinks.map((link) => (
            <Button key={link.href} variant="contained" href={link.href} fullWidth>
              {link.label}
            </Button>
          ))}
          <Button
            variant="contained"
            color="secondary"
            href="/report/login"
            onClick={handleLogout}
            fullWidth
          >
            {logoutLabel}
          </Button>
        </Stack>
        <IconButton
          id="g-nav-openbtn"
          onClick={toggleDrawer(true)}
          aria-label="menu"
          size="large"
          sx={{ bgcolor: '#1976d2' }}
        >
          <MenuIcon id="g-nav-icon" />
        </IconButton>
        <Drawer
          id="g-nav-drawer"
          open={open}
          anchor="top"
          onClose={toggleDrawer(false)}
          sx={{ zIndex: 999 }}
        >
          {DrawerList}
        </Drawer>
      </header>
    </>
  );
};

export default Header;
