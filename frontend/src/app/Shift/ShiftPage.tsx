//import React, { useState, useEffect } from 'react';             // React
import React, { useEffect } from 'react';                       // React
import { Paper, Button, TextField } from '@mui/material';       // Material UI
import {
  GridRowsProp,
  GridRowModesModel,
  GridRowModes,
  DataGrid,
  GridColDef,
  GridRenderEditCellParams,
  GridRowModel,
  GridEventListener,
  GridToolbarContainer,
  GridActionsCellItem,
  GridRowEditStopReasons,
  GridRowId,
  GridSlotProps
} from '@mui/x-data-grid';                                      // Material UI
import { randomId } from '@mui/x-data-grid-generator';          // Material UI
import AddIcon from '@mui/icons-material/Add';                  // Material UI アイコン
import EditIcon from '@mui/icons-material/Edit';                // Material UI アイコン
import DeleteIcon from '@mui/icons-material/DeleteOutlined';    // Material UI アイコン
import SaveIcon from '@mui/icons-material/Save';                // Material UI アイコン
import CancelIcon from '@mui/icons-material/Close';             // Material UI アイコン
import FileDownloadIcon from '@mui/icons-material/FileDownload';// 追加：XLS出力アイコン
import axios from 'axios';                                      // HTTPリクエスト

/**
 * === APIベースURLの決定ロジック ===
 * 1) .env を最優先（REACT_APP_API_BASE）
 * 2) .env 未設定時のフォールバック
 *    - localhost系 → http://localhost/Report/backend
 *    - それ以外    → {現在のホスト}/report/backend  （本番 sugie-k.com を想定）
 * ※ 末尾スラッシュは除去（URL結合時の // 防止）
 */
const BASE_ADDR = (
  process.env.REACT_APP_API_BASE ||
  (typeof window !== 'undefined' && (
    window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1'
  )
    ? 'http://localhost/Report/backend'
    : `${window.location.origin}/report/backend`)
).replace(/\/+$/, '');

// 初期表示項目(ページ/項目数)
const paginationModel = { page: 1, pageSize: 10 };

// TypeScript用設定：ツールバーに baseAddr を渡せるよう拡張
declare module '@mui/x-data-grid' {
  interface ToolbarPropsOverrides {
    setRows: (newRows: (oldRows: GridRowsProp) => GridRowsProp) => void;
    setRowModesModel: (
      newModel: (oldModel: GridRowModesModel) => GridRowModesModel,
    ) => void;
    baseAddr: string;
  }
}

// ツールバー
const EditToolbar = (props: GridSlotProps['toolbar']) => {
  const { setRows, setRowModesModel, baseAddr } = props;

  const handleClickAdd = () => {
    const id = randomId();
    setRows((oldRows) => [
      ...oldRows,
      { id, regular_start: '', regular_finish: '', overtime_start: '', late_overtime_start: '', isNew: true },
    ]);
    setRowModesModel((oldModel) => ({
      ...oldModel,
      [id]: { mode: GridRowModes.Edit, fieldToFocus: 'regular_start' },
    }));
  };

  // エクセル出力
  const handleExportXls = () => {
    const url = `${baseAddr}/m_shifts/export_xls.php`;
    window.open(url, '_blank', 'noopener,noreferrer');
  };

  return (
    <GridToolbarContainer>
      {/* エクセル出力（統一デザイン） */}
      <Button
        size="small"
        variant="contained"
        startIcon={<FileDownloadIcon />}
        onClick={handleExportXls}
        sx={{ mr: 1 }}
      >
        エクセル出力
      </Button>

      {/* 追加 */}
      <Button color="primary" startIcon={<AddIcon />} onClick={handleClickAdd}>
        追加
      </Button>
    </GridToolbarContainer>
  );
};

// 初期値
const initialRows: GridRowsProp = [];

// タイムピッカー
const TimePickerCell = (params: GridRenderEditCellParams) => {
  const { id, field, value, api } = params;

  const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    api.setEditCellValue({ id, field, value: event.target.value });
  };

  return (
    <TextField
      type="time"
      value={value || ""}
      onChange={handleChange}
    />
  );
};

