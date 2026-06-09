// frontend/src/app/Report/utils/workTimeCalc.js

/**
 * 勤怠計算ユーティリティ（単一ソース）
 * - EditDialog / ReportTable で同じ関数を使う
 * - export を揃えて import error を潰す
 *
 * 残業仕様（今回確定）:
 * - 深夜開始(late_overtime_start)までを通常帯として扱う
 * - 通常残業 = max(
 *     (通常帯の合計実働 - 9時間),
 *     (overtime_start以降の通常帯合計実働) ※ただし「定時出勤」時のみ適用
 *   )
 * - 休憩時間の推定控除はしない（入力された区間＝実働として扱う）
 *
 * ★追加（仕様例に合わせる）
 * - overtime_start 以降を残業扱いにするのは「定時出勤（最初の出社が regular_start 以下）」のときだけ
 *   例: regular_start=09:00, overtime_start=17:30
 *     09:00-18:00 -> 0:30 残業（定時出勤なので overtime_start ルールが効く）
 *     10:00-19:00 -> 0 残業（定時出勤ではないので overtime_start ルールは無効、9h超もない）
 */

/** 半角数字以外を除去 */
export function onlyDigits(s) {
  return String(s ?? '').replace(/[^\d]/g, '');
}

/** "1000" → "1,000"（空文字はそのまま） */
export function withComma(s) {
  const d = onlyDigits(s);
  if (!d) return '';
  return d.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/** "1,000" → 1000（数値） */
export function parseAmountNumber(s) {
  const d = onlyDigits(s);
  return d ? Number(d) : 0;
}

/** 分 → "HH:mm"（0埋め、24h超も許容） */
export function minutesToHm(min) {
  const v = Math.max(0, Math.floor(min || 0));
  const h = Math.floor(v / 60);
  const m = v % 60;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

/**
 * EditDialog仕様の normalize（0〜29時を許容）
 * - "8:00" / "08:00" / "0800" / "800" を "HH:mm" に寄せる
 * - 不正は '' を返す
 */
export function normalizeTimeInput29(raw) {
  if (!raw && raw !== 0) return '';
  const s = String(raw).trim();

  // "H:MM" / "HH:MM"
  if (/^\d{1,2}:\d{2}$/.test(s)) {
    const [h, m] = s.split(':').map(Number);
    if (
      Number.isInteger(h) &&
      Number.isInteger(m) &&
      h >= 0 && h <= 29 &&
      m >= 0 && m <= 59
    ) {
      return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }
    return '';
  }

  // "800" / "0830" 等
  const d = s.replace(/\D+/g, '');
  if (!d) return '';

  if (d.length <= 2) {
    const h = parseInt(d, 10);
    if (Number.isNaN(h) || h > 29) return '';
    return String(h).padStart(2, '0') + ':00';
  }

  if (d.length === 3) {
    const h = parseInt(d.slice(0, 1), 10);
    const m = parseInt(d.slice(1), 10);
    if (Number.isNaN(h) || Number.isNaN(m) || h > 29 || m > 59) return '';
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
  }

  const h = parseInt(d.slice(0, 2), 10);
  const m = parseInt(d.slice(2, 4), 10);
  if (Number.isNaN(h) || Number.isNaN(m) || h > 29 || m > 59) return '';
  return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
}

/** "HH:mm" → 分（0〜29時許容） */
export function hmToMin29(s) {
  const n = normalizeTimeInput29(s);
  if (!n) return null;
  const [h, m] = n.split(':').map(Number);
  return h * 60 + m;
}

/**
 * シフト表記生成
 */
export function buildShiftLabel(shift) {
  if (!shift) return 'シフト未設定';
  const s = normalizeTimeInput29(String(shift.regular_start ?? '').slice(0, 5));
  const f = normalizeTimeInput29(String(shift.regular_finish ?? '').slice(0, 5));
  const lo = normalizeTimeInput29(String(shift.late_overtime_start ?? '').slice(0, 5));
  const segs = [];
  if (s) segs.push(`出社：${s}`);
  if (f) segs.push(`退社：${f}`);
  if (lo) segs.push(`深夜残業：${lo}～`);
  return segs.length ? segs.join('　') : 'シフト未設定';
}

/* =========================
 * 内部ユーティリティ
 * ========================= */

const THRESHOLD_MIN = 9 * 60; // 540

function toRange(startHm, finishHm) {
  const st = hmToMin29(startHm);
  let ft = hmToMin29(finishHm);
  if (st == null || ft == null) return null;

  // 跨日（退社が出社以下なら翌日扱い）
  if (ft <= st) ft += 1440;
  return { st, ft };
}

function overlapMin(aSt, aFt, bSt, bFt) {
  const s = Math.max(aSt, bSt);
  const e = Math.min(aFt, bFt);
  return Math.max(0, e - s);
}

/**
 * 複数区間（区間合算で計算してズレないようにする）
 * segments: [{startHm, finishHm}, ...] or [{startStr, finishStr}, ...]
 */
export function calcAutoTimesMulti(shift, segments) {
  if (!shift) {
    return { earlyMin: 0, overtimeMin: 0, midnightMin: 0, earlyHm: '', overtimeHm: '', midnightHm: '' };
  }

  const mReg  = shift?.regular_start ? hmToMin29(String(shift.regular_start).slice(0, 5)) : null;
  const mOT   = shift?.overtime_start ? hmToMin29(String(shift.overtime_start).slice(0, 5)) : null;
  const mLate = shift?.late_overtime_start ? hmToMin29(String(shift.late_overtime_start).slice(0, 5)) : null;

  const ranges = (segments || [])
    .map((seg) => {
      const startHm = seg?.startHm ?? seg?.startStr ?? '';
      const finishHm = seg?.finishHm ?? seg?.finishStr ?? '';
      if (!startHm || !finishHm) return null;
      return toRange(startHm, finishHm);
    })
    .filter(Boolean);

  if (ranges.length === 0) {
    return { earlyMin: 0, overtimeMin: 0, midnightMin: 0, earlyHm: '', overtimeHm: '', midnightHm: '' };
  }

  // ★ 最初の出社 / 最後の退社（跨日補正後の ranges から）
  const earliestStart = ranges.reduce((min, r) => Math.min(min, r.st), Number.POSITIVE_INFINITY);
  const latestFinish  = ranges.reduce((max, r) => Math.max(max, r.ft), 0);

  // ★「定時出勤」判定（最初の出社が regular_start 以下なら true）
  // mReg が取れない場合は従来通り overtime_start ルールを有効として扱う
  const isOnTimeStart = (mReg != null) ? (earliestStart <= mReg) : true;

  // 早出・深夜は「時間帯に属する実働」を合算
  let earlyMin = 0;
  let midnightMin = 0;

  for (const r of ranges) {
    if (mReg != null) {
      earlyMin += overlapMin(r.st, r.ft, -999999, mReg);
    }
    if (mLate != null) {
      midnightMin += overlapMin(r.st, r.ft, mLate, 999999);
    }
  }

  // =========================
  // 残業（ここが今回の肝）
  // =========================

  // ★ 通常帯の「拘束span」を作る（深夜開始まで）
  const normalEndOverall = (mLate != null) ? Math.min(latestFinish, mLate) : latestFinish;
  const spanBeforeLateMin = Math.max(0, normalEndOverall - earliestStart);

  // ★ 9時間超は「拘束span」で判定（区間の隙間があっても一致する）
  const overtimeBySpan = Math.max(0, spanBeforeLateMin - THRESHOLD_MIN);

  // overtime_start 以降の「通常帯実働」は区間の重なりで合算（隙間は数えない）
  let workAfterOTMin = 0;
  if (isOnTimeStart && mOT != null) {
    for (const r of ranges) {
      const normalEnd = (mLate != null) ? Math.min(r.ft, mLate) : r.ft;
      if (normalEnd > r.st) {
        workAfterOTMin += overlapMin(r.st, normalEnd, mOT, 999999);
      }
    }
  }

  const overtimeByShiftStart = Math.max(0, workAfterOTMin);

  // 残業 = max(拘束span-9h, overtime_start以降実働(定時出勤時のみ))
  const overtimeMin = Math.max(overtimeBySpan, overtimeByShiftStart);

  return {
    earlyMin,
    overtimeMin,
    midnightMin,
    earlyHm: earlyMin ? minutesToHm(earlyMin) : '',
    overtimeHm: overtimeMin ? minutesToHm(overtimeMin) : '',
    midnightHm: midnightMin ? minutesToHm(midnightMin) : '',
  };
}

/**
 * 単一区間
 * ※単一区間でも Multi に委譲して「単一/複数で計算が一致」するようにする
 */
export function calcAutoTimes(work, shift) {
  const startHm = work?.startHm ?? '';
  const finishHm = work?.finishHm ?? '';
  return calcAutoTimesMulti(shift, [{ startHm, finishHm }]);
}