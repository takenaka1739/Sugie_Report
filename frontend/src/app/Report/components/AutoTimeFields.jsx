import React from 'react';
import { Box, InputAdornment, TextField, Tooltip, Typography } from '@mui/material';
import Grid from '@mui/material/Grid2';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';

const AutoTimeFields = ({ auto, hasSegment2 }) => {
  const autoFieldProps = {
    disabled: true,
    InputProps: {
      readOnly: true,
      startAdornment: (
        <InputAdornment position="start">
          <Tooltip title="自動計算（編集不可）">
            <LockOutlinedIcon fontSize="small" />
          </Tooltip>
        </InputAdornment>
      ),
    },
    helperText: hasSegment2 ? '自動計算：区間ごとに計算して合算' : '自動計算（編集できません）',
  };

  return (
    <Box>
      <Typography variant="subtitle2" className="report-edit-section-title">自動計算（編集不可）</Typography>
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, sm: 4 }}>
          <TextField label="早出" fullWidth size="small" value={auto.earlyHm || ''} {...autoFieldProps} />
        </Grid>
        <Grid size={{ xs: 12, sm: 4 }}>
          <TextField label="残業" fullWidth size="small" value={auto.overtimeHm || ''} {...autoFieldProps} />
        </Grid>
        <Grid size={{ xs: 12, sm: 4 }}>
          <TextField label="深夜" fullWidth size="small" value={auto.midnightHm || ''} {...autoFieldProps} />
        </Grid>
      </Grid>
    </Box>
  );
};

export default AutoTimeFields;
