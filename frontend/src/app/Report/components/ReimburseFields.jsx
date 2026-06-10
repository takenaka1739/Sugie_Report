import React from 'react';
import {
  Box,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  TextField,
  Typography,
} from '@mui/material';
import Grid from '@mui/material/Grid2';

const ReimburseFields = ({
  paymentIds,
  amountStrs,
  payments,
  disabled,
  onPaymentChange,
  onAmountChange,
}) => (
  <Box>
    <Typography variant="subtitle2" className="report-edit-section-title">立替金</Typography>
    <Grid container spacing={2}>
      {[0, 1, 2, 3, 4].map((idx) => (
        <React.Fragment key={idx}>
          <Grid size={{ xs: 12, sm: 6 }}>
            <FormControl fullWidth size="small" disabled={disabled}>
              <InputLabel id={`payment-${idx + 1}-label`}>{`立替${idx + 1}`}</InputLabel>
              <Select
                labelId={`payment-${idx + 1}-label`}
                id={`payment-${idx + 1}`}
                label={`立替${idx + 1}`}
                value={paymentIds[idx] ?? ''}
                onChange={onPaymentChange(idx)}
              >
                <MenuItem value="">（未選択）</MenuItem>
                {payments.map((payment) => (
                  <MenuItem key={payment.id} value={payment.id}>{payment.name}</MenuItem>
                ))}
              </Select>
            </FormControl>
          </Grid>

          <Grid size={{ xs: 12, sm: 6 }}>
            <TextField
              id={`amount-${idx + 1}`}
              label={`金額${idx + 1}`}
              fullWidth
              size="small"
              value={amountStrs[idx]}
              onChange={onAmountChange(idx)}
              placeholder="1,000"
              disabled={disabled || paymentIds[idx] == null}
              inputProps={{ inputMode: 'numeric', pattern: '[0-9]*' }}
            />
          </Grid>
        </React.Fragment>
      ))}
    </Grid>
  </Box>
);

export default ReimburseFields;
