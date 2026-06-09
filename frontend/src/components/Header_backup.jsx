import { useState } from 'react';
import { Stack, Button, IconButton, Drawer, Divider } from '@mui/material';
import MenuIcon from '@mui/icons-material/Menu';

const Header = () => {
  const [admin, setAdmin] = useState('管');
  const adminClick = () => setAdmin(prevState => (prevState === '管') ? '作' : '管');

  const [open, setOpen] = useState(false);

  const toggleDrawer = (newOpen) => () => {
    setOpen(newOpen);
  };

  // モバイル表示用メニュー
  const DrawerList = (
    <Stack className='drawer-item'>
      <Button variant='text' href="/report/report" fullWidth>作業日報</Button>
      <Divider />
      <Button variant='text' href="/report/paidholiday" fullWidth>有給申請</Button>
      <Divider />
      <Button variant='text' href="/report/calendar" fullWidth>カレンダー</Button>
      {(admin === '管') && (
        <>
          <Divider />
          <Button variant='text' href="/report/user" fullWidth>社員マスタ</Button>
          <Divider />
          <Button variant='text' href="/report/reimburse" fullWidth>立替金マスタ</Button>
          <Divider />
          <Button variant='text' href="/report/vehicle" fullWidth>車両マスタ</Button>
          <Divider />
          <Button variant='text' href="/report/onsite" fullWidth>営業所マスタ</Button>
          <Divider />
          <Button variant='text' href="/report/shift" fullWidth>シフトマスタ</Button>
        </>
      )}
    </Stack>
  );

  return (
    <>
      <header id="header">
        <Stack id="g-nav" direction='row' spacing={2}>
          <Button variant='contained' href="/report/report" fullWidth>作業日報</Button>
          
          {(admin === '管') && (
            <>
              <Button variant='contained' href="/report/paidholiday" fullWidth>有給申請</Button>
            </>
          )}
          {(admin === '作') && (
            <>
              <Button variant='contained' href="/report/paidholidayrequest" fullWidth>有給申請</Button>
            </>
          )}
          <Button variant='contained' href="/report/calendar" fullWidth>カレンダー</Button>

          {(admin === '管') && (
            <>
              <Button variant='contained' href="/report/user" fullWidth>社員マスタ</Button>
              <Button variant='contained' href="/report/reimburse" fullWidth>立替金マスタ</Button>
              <Button variant='contained' href="/report/vehicle" fullWidth>車両マスタ</Button>
              <Button variant='contained' href="/report/onsite" fullWidth>営業所マスタ</Button>
              <Button variant='contained' href="/report/shift" fullWidth>シフトマスタ</Button>
            </>
          )}
        </Stack>
        <IconButton id="g-nav-openbtn" onClick={toggleDrawer(true)} aria-label="menu" size='large' sx={{ bgcolor: '#1976d2' }}>
          <MenuIcon id="g-nav-icon"/>
        </IconButton>
        <Drawer id="g-nav-drawer" open={open} anchor='top' onClose={toggleDrawer(false)} sx={{ zIndex: 999 }}>
          {DrawerList}
        </Drawer>
        <IconButton id="adminbtn" onClick={adminClick} aria-label="menu" size='middle' sx={{ bgcolor: '#f33' }}>
          {admin}
        </IconButton>
      </header>
    </>
  );
}

export default Header;