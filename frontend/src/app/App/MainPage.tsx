import { Header } from '../../components';
import { Outlet } from 'react-router-dom';
import { Box } from '@mui/material';

const MainPage = () => {
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
