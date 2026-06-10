// frontend/src/app/Report/components/ReportTable.jsx

import React from 'react';
import {
  TableContainer, Table, TableHead, TableRow, TableCell, TableBody, Paper, IconButton, CircularProgress, Tooltip,
} from '@mui/material';
import { useTheme } from '@mui/material/styles';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';

// 夜勤チェック表示用
import CheckIcon from '@mui/icons-material/Check';

// ★ 一覧でも EditDialog と同じ計算関数を使う（同一ソースに統一）
import { calcAutoTimes, calcAutoTimesMulti } from '../utils/workTimeCalc';

/**
 * 日報テーブル（横スクロール前提）
 */
const ReportTable = ({
  rows = [],
  loading = false,
  isAdmin = false, // eslint-disable-line no-unused-vars
  onEdit,
  onDelete,
  getRowMeta,
}) => {
  const theme = useTheme();

  // パレット名（"primary.light" など）→ 実カラーコード（# / rgba）へ解決
  const resolveColor = (val) => {
    if (!val) return undefined;
    if (typeof val !== 'string') return val;

    const [name, shade] = val.split('.');
    const group = theme?.palette?.[name];
    if (group) {
      const key = shade || 'main';
      if (group[key]) return group[key];
    }
    return val;
  };

  const isNightShiftOn = (row) => {
    return Number(row?.is_night_shift ?? 0) === 1;
  };

  const columns = [
    { key: 'edit', header: '操作', className: 'report-col--edit' },
    { key: 'date', header: '日', className: 'report-col--date' },
    { key: 'day', header: '曜', className: 'report-col--dow' },
    { key: 'start', header: '出社', className: 'report-col--time' },
    { key: 'leave', header: '退社', className: 'report-col--time' },
    { key: 'early', header: '早出', className: 'report-col--time' },
    { key: 'overtime', header: '残業', className: 'report-col--time' },
    { key: 'midnight', header: '深夜', className: 'report-col--time' },

    // 夜勤チェック列
    { key: 'night', header: '夜勤', className: 'report-col--night' },

    { key: 'site', header: '営業所', className: 'report-col--site' },
  { key: 'work', header: '現場名', className: 'report-col--work' },
    { key: 'alcohol', header: '酒気帯', className: 'report-col--time' },
    { key: 'condition', header: '体調', className: 'report-col--time' },
    { key: 'vehicle', header: '車両', className: 'report-col--vehicle' },
    { key: 'reimburse1', header: '立替1', className: 'report-col--reimburse' },
    { key: 'amount1', header: '金額1', className: 'report-col--amount' },
    { key: 'reimburse2', header: '立替2', className: 'report-col--reimburse' },
    { key: 'amount2', header: '金額2', className: 'report-col--amount' },
    { key: 'reimburse3', header: '立替3', className: 'report-col--reimburse' },
    { key: 'amount3', header: '金額3', className: 'report-col--amount' },
    { key: 'reimburse4', header: '立替4', className: 'report-col--reimburse' },
    { key: 'amount4', header: '金額4', className: 'report-col--amount' },
    { key: 'reimburse5', header: '立替5', className: 'report-col--reimburse' },
    { key: 'amount5', header: '金額5', className: 'report-col--amount' },
  ];

  // stickyセル専用：透け防止の背景（白＋行色の二重レイヤー）
  const stickySolidBg = {
    background:
      'linear-gradient(0deg, var(--row-bg, #fff), var(--row-bg, #fff)), #fff',
  };

  // 2段表示用：\n を改行として扱う（営業所/現場/時刻 など）
  const multiLineCellStyle = {
    whiteSpace: 'pre-line',
    overflowWrap: 'anywhere',
    lineHeight: 1.2,
  };

  // 2段表示のための合成（既に \n が入っていればそのまま）
  const join2Lines = (a, b) => {
    const s1 = (a ?? '') === null ? '' : String(a ?? '');
    const s2 = (b ?? '') === null ? '' : String(b ?? '');
    if (!s2) return s1;
    if (!s1) return s2;
    if (s1.includes('\n')) return s1; // 既に2段になっている（後方互換）
    return `${s1}\n${s2}`;
  };

  /**
   * 一覧表示用：早出/残業/深夜の表示値を決める（区間2は calcAutoTimesMulti で統一）
   * - shift が取れない場合は row.early 等にフォールバック（後方互換）
   */
  const getAutoTimeHms = (row, meta) => {
    const shift = (meta && meta.shift) ? meta.shift : (row && row.shift) ? row.shift : null;

    const s1 = row?.start || '';
    const e1 = row?.leave || '';

    const s2 = row?.start2 || row?.start_time2 || '';
    const e2 = row?.leave2 || row?.finish_time2 || '';

    // shift + 区間1が揃ってなければ従来表示へ
    if (!shift || !s1 || !e1) {
      return {
        early: row?.early || '',
        overtime: row?.overtime || '',
        midnight: row?.midnight || '',
      };
    }

    // 区間2が有効なら「同一ロジック」で一括計算（合算手計算はしない）
    if (s2 && e2) {
      const r = calcAutoTimesMulti(shift, [
        { startHm: s1, finishHm: e1 },
        { startHm: s2, finishHm: e2 },
      ]);
      return {
        early: r?.earlyHm || '',
        overtime: r?.overtimeHm || '',
        midnight: r?.midnightHm || '',
      };
    }

    // 区間1のみ
    const r = calcAutoTimes({ startHm: s1, finishHm: e1 }, shift);
    return {
      early: r?.earlyHm || '',
      overtime: r?.overtimeHm || '',
      midnight: r?.midnightHm || '',
    };
  };

  return (
    <TableContainer component={Paper} className="report-table-container">
      <Table size="small" stickyHeader className="report-table">
        <colgroup>{columns.map((c) => <col key={c.key} />)}</colgroup>

        <TableHead>
          <TableRow>
            {columns.map((c) => (
              <TableCell key={c.key} className={c.className}>{c.header}</TableCell>
            ))}
          </TableRow>
        </TableHead>

        <TableBody>
          {loading && (
            <TableRow>
              <TableCell colSpan={columns.length} align="center">
                <CircularProgress size={20} />
              </TableCell>
            </TableRow>
          )}

          {!loading && rows.length === 0 && (
            <TableRow>
              <TableCell colSpan={columns.length} align="center">
                データがありません
              </TableCell>
            </TableRow>
          )}

          {!loading && rows.map((row) => {
            const meta = (typeof getRowMeta === 'function') ? getRowMeta(row) : { disabled: false };
            const disabled = !!(meta && meta.disabled);

            // 'info.light' 等を「実色」に解決して、行全体の塗りにも使う
            const resolvedBg = resolveColor(meta && meta.bgcolor);

            // stickyセル用（css変数）
            const rowStyle = resolvedBg ? { '--row-bg': resolvedBg } : undefined;

            // 早出/残業/深夜：都度計算して表示（区間2は calcAutoTimesMulti）
            const autoTimes = getAutoTimeHms(row, meta);

            // 夜勤チェック
            const nightOn = isNightShiftOn(row);

            // 2段表示（時刻/営業所/現場）
            const startText = join2Lines(row?.start || '', row?.start2 || row?.start_time2 || '');
            const leaveText = join2Lines(row?.leave || '', row?.leave2 || row?.finish_time2 || '');

            const siteText = join2Lines(
              row?.site || '',
              row?.site2 || row?.site_2 || '' // 念のため
            );

            const work1 = (meta && meta.workLabel) ? meta.workLabel : (row?.work || '');
            const workText = join2Lines(
              work1,
              row?.work2 || row?.work_2 || '' // 念のため
            );

            return (
              <TableRow
                key={row.work_date}
                sx={{ bgcolor: resolvedBg || undefined }}
                style={rowStyle}
              >
                <TableCell className="report-col--edit">
                  <div className="report-actions">
                    <Tooltip title="編集">
                      <span>
                        <IconButton size="small" onClick={() => onEdit && onEdit(row)} disabled={disabled} aria-label="編集">
                          <EditIcon fontSize="small" />
                        </IconButton>
                      </span>
                    </Tooltip>

                    <Tooltip title="削除">
                      <span>
                        <IconButton
                          size="small"
                          color="error"
                          onClick={() => onDelete && onDelete(row)}
                          disabled={disabled}
                          aria-label="削除"
                        >
                          <DeleteIcon fontSize="small" />
                        </IconButton>
                      </span>
                    </Tooltip>
                  </div>
                </TableCell>

                {/* 固定セル：透け防止＋行色をそのまま表示 */}
                <TableCell className="report-col--date report-td--right" style={stickySolidBg}>
                  {row.date}
                </TableCell>
                <TableCell className="report-col--dow" style={stickySolidBg}>
                  {row.day}
                </TableCell>

                {/* 時刻も2段表示対応 */}
                <TableCell className="report-col--time">
                  <div style={multiLineCellStyle}>{startText}</div>
                </TableCell>
                <TableCell className="report-col--time">
                  <div style={multiLineCellStyle}>{leaveText}</div>
                </TableCell>

                {/* 自動計算 */}
                <TableCell className="report-col--time">{autoTimes.early}</TableCell>
                <TableCell className="report-col--time">{autoTimes.overtime}</TableCell>
                <TableCell className="report-col--time">{autoTimes.midnight}</TableCell>

                {/* 夜勤チェック表示 */}
                <TableCell className="report-col--night" align="center">
                  {nightOn ? (
                    <Tooltip title="夜勤">
                      <span aria-label="夜勤">
                        <CheckIcon fontSize="small" />
                      </span>
                    </Tooltip>
                  ) : (
                    ''
                  )}
                </TableCell>

                {/* 営業所：2段表示 */}
                <TableCell className="report-col--site">
                  <div className="report-cell--scroll" style={multiLineCellStyle}>
                    {siteText}
                  </div>
                </TableCell>

                {/* 現場：2段表示 */}
                <TableCell className="report-col--work">
                  <div className="report-cell--scroll" style={multiLineCellStyle}>
                    {workText}
                  </div>
                </TableCell>

                <TableCell className="report-col--time">{row.alcohol || ''}</TableCell>
                <TableCell className="report-col--time">{row.condition || ''}</TableCell>

                <TableCell className="report-col--vehicle">
                  <div className="report-plate">{row.vehicle || ''}</div>
                </TableCell>

                <TableCell className="report-col--reimburse"><div className="report-cell--scroll">{row.reimburse1 || ''}</div></TableCell>
                <TableCell className="report-col--amount">{row.amount1 || ''}</TableCell>
                <TableCell className="report-col--reimburse"><div className="report-cell--scroll">{row.reimburse2 || ''}</div></TableCell>
                <TableCell className="report-col--amount">{row.amount2 || ''}</TableCell>
                <TableCell className="report-col--reimburse"><div className="report-cell--scroll">{row.reimburse3 || ''}</div></TableCell>
                <TableCell className="report-col--amount">{row.amount3 || ''}</TableCell>
                <TableCell className="report-col--reimburse"><div className="report-cell--scroll">{row.reimburse4 || ''}</div></TableCell>
                <TableCell className="report-col--amount">{row.amount4 || ''}</TableCell>
                <TableCell className="report-col--reimburse"><div className="report-cell--scroll">{row.reimburse5 || ''}</div></TableCell>
                <TableCell className="report-col--amount">{row.amount5 || ''}</TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </TableContainer>
  );
};

export default ReportTable;
