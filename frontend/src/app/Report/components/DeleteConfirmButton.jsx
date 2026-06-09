import React from 'react';
import { IconButton, Tooltip } from '@mui/material';
import DeleteIcon from '@mui/icons-material/Delete';

/**
 * 汎用の削除ボタン（確認ダイアログ付き）
 *
 * Props:
 * - onConfirm: () => Promise<void> | void   クリック後、確認OKなら呼ばれる
 * - disabled: boolean                       無効化フラグ
 * - tooltipWhenEnabled: string              有効時のツールチップ（例: '削除'）
 * - tooltipWhenDisabled: string             無効時のツールチップ（例: '登録が無いため削除不可'）
 * - confirmMessage: string                  window.confirm のメッセージ
 * - color: 'error' | 'primary' | ...        MUI IconButton color（既定: 'error'）
 * - size: 'small' | 'medium' | 'large'      既定: 'small'
 * - sx: any                                 追加スタイル
 */
export default function DeleteConfirmButton({
  onConfirm,
  disabled = false,
  tooltipWhenEnabled = '削除',
  tooltipWhenDisabled = '削除不可',
  confirmMessage = '削除します。よろしいですか？',
  color = 'error',
  size = 'small',
  sx,
}) {
  const handleClick = async () => {
    if (disabled) return;
    const ok = window.confirm(confirmMessage);
    if (!ok) return;
    await Promise.resolve(onConfirm?.());
  };

  return (
    <Tooltip title={disabled ? tooltipWhenDisabled : tooltipWhenEnabled}>
      <span>
        <IconButton
          size={size}
          color={color}
          onClick={handleClick}
          disabled={disabled}
          sx={sx}
        >
          <DeleteIcon fontSize={size === 'small' ? 'small' : undefined} />
        </IconButton>
      </span>
    </Tooltip>
  );
}