// シフトマスタページ
const ShiftPage = () => {
  const [rows, setRows] = React.useState<GridRowsProp>(initialRows);
  const [rowModesModel, setRowModesModel] = React.useState<GridRowModesModel>({});

  // DBデータの取得
  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await axios.get<GridRowsProp>(`${BASE_ADDR}/m_shifts/select.php`, {
          headers: { 'Accept': 'application/json' },
        });
        setRows(response.data);
      } catch (err: any) {
        if (err.response) {
          alert(`一覧取得に失敗しました (HTTP ${err.response.status})。バックエンドのログ(PHP)も確認してください。`);
        } else if (err.request) {
          alert('一覧取得に失敗しました。サーバ到達不可の可能性（URL/ポート、CORS、Apache起動状態）を確認してください。');
        } else {
          alert(`一覧取得に失敗しました: ${err.message}`);
        }
      }
    };

    fetchData();
  }, []);

  // 編集モード中に別操作の実施イベント
  const handleRowEditStop: GridEventListener<'rowEditStop'> = (params, event) => {
    if (params.reason === GridRowEditStopReasons.rowFocusOut) {
      event.defaultMuiPrevented = true;
    }
  };

  // 編集ボタンクリックイベント
  const handleEditClick = (id: GridRowId) => () => {
    setRowModesModel({ ...rowModesModel, [id]: { mode: GridRowModes.Edit } });
  };

  // 保存ボタンクリックイベント
  const handleSaveClick = (id: GridRowId) => () => {
    setRowModesModel({ ...rowModesModel, [id]: { mode: GridRowModes.View } });
  };

  // 削除ボタンクリックイベント
  const handleDeleteClick = (id: GridRowId) => () => {
    setRows(rows.filter((row) => row.id !== id));

    // IDが数値の場合のみAPI呼び出し（新規の一時IDは文字列）
    if (typeof id === 'number') {
      axios.post(`${BASE_ADDR}/m_shifts/delete.php`, { id }).catch(() => {
        alert('削除APIでエラーが発生しました。');
      });
    }
  };

  // 編集キャンセルボタンクリックイベント
  const handleCancelClick = (id: GridRowId) => () => {
    setRowModesModel({
      ...rowModesModel,
      [id]: { mode: GridRowModes.View, ignoreModifications: true },
    });

    const editedRow = rows.find((row) => row.id === id);
    if (editedRow && (editedRow as any).isNew) {
      setRows(rows.filter((row) => row.id !== id));
    }
  };

  // 編集完了イベント
  const processRowUpdate = async (newRow: GridRowModel) => {
    const updatedRow = { ...newRow, isNew: false };
    setRows(rows.map((row) => (row.id === newRow.id ? updatedRow : row)));

    // 未入力項目あり
    if (
      newRow.regular_start === '' ||
      newRow.regular_finish === '' ||
      newRow.overtime_start === '' ||
      newRow.late_overtime_start === ''
    ) {
      window.alert('未入力項目があるため登録できません。');
      return updatedRow;
    }

    try {
      if ((newRow as any).isNew || isNaN(newRow.id as any)) {
        await axios.post(`${BASE_ADDR}/m_shifts/insert.php`, {
          regular_start: newRow.regular_start,
          regular_finish: newRow.regular_finish,
          overtime_start: newRow.overtime_start,
          late_overtime_start: newRow.late_overtime_start,
        });
      } else {
        await axios.post(`${BASE_ADDR}/m_shifts/update.php`, {
          id: newRow.id,
          regular_start: newRow.regular_start,
          regular_finish: newRow.regular_finish,
          overtime_start: newRow.overtime_start,
          late_overtime_start: newRow.late_overtime_start,
        });
      }

      // 反映後に再取得
      const response = await axios.get<GridRowsProp>(`${BASE_ADDR}/m_shifts/select.php`, {
        headers: { 'Accept': 'application/json' },
      });
      setRows(response.data);
    } catch {
      alert('登録/更新APIでエラーが発生しました。バックエンドのログを確認してください。');
    }

    return updatedRow;
  };

  const handleRowModesModelChange = (newRowModesModel: GridRowModesModel) => {
    setRowModesModel(newRowModesModel);
  };

  // DataGridの列項目
  const columns: GridColDef[] = [
    { field: 'regular_start', headerName: '定時（始業）', width: 160, editable: true, renderEditCell: (params) => <TimePickerCell {...params} /> },
    { field: 'regular_finish', headerName: '定時（終業）', width: 160, editable: true, renderEditCell: (params) => <TimePickerCell {...params} /> },
    { field: 'overtime_start', headerName: '残業開始', width: 160, editable: true, renderEditCell: (params) => <TimePickerCell {...params} /> },
    { field: 'late_overtime_start', headerName: '深夜残業開始', width: 160, editable: true, renderEditCell: (params) => <TimePickerCell {...params} /> },
    {
      field: 'actions',
      headerName: '編集',
      type: 'actions',
      width: 100,
      cellClassName: 'actions',
      getActions: ({ id }) => {
        const isInEditMode = rowModesModel[id]?.mode === GridRowModes.Edit;

        if (isInEditMode) {
          return [
            <GridActionsCellItem
              key="save"
              icon={<SaveIcon />}
              label="Save"
              sx={{ color: 'primary.main' }}
              onClick={handleSaveClick(id)}
            />,
            <GridActionsCellItem
              key="cancel"
              icon={<CancelIcon />}
              label="Cancel"
              className="textPrimary"
              onClick={handleCancelClick(id)}
              color="inherit"
            />,
          ];
        }

        return [
          <GridActionsCellItem
            key="edit"
            icon={<EditIcon />}
            label="Edit"
            className="textPrimary"
            onClick={handleEditClick(id)}
            color="inherit"
          />,
          <GridActionsCellItem
            key="delete"
            icon={<DeleteIcon />}
            label="Delete"
            onClick={handleDeleteClick(id)}
            color="inherit"
          />,
        ];
      },
    },
  ];

  return (
    <Paper id="master-paper">
      <DataGrid
        rows={rows}
        columns={columns}
        initialState={{ pagination: { paginationModel } }}
        pageSizeOptions={[10, 15, 20]}
        editMode="row"
        rowModesModel={rowModesModel}
        onRowModesModelChange={handleRowModesModelChange}
        onRowEditStop={handleRowEditStop}
        processRowUpdate={processRowUpdate}
        slots={{ toolbar: EditToolbar }}
        slotProps={{ toolbar: { setRows, setRowModesModel, baseAddr: BASE_ADDR } }}
      />
    </Paper>
  );
};

export default ShiftPage;
