import React from 'react';
import {
  Checkbox,
  FormControl,
  FormControlLabel,
  Radio,
  RadioGroup,
} from '@mui/material';

const WORK_TYPE_OPTIONS = [
  { value: 0, label: '出勤' },
  { value: 1, label: '雨天中止' },
  { value: 2, label: '業務都合休暇' },
  { value: 3, label: '自己都合休暇' },
];

const WorkTypeControls = ({
  workType,
  onWorkTypeChange,
  isNightShift,
  onNightShiftChange,
}) => (
  <div className="report-edit-work-type-row">
    <FormControl component="fieldset">
      <RadioGroup
        row
        className="report-edit-work-type-group"
        value={Number(workType) || 0}
        onChange={(e) => onWorkTypeChange(e.target.value)}
      >
        {WORK_TYPE_OPTIONS.map((option) => (
          <FormControlLabel
            key={option.value}
            value={option.value}
            control={<Radio size="small" />}
            label={option.label}
          />
        ))}
      </RadioGroup>
    </FormControl>

    <FormControlLabel
      className="report-edit-night-shift"
      control={(
        <Checkbox
          id="is-night-shift"
          checked={!!isNightShift}
          disabled={Number(workType) !== 0}
          onChange={(e) => onNightShiftChange(e.target.checked)}
          size="small"
        />
      )}
      label="夜勤"
    />
  </div>
);

export default WorkTypeControls;
