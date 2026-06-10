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

const SegmentFields = ({
  title,
  suffix = '',
  start,
  finish,
  onSiteId,
  work,
  sites,
  disabled,
  onStartChange,
  onStartBlur,
  onFinishChange,
  onFinishBlur,
  onSiteChange,
  onWorkChange,
  startPlaceholder,
  finishPlaceholder,
}) => {
  const startId = suffix ? `start-time${suffix}` : 'start-time';
  const finishId = suffix ? `finish-time${suffix}` : 'finish-time';
  const siteLabelId = suffix ? `on-site${suffix}-label` : 'on-site-label';
  const siteId = suffix ? `on-site${suffix}` : 'on-site';
  const workId = suffix ? `work${suffix}` : 'work';
  const segmentLabel = suffix === '2' ? '区間2' : '区間1';

  return (
    <Box>
      <Typography variant="subtitle2" className="report-edit-section-title">{title}</Typography>
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, sm: 3 }}>
          <TextField
            id={startId}
            label={suffix ? `出社${suffix}` : '出社'}
            fullWidth
            size="small"
            value={start}
            onChange={onStartChange}
            onBlur={onStartBlur}
            placeholder={startPlaceholder}
            disabled={disabled}
            inputProps={{ inputMode: 'numeric', pattern: '[0-9]*' }}
          />
        </Grid>

        <Grid size={{ xs: 12, sm: 3 }}>
          <TextField
            id={finishId}
            label={suffix ? `退社${suffix}` : '退社'}
            fullWidth
            size="small"
            value={finish}
            onChange={onFinishChange}
            onBlur={onFinishBlur}
            placeholder={finishPlaceholder}
            disabled={disabled}
            inputProps={{ inputMode: 'numeric', pattern: '[0-9]*' }}
          />
        </Grid>

        <Grid size={{ xs: 12, sm: 6 }}>
          <FormControl fullWidth size="small" disabled={disabled}>
            <InputLabel id={siteLabelId}>営業所（{segmentLabel}）</InputLabel>
            <Select
              labelId={siteLabelId}
              id={siteId}
              label={`営業所（${segmentLabel}）`}
              value={onSiteId ?? ''}
              onChange={onSiteChange}
            >
              <MenuItem value="">（未選択）</MenuItem>
              {sites.map((site) => (
                <MenuItem key={site.id} value={site.id}>{site.name}</MenuItem>
              ))}
            </Select>
          </FormControl>
        </Grid>

        <Grid size={{ xs: 12 }}>
          <TextField
            id={workId}
            label={`現場名（${segmentLabel}）`}
            fullWidth
            size="small"
            value={work}
            onChange={onWorkChange}
            disabled={disabled}
          />
        </Grid>
      </Grid>
    </Box>
  );
};

export default SegmentFields;
