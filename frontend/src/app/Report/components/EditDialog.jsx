// frontend/src/app/Report/components/EditDialog.jsx

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Dialog, DialogTitle, DialogContent, DialogActions,
  Button, Stack, Divider,
} from '@mui/material';
import axios from 'axios';
import { useTheme } from '@mui/material/styles';
import useMediaQuery from '@mui/material/useMediaQuery';
import WorkTypeControls from './WorkTypeControls';
import SegmentFields from './SegmentFields';
import AutoTimeFields from './AutoTimeFields';
import HealthCheckControls from './HealthCheckControls';
import VehicleSelect from './VehicleSelect';
import ReimburseFields from './ReimburseFields';

// 計算/正規化/金額系はすべて utils に統一
import {
  onlyDigits,
  withComma,
  parseAmountNumber,
  normalizeTimeInput29,
  calcAutoTimes,
  calcAutoTimesMulti,
} from '../utils/workTimeCalc';

/* ================== 本体 ================== */
const EditDialog = ({ open, onClose, target, apiBase, shift, workDateStr, userId, onSaved }) => {
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('sm'));

  const [sites, setSites] = useState([]);
  const [vehicles, setVehicles] = useState([]);
  const [payments, setPayments] = useState([]);

  const [isCanceled, setIsCanceled] = useState(0);

  // 夜勤
  const [isNightShift, setIsNightShift] = useState(false);

  // ===== 区間1 =====
  const [start, setStart] = useState('');
  const [finish, setFinish] = useState('');
  const [onSiteId, setOnSiteId] = useState(null);
  const [work, setWork] = useState('');

  // ===== 区間2 =====
  const [start2, setStart2] = useState('');
  const [finish2, setFinish2] = useState('');
  const [onSiteId2, setOnSiteId2] = useState(null);
  const [work2, setWork2] = useState('');

  const [vehicleId, setVehicleId] = useState(null);
  const [alcoholChecked, setAlcoholChecked] = useState(false);
  const [conditionChecked, setConditionChecked] = useState(false);

  const [paymentIds, setPaymentIds] = useState([null, null, null, null, null]);
  const [amountStrs, setAmountStrs] = useState(['', '', '', '', '']);

  // IDを安全に number/null に正規化（'' / '0' / 0 / NaN は null 扱い）
  const normId = useCallback((v) => {
    if (v === '' || v === null || v === undefined) return null;
    const n = Number(v);
    if (!Number.isFinite(n) || n <= 0) return null;
    return n;
  }, []);

  const loadMasters = useCallback(async () => {
    try {
      const [sRes, vRes, pRes] = await Promise.all([
        axios.get(`${apiBase}/m_on_sites/select.php`, { withCredentials: true }),
        axios.get(`${apiBase}/m_vehicles/select.php`, { withCredentials: true }),
        axios.get(`${apiBase}/m_payments/select.php`, { withCredentials: true }),
      ]);
      const s = Array.isArray(sRes?.data) ? sRes.data : [];
      const v = Array.isArray(vRes?.data) ? vRes.data : [];
      const p = Array.isArray(pRes?.data) ? pRes.data : [];
      setSites(s.map(x => ({ id: Number(x.id), name: String(x.name ?? '') })));
      setVehicles(v.map(x => ({ id: Number(x.id), number: String(x.number ?? x.nuber ?? x.code ?? '') })));
      setPayments(p.map(x => ({ id: Number(x.id), name: String(x.name ?? '') })));
    } catch {
      setSites([]);
      setVehicles([]);
      setPayments([]);
    }
  }, [apiBase]);

  const initFromTarget = useCallback(() => {
    setIsCanceled(Number(target?.is_canceled ?? 0));

    setIsNightShift(Number(target?.is_night_shift ?? 0) === 1);

    // 区間1
    setStart(target?.start || target?.start_time || '');
    setFinish(target?.leave || target?.finish_time || '');
    setOnSiteId(normId(target?.on_site_id));
    setWork(target?.work || '');

    // 区間2（区間2が「本当にある」ときだけ営業所2を採用）
    const tStart2 = target?.start2 || target?.start_time2 || '';
    const tFinish2 = target?.leave2 || target?.finish_time2 || '';
    const tWork2 = target?.work2 || '';
    const tOnSiteId2Raw = target?.on_site_id2 ?? null;

    // 区間2の存在判定：時刻/現場/営業所2のいずれかが入っている場合のみ
    const seg2HasAny =
      !!tStart2 ||
      !!tFinish2 ||
      !!tWork2 ||
      (tOnSiteId2Raw !== null && tOnSiteId2Raw !== '' && Number(tOnSiteId2Raw) > 0);

    setStart2(tStart2);
    setFinish2(tFinish2);
    setOnSiteId2(seg2HasAny ? normId(tOnSiteId2Raw) : null);
    setWork2(tWork2);

    setVehicleId(normId(target?.vehicle_id));
    setAlcoholChecked(!!target?.alcohol_checked || target?.alcohol === 'OK');
    setConditionChecked(!!target?.condition_checked || target?.condition === 'OK');

    setPaymentIds(
      [
        target?.payment1_id ?? null,
        target?.payment2_id ?? null,
        target?.payment3_id ?? null,
        target?.payment4_id ?? null,
        target?.payment5_id ?? null,
      ].map(v => normId(v))
    );

    // 金額は utils の withComma に統一
    setAmountStrs(
      [target?.amount1 ?? null, target?.amount2 ?? null, target?.amount3 ?? null, target?.amount4 ?? null, target?.amount5 ?? null]
        .map(v => (v == null ? '' : withComma(String(v))))
    );
  }, [target, normId]);

  useEffect(() => {
    if (open) {
      loadMasters();
      initFromTarget();
    }
  }, [open, loadMasters, initFromTarget]);

  // 閉じた時に state を確実にクリア（再利用で前回値が残る事故を防ぐ）
  useEffect(() => {
    if (open) return;

    setIsCanceled(0);
    setIsNightShift(false);

    setStart('');
    setFinish('');
    setOnSiteId(null);
    setWork('');

    setStart2('');
    setFinish2('');
    setOnSiteId2(null);
    setWork2('');

    setVehicleId(null);
    setAlcoholChecked(false);
    setConditionChecked(false);

    setPaymentIds([null, null, null, null, null]);
    setAmountStrs(['', '', '', '', '']);
  }, [open]);

  // 区間2が「有効」か判定
  const hasSegment2 = useMemo(() => {
    return !!(onSiteId2 != null || start2 || finish2 || work2);
  }, [onSiteId2, start2, finish2, work2]);

  // 自動計算：workTimeCalc.js に完全統一
  const auto = useMemo(() => {
    if (isCanceled) return { earlyHm: '', overtimeHm: '', midnightHm: '' };

    // 引数順：calcAutoTimesMulti(shift, segments)
    if (hasSegment2) {
      return calcAutoTimesMulti(shift, [
        { startHm: start, finishHm: finish },
        { startHm: start2, finishHm: finish2 },
      ]);
    }

    return calcAutoTimes({ startHm: start, finishHm: finish }, shift);
  }, [shift, start, finish, start2, finish2, isCanceled, hasSegment2]);

  const handleWorkTypeChange = (value) => {
    const nextType = Number(value) || 0;
    setIsCanceled(nextType);
    if (nextType) {
      // 区間1
      setStart('');
      setFinish('');
      setOnSiteId(null);
      setWork('');

      // 区間2
      setStart2('');
      setFinish2('');
      setOnSiteId2(null);
      setWork2('');

      // その他
      setVehicleId(null);
      setAlcoholChecked(false);
      setConditionChecked(false);
      setPaymentIds([null, null, null, null, null]);
      setAmountStrs(['', '', '', '', '']);
      setIsNightShift(false);
    }
  };

  // 時刻入力：入力中は数字だけ、blur で normalizeTimeInput29 に統一
  const handleTimeBlur = (setter) => (e) => setter(normalizeTimeInput29(e.target.value));
  const handleTimeChange = (setter) => (e) => setter(onlyDigits(e.target.value));

  // 金額：表示は withComma、保存は parseAmountNumber
  const handleAmountChange = (idx) => (e) => {
    if (paymentIds[idx] == null) return;
    const digits = onlyDigits(e.target.value);
    setAmountStrs(prev => {
      const next = [...prev];
      next[idx] = withComma(digits);
      return next;
    });
  };
  const handlePaymentChange = (idx) => (e) => {
    const v = e.target.value === '' ? null : Number(e.target.value);
    setPaymentIds(prev => {
      const next = [...prev];
      next[idx] = v;
      return next;
    });
    if (v == null) {
      setAmountStrs(prev => {
        const next = [...prev];
        next[idx] = '';
        return next;
      });
    }
  };
  const parseAmountToIntOrNull = (v) => {
    if (v === '' || v == null) return null;
    const n = parseAmountNumber(v);
    return Number.isFinite(n) ? n : null;
  };

  const handleSave = async () => {
    try {
      const hasId = target?.id != null && target?.id !== '' && Number(target.id) > 0;

      const payload = {
        ...(hasId ? { id: Number(target.id) } : {
          user_id: Number(userId),
          work_date: workDateStr,
        }),

        // ===== 区間1 =====
        start_time: isCanceled ? null : (start ? normalizeTimeInput29(start) : null),
        finish_time: isCanceled ? null : (finish ? normalizeTimeInput29(finish) : null),
        on_site_id: isCanceled ? null : (onSiteId == null ? null : Number(onSiteId)),
        work: isCanceled ? '' : (work || ''),

        // ===== 区間2 =====
        start_time2: isCanceled ? null : (start2 ? normalizeTimeInput29(start2) : null),
        finish_time2: isCanceled ? null : (finish2 ? normalizeTimeInput29(finish2) : null),
        on_site_id2: isCanceled ? null : (onSiteId2 == null ? null : Number(onSiteId2)),
        work2: isCanceled ? '' : (work2 || ''),

        // ===== flags =====
        is_canceled: Number(isCanceled) || 0,
        is_night_shift: isNightShift ? 1 : 0,

        alcohol_checked: isCanceled ? 0 : (alcoholChecked ? 1 : 0),
        condition_checked: isCanceled ? 0 : (conditionChecked ? 1 : 0),

        // ===== 車両 =====
        vehicle_id: isCanceled ? null : (vehicleId == null ? null : Number(vehicleId)),

        // ===== 立替 =====
        payment1_id: isCanceled ? null : (paymentIds[0] == null ? null : Number(paymentIds[0])),
        amount1: isCanceled ? null : parseAmountToIntOrNull(amountStrs[0]),
        payment2_id: isCanceled ? null : (paymentIds[1] == null ? null : Number(paymentIds[1])),
        amount2: isCanceled ? null : parseAmountToIntOrNull(amountStrs[1]),
        payment3_id: isCanceled ? null : (paymentIds[2] == null ? null : Number(paymentIds[2])),
        amount3: isCanceled ? null : parseAmountToIntOrNull(amountStrs[2]),
        payment4_id: isCanceled ? null : (paymentIds[3] == null ? null : Number(paymentIds[3])),
        amount4: isCanceled ? null : parseAmountToIntOrNull(amountStrs[3]),
        payment5_id: isCanceled ? null : (paymentIds[4] == null ? null : Number(paymentIds[4])),
        amount5: isCanceled ? null : parseAmountToIntOrNull(amountStrs[4]),
      };

      const url = hasId
        ? `${apiBase}/t_work_reports/update.php`
        : `${apiBase}/t_work_reports/insert.php`;

      const res = await axios.post(url, payload, {
        withCredentials: true,
        headers: { 'Content-Type': 'application/json' },
      });

      if (res?.data?.success === false) {
        throw new Error(res?.data?.message || '保存に失敗しました。');
      }

      onSaved && onSaved();
      onClose && onClose();
    } catch (e) {
      console.error(e);
      alert('保存に失敗しました。');
    }
  };

  const disabledByCancel = !!isCanceled;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="lg" fullWidth>
      <DialogTitle
        sx={{
          whiteSpace: 'nowrap',
          overflow: 'hidden',
          textOverflow: 'ellipsis',
          fontSize: { xs: '0.95rem', sm: '1.25rem' },
          lineHeight: 1.2,
        }}
      >
        日報編集（{workDateStr}）
      </DialogTitle>

      <DialogContent dividers>
        <Stack spacing={2}>

          <WorkTypeControls
            workType={isCanceled}
            onWorkTypeChange={handleWorkTypeChange}
            isNightShift={isNightShift}
            onNightShiftChange={setIsNightShift}
          />

          <SegmentFields
            title="区間1"
            start={start}
            finish={finish}
            onSiteId={onSiteId}
            work={work}
            sites={sites}
            disabled={disabledByCancel}
            onStartChange={handleTimeChange(setStart)}
            onStartBlur={handleTimeBlur(setStart)}
            onFinishChange={handleTimeChange(setFinish)}
            onFinishBlur={handleTimeBlur(setFinish)}
            onSiteChange={(e) => setOnSiteId(e.target.value === '' ? null : Number(e.target.value))}
            onWorkChange={(e) => setWork(e.target.value)}
            startPlaceholder="0900"
            finishPlaceholder="1200"
          />

          <SegmentFields
            title="区間2（午後など）"
            suffix="2"
            start={start2}
            finish={finish2}
            onSiteId={onSiteId2}
            work={work2}
            sites={sites}
            disabled={disabledByCancel}
            onStartChange={handleTimeChange(setStart2)}
            onStartBlur={handleTimeBlur(setStart2)}
            onFinishChange={handleTimeChange(setFinish2)}
            onFinishBlur={handleTimeBlur(setFinish2)}
            onSiteChange={(e) => setOnSiteId2(e.target.value === '' ? null : Number(e.target.value))}
            onWorkChange={(e) => setWork2(e.target.value)}
            startPlaceholder="1300"
            finishPlaceholder="1700"
          />

          <AutoTimeFields auto={auto} hasSegment2={hasSegment2} />

          <HealthCheckControls
            isMobile={isMobile}
            disabled={disabledByCancel}
            alcoholChecked={alcoholChecked}
            conditionChecked={conditionChecked}
            onAlcoholChange={(e) => setAlcoholChecked(e.target.checked)}
            onConditionChange={(e) => setConditionChecked(e.target.checked)}
          />

          <Divider />

          <VehicleSelect
            vehicleId={vehicleId}
            vehicles={vehicles}
            disabled={disabledByCancel}
            onChange={(e) => setVehicleId(e.target.value === '' ? null : Number(e.target.value))}
          />

          <ReimburseFields
            paymentIds={paymentIds}
            amountStrs={amountStrs}
            payments={payments}
            disabled={disabledByCancel}
            onPaymentChange={handlePaymentChange}
            onAmountChange={handleAmountChange}
          />
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} variant="text">キャンセル</Button>
        <Button onClick={handleSave} variant="contained" color="primary">保存</Button>
      </DialogActions>
    </Dialog>
  );
};

export default EditDialog;
