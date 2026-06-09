import React from 'react';
import {
  Stack, Box, Chip, Button, useMediaQuery,
} from '@mui/material';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import SummarizeIcon from '@mui/icons-material/Summarize';

const ReportToolbar = ({
  monthValue,
  onChangeMonth,
  chipLabel,
  isAdmin,
  onExportMyMonth,
  onExportSummary,
  disableExportMy = false,
  loading = false,

  //  追加：有休の注意文（管理者は非表示にする想定なら、親で空にしてもOK）
  paidLeaveMessage = '',
}) => {
  const isXs = useMediaQuery('(max-width:600px)');

  const compactChip = (text) => {
    if (!text || !isXs) return text;
    let s = String(text)
      .replace(/出社/g, '出')
      .replace(/退社/g, '退')
      .replace(/深夜残業開始|深夜残業/g, '深')
      .replace(/[：:]/g, ':')
      .replace(/\s+/g, ' ')
      .trim();
    s = s.replace(/出:?(\d{1,2}:\d{2})/g, '出$1')
         .replace(/退:?(\d{1,2}:\d{2})/g, '退$1')
         .replace(/深:?(\d{1,2}:\d{2})/g, '深$1');
    return s;
  };
  const labelText = compactChip(chipLabel);

  return (
    <Box
      className="report-toolbar"
      sx={{
        px: 1.5,
        pt: 1.25,
        pb: 0.9,
        pr: { xs: '60px', sm: 1.5 },
      }}
    >
      {/*  追加：有休メッセージ表示枠（必要な時だけ出す） */}
      {!!paidLeaveMessage && !isAdmin && (
        <Box sx={{ mb: 1 }}>
          <Box
            sx={{
              border: '1px solid rgba(255, 193, 7, 0.65)',
              background: 'rgba(255, 248, 225, 0.85)',
              borderRadius: 1,
              px: 1.5,
              py: 1,
              fontSize: 14,
              lineHeight: 1.6,
            }}
          >
            {paidLeaveMessage}
          </Box>
        </Box>
      )}

      <Stack
        direction={{ xs: 'column', sm: 'row' }}
        alignItems={{ xs: 'stretch', sm: 'center' }}
        flexWrap="wrap"
        sx={{ rowGap: 1, columnGap: 1.25, width: '100%' }}
      >
        <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale="ja">
          <DatePicker
            label="表示月"
            views={['year', 'month']}
            openTo="month"
            value={monthValue}
            onChange={(v) => v && onChangeMonth?.(v)}
            sx={{ width: { xs: '100%', sm: 240 } }}
            slotProps={{
              textField: { size: 'small' },
              /* ▼ 追加：ポップアップのツールバーを「年 月」に */
              toolbar: { toolbarFormat: 'YYYY年 M月' },
              /* ▼ 追加：カレンダー上部の見出し（中央タイトル）も「年 月」に */
              calendarHeader: { format: 'YYYY年 MMMM' },
            }}
            /* 入力欄の表示も「年 月」に */
            format="YYYY年 M月"
            disabled={loading}
          />
        </LocalizationProvider>

        <Chip
          size={isXs ? 'small' : 'medium'}
          label={
            <Box
              component="span"
              sx={{
                display: 'inline-block',
                width: '100%',
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
              }}
              title={String(chipLabel || '')}
            >
              {labelText}
            </Box>
          }
          sx={{
            fontSize: isXs ? 13 : 16,
            height: isXs ? 30 : 36,
            width: { xs: '100%', sm: 'auto' },
            flexBasis: { xs: '100%', sm: 'auto' },
            flexShrink: 1,
            mt: { xs: 0.5, sm: 0 },
          }}
        />

        <Box sx={{ flex: 1, display: { xs: 'none', sm: 'block' } }} />

        <Button
          size="small"
          variant="contained"
          startIcon={<FileDownloadIcon />}
          onClick={onExportMyMonth}
          disabled={disableExportMy || loading}
          sx={{
            mx: { xs: 0, sm: 0.5 },
            mt: { xs: 0.5, sm: 0 },
            width: { xs: '100%', sm: 'auto' },
            minWidth: { xs: 44, sm: 64 },
            whiteSpace: 'nowrap',
          }}
        >
          当月のエクセル出力
        </Button>

        {isAdmin && (
          <Button
            size="small"
            variant="outlined"
            startIcon={<SummarizeIcon />}
            onClick={onExportSummary}
            disabled={loading}
            sx={{
              mx: { xs: 0, sm: 0.5 },
              mt: { xs: 0.5, sm: 0 },
              width: { xs: '100%', sm: 'auto' },
              minWidth: { xs: 44, sm: 64 },
              whiteSpace: 'nowrap',
            }}
          >
            当月集計をエクセル出力
          </Button>
        )}
      </Stack>
    </Box>
  );
};

export default ReportToolbar;
