import './App.css';
import { RouterProvider } from 'react-router-dom';
import Routes from './routes/Routes';
import { Box } from '@mui/material';

function App() {
  return (
    <>
    <Box id="body">
      <RouterProvider router={Routes}/>
    </Box>
    </>
  );
}

export default App;
