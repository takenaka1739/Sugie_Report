import React from 'react';
import { Checkbox, FormControlLabel, Stack } from '@mui/material';
import Grid from '@mui/material/Grid2';

const HealthCheckControls = ({
  isMobile,
  disabled,
  alcoholChecked,
  conditionChecked,
  onAlcoholChange,
  onConditionChange,
}) => (
  <Grid container spacing={2}>
    <Grid size={{ xs: 12 }}>
      <Stack
        direction="row"
        spacing={3}
        flexWrap={isMobile ? 'wrap' : 'nowrap'}
        className="report-edit-check-row"
      >
        <FormControlLabel
          control={(
            <Checkbox
              id="alcohol-check"
              checked={!!alcoholChecked}
              onChange={onAlcoholChange}
              disabled={disabled}
              size="small"
            />
          )}
          label={isMobile ? '酒気OK' : '酒気チェック（OKでチェック）'}
        />
        <FormControlLabel
          control={(
            <Checkbox
              id="condition-check"
              checked={!!conditionChecked}
              onChange={onConditionChange}
              disabled={disabled}
              size="small"
            />
          )}
          label={isMobile ? '体調OK' : '体調チェック（OKでチェック）'}
        />
      </Stack>
    </Grid>
  </Grid>
);

export default HealthCheckControls;
