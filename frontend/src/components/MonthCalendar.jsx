// frontend\src\components\MonthCalendar.jsx

import React from 'react';
import dayjs from 'dayjs';
import { useMediaQuery } from '@mui/material'; //  追加：画面幅で出し分け

/**
 * Props
 * - year: number
 * - month: number (1-12)
 * - items: { [YYYY-MM-DD]: 0|1|2 } // 0=出勤, 1=社内休日, 2=法定休日
 * - isAdmin: boolean
 * - onCellClick?: (dateStr: string) => void
 * - className?: string // 併用可
 * - holidaySet?: Set<string> // 追加：祝日(YYYY-MM-DD)の集合（表示用）
 *
 * デザイン:
 * - すべての見た目は SCSS（_calendar.scss）に移管
 * - 月→曜日→日付セル の順で表示
 * - 出勤(0)はラベル非表示、休日はテキストのみ（枠線/塗り潰しナシ）
 * - 狭幅では data-status に応じたドットバッジが出る（SCSSの ::after）
 */

const WEEK_LABELS = ['日', '月', '火', '水', '木', '金', '土'];

const MonthCalendar = ({
  year,
  month,
  items = {},
  isAdmin = false,
  onCellClick,
  className = '',
  holidaySet, // 追加
}) => {
  const first = dayjs(`${year}-${String(month).padStart(2, '0')}-01`);
  const startWeekday = first.day(); // 0(日) - 6(土)
  const daysInMonth = first.daysInMonth();

  //  追加：600px以下＝スマホ想定（MUI既定のxs目安）
  const isMobile = useMediaQuery('(max-width:600px)');

  // 42セル（6週 × 7日）分の配列を作る
  const cells = [];
  for (let i = 0; i < startWeekday; i++) {
    cells.push({ type: 'blank', key: `b-${i}` });
  }
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = dayjs(`${year}-${month}-${d}`).format('YYYY-MM-DD');
    const weekday = dayjs(dateStr).day();
    const status = Number.isInteger(items[dateStr]) ? items[dateStr] : 0; // 無ければ0=出勤
    cells.push({ type: 'day', key: dateStr, dateStr, dayNum: d, weekday, status });
  }
  while (cells.length < 42) {
    cells.push({ type: 'blank', key: `b-tail-${cells.length}` });
  }

  const emitClick = (dateStr) => {
    if (!isAdmin || !onCellClick) return;
    onCellClick(dateStr);
  };

  return (
    <div className={`calendar-month ${className}`}>
      {/* 月（年表記なし） */}
      <h3 className="calendar-title">{month}月</h3>

      <div className="calendar-table-container">
        <table className="calendar-table">
          <thead className="calendar-week-head">
            <tr>
              {WEEK_LABELS.map((w, i) => (
                <th
                  key={w}
                  className={`calendar-week-head-cell${i === 0 ? ' is-sun' : i === 6 ? ' is-sat' : ''}`}
                  scope="col"
                >
                  <p className="calendar-week-head-text">{w}</p>
                </th>
              ))}
            </tr>
          </thead>

          <tbody className="calendar-body">
            {
              // 7列ずつ切って 6 行に整形
              Array.from({ length: 6 }).map((_, rowIdx) => (
                <tr key={rowIdx} className="calendar-week-row">
                  {cells.slice(rowIdx * 7, rowIdx * 7 + 7).map((c) => {
                    if (c.type === 'blank') {
                      return <td key={c.key} className="calendar-cell"><div className="calendar-cell-inner" /></td>;
                    }

                    const isSun = c.weekday === 0;
                    const isSat = c.weekday === 6;

                    // 祝日判定（holidaySet が未指定なら false）
                    const isHoliday = !!holidaySet && typeof holidaySet.has === 'function' && holidaySet.has(c.dateStr);

                    // 日付（数字）だけ赤にするためのクラスを追加
                    // 既存の is-sun/is-sat は残しつつ、祝日優先にしたいので is-holiday を付ける
                    const dayNumClass =
                      'calendar-day-number'
                      + (isSun ? ' is-sun' : isSat ? ' is-sat' : '')
                      + (isHoliday ? ' is-holiday' : '');

                    // 出勤(0)はラベル非表示
                    let label = null;
                    if (c.status === 1) {
                      // 社内休日：スマホ=「社」 / PC=「社内休日」
                      label = isMobile ? (
                        <span
                          className="calendar-label calendar-label--company"
                          title="社内休日"
                          aria-label="社内休日"
                        >
                          社
                        </span>
                      ) : (
                        <span className="calendar-label calendar-label--company">社内休日</span>
                      );
                    } else if (c.status === 2) {
                      // 法定休日：スマホ=「法」 / PC=「法定休日」
                      label = isMobile ? (
                        <span
                          className="calendar-label calendar-label--statutory"
                          title="法定休日"
                          aria-label="法定休日"
                        >
                          法
                        </span>
                      ) : (
                        <span className="calendar-label calendar-label--statutory">法定休日</span>
                      );
                    }

                    return (
                      <td key={c.key} className="calendar-cell">
                        <div
                          className={`calendar-cell-inner${isAdmin ? ' is-admin' : ''}`}
                          data-status={c.status} // 狭幅時ドット表示に使用
                          onClick={() => emitClick(c.dateStr)}
                        >
                          <p className={dayNumClass} title={isHoliday ? '祝日' : undefined}>
                            {c.dayNum}
                          </p>
                          {label}
                        </div>
                      </td>
                    );
                  })}
                </tr>
              ))
            }
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default MonthCalendar;
