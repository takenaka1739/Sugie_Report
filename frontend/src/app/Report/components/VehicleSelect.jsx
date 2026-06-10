import React from 'react';
import { FormControl, InputLabel, MenuItem, Select } from '@mui/material';
import Grid from '@mui/material/Grid2';

const VehicleSelect = ({ vehicleId, vehicles, disabled, onChange }) => (
  <Grid container spacing={2}>
    <Grid size={{ xs: 12 }}>
      <FormControl fullWidth size="small" disabled={disabled}>
        <InputLabel id="vehicle-label">車両（ナンバー）</InputLabel>
        <Select
          labelId="vehicle-label"
          id="vehicle"
          label="車両（ナンバー）"
          value={vehicleId ?? ''}
          onChange={onChange}
        >
          <MenuItem value="">（未選択）</MenuItem>
          {vehicles.map((vehicle) => (
            <MenuItem key={vehicle.id} value={vehicle.id}>{vehicle.number}</MenuItem>
          ))}
        </Select>
      </FormControl>
    </Grid>
  </Grid>
);

export default VehicleSelect;
